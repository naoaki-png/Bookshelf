<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookUser;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * マイ読書レポート(/reports)の集計が正しいことを確認する。
 *
 * このコントローラーは DB を更新しない。ログイン中のユーザーのレビューを
 * 集めて $stats という配列を組み立て、ビューに渡すだけ。
 * そのため assertDatabaseHas ではなく、viewData('stats') の中身を見る。
 *
 * 集計は4ブロックある。
 *   summary             総レビュー数 / 読んだ冊数 / 平均評価
 *   rating_distribution ★1〜★5 それぞれの件数(5要素固定)
 *   top_rated_books     4星以上を書籍単位にまとめて上位5冊
 *   genre_ratings       レビューをジャンル単位にまとめて上位5ジャンル
 *
 * 構造上の注意が2つある。
 *
 * 1. 同じ本に複数のレビューが付く。
 *    users --- book_users --- reviews で、book_users 1行に reviews が何行でもぶら下がる。
 *    そのため top_rated_books は書籍単位にまとめないと同じ本が並ぶ。
 *    重複したときの rating は「最高評価」を採る仕様。
 *
 * 2. 1件のレビューが複数のジャンルに数えられる。
 *    books --- book_genres --- genres の多対多なので、2ジャンル持つ本の
 *    レビュー1件は genre_ratings では2回数えられる。合計が総レビュー数と
 *    一致しないのは正常。
 */
class ReportsControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 指定ユーザーの、指定書籍に対するレビューを1件作る。
     *
     * book_users は「この人がこの本を読んだ」の1行なので、
     * 同じ組み合わせで2回目を作らないよう firstOrCreate で使い回す。
     * ここを create にすると同じ本が2冊読んだ扱いになり、books_read がずれる。
     */
    private function reviewFor(User $user, Book $book, int $rating): Review
    {
        $bookUser = BookUser::firstOrCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);

        return Review::factory()->create([
            'book_user_id' => $bookUser->id,
            'rating' => $rating,
        ]);
    }

    /**
     * 前提: 未ログイン
     * 操作: GET /reports
     * 期待: /login にリダイレクト
     *
     * ReportsController@index は先頭で Auth::user() を呼び、その戻り値に
     * ->reviews() を繋いでいる。auth ミドルウェアが外れると $user が null になり、
     * 集計ではなく null 参照で 500 になる。リダイレクトの確認はその防波堤。
     */
    public function test_未ログインでレポート画面はログイン画面にリダイレクトされる(): void
    {
        $this->get('/reports')->assertRedirect('/login');
    }

    /**
     * 前提: ログイン済み、レビュー0件
     * 操作: GET /reports
     * 期待: 200 で表示され、4ブロックすべてが「0件の形」で返る
     *
     * 平均評価は avg() が null を返すため ?? 0 で受けている。
     * ここが素通りすると number_format(null) になり、PHP 8.1 以降は
     * deprecation を出しながら 0.0 を表示する。0 が入っていることを明示的に確認する。
     */
    public function test_レビューが0件でもレポート画面が表示される(): void
    {
        $user = User::factory()->create();

        $stats = $this->actingAs($user)->get('/reports')->assertOk()->viewData('stats');

        $this->assertSame(0, $stats['summary']['total_reviews']);
        $this->assertSame(0, $stats['summary']['books_read']);
        $this->assertSame(0, $stats['summary']['average_rating']);
        $this->assertSame([0, 0, 0, 0, 0], $stats['rating_distribution']->values()->all());
        $this->assertCount(0, $stats['top_rated_books']);
        $this->assertCount(0, $stats['genre_ratings']);
    }

    /**
     * 前提: 2冊の本に対して合計3件のレビュー(1冊目に2件、2冊目に1件)
     * 操作: GET /reports
     * 期待: 総レビュー数3 / 読んだ冊数2 / 平均4.0
     *
     * total_reviews と books_read が別々の数になる組み合わせを選んでいる。
     * 同じ数だと、books_read が unique() を忘れていても気づけない。
     */
    public function test_サマリーはレビュー数と冊数と平均評価を返す(): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->count(2)->create();

        $this->reviewFor($user, $books[0], 5);
        $this->reviewFor($user, $books[0], 3);
        $this->reviewFor($user, $books[1], 4);

        $stats = $this->actingAs($user)->get('/reports')->viewData('stats');

        $this->assertSame(3, $stats['summary']['total_reviews']);
        $this->assertSame(2, $stats['summary']['books_read']);
        $this->assertEqualsWithDelta(4.0, $stats['summary']['average_rating'], 0.001);
    }

    /**
     * 前提: ★5が2件、★3が1件、★1が1件
     * 操作: GET /reports
     * 期待: [1,0,1,0,2] が★1→★5の順で返る
     *
     * 0件の星をスキップしてしまうと要素が5個未満になり、ビューのグラフが
     * 星の並びとずれる。件数0の★2と★4を必ず含めているのはそのため。
     */
    public function test_評価分布は星1から星5まで5要素で返る(): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->count(4)->create();

        $this->reviewFor($user, $books[0], 5);
        $this->reviewFor($user, $books[1], 5);
        $this->reviewFor($user, $books[2], 3);
        $this->reviewFor($user, $books[3], 1);

        $stats = $this->actingAs($user)->get('/reports')->viewData('stats');

        $this->assertSame([1, 0, 1, 0, 2], $stats['rating_distribution']->values()->all());
    }

    /**
     * 前提: 同じ本に★5と★4の2件、別の本に★4が1件、さらに別の本に★3が1件
     * 操作: GET /reports
     * 期待: 2冊だけ返り、同じ本は1行にまとまる / まとめた rating は最高の5 / ★3の本は出ない
     *
     * 「同じ本に複数レビュー」と「4星未満は除外」を1つのテストで見ている。
     * この2つは groupBy と where の担当が分かれていて、片方が壊れると
     * 件数と rating のどちらかがずれるので、失敗したとき原因を切り分けられる。
     */
    public function test_高評価書籍は書籍単位にまとまり重複時は最高評価を採る(): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->count(3)->create();

        $this->reviewFor($user, $books[0], 5);
        $this->reviewFor($user, $books[0], 4);
        $this->reviewFor($user, $books[1], 4);
        $this->reviewFor($user, $books[2], 3);

        $stats = $this->actingAs($user)->get('/reports')->viewData('stats');
        $top = $stats['top_rated_books'];

        $this->assertCount(2, $top);
        $this->assertSame([0, 1], $top->keys()->all());

        $first = $top->firstWhere('id', $books[0]->id);
        $this->assertSame(5, $first['rating']);
        $this->assertSame($books[0]->title, $first['title']);
        $this->assertSame($books[0]->author, $first['author']);

        $this->assertNull($top->firstWhere('id', $books[2]->id));
    }

    /**
     * 前提: ★5が1冊、★4が5冊
     * 操作: GET /reports
     * 期待: 5件で打ち切られ、先頭は★5の本
     *
     * take(5) と sortByDesc がどちらも効いていないと通らない。
     * 並べ替えを忘れると★5が6番目に来て切り捨てられる。
     */
    public function test_高評価書籍は評価の高い順に5件までで打ち切られる(): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->count(6)->create();

        $this->reviewFor($user, $books[0], 5);
        foreach (range(1, 5) as $i) {
            $this->reviewFor($user, $books[$i], 4);
        }

        $top = $this->actingAs($user)->get('/reports')->viewData('stats')['top_rated_books'];

        $this->assertCount(5, $top);
        $this->assertSame($books[0]->id, $top[0]['id']);
        $this->assertSame(5, $top[0]['rating']);
    }

    /**
     * 前提: 2ジャンル(小説・技術書)を持つ本に★4を1件、技術書だけの本に★2を1件
     * 操作: GET /reports
     * 期待: 小説は1件・平均4.0 / 技術書は2件・平均3.0
     *
     * レビューは合計2件しかないのに、技術書の count が2になるのが正しい。
     * ここが1件になっていたら、1レビューを1ジャンルにしか数えていない
     * (groupBy から始めてしまった)ということ。flatMap で
     * 「レビュー×ジャンル」の組に展開している意味がこのテストに出る。
     */
    public function test_1件のレビューは複数ジャンルそれぞれに数えられる(): void
    {
        $user = User::factory()->create();
        $novel = Genre::factory()->create(['name' => '小説']);
        $tech = Genre::factory()->create(['name' => '技術書']);

        $both = Book::factory()->create();
        $both->genres()->attach([$novel->id, $tech->id]);
        $techOnly = Book::factory()->create();
        $techOnly->genres()->attach($tech->id);

        $this->reviewFor($user, $both, 4);
        $this->reviewFor($user, $techOnly, 2);

        $genres = $this->actingAs($user)->get('/reports')->viewData('stats')['genre_ratings'];

        $novelRow = $genres->firstWhere('id', $novel->id);
        $this->assertSame(1, $novelRow['count']);
        $this->assertEqualsWithDelta(4.0, $novelRow['average_rating'], 0.001);
        $this->assertSame('小説', $novelRow['name']);

        $techRow = $genres->firstWhere('id', $tech->id);
        $this->assertSame(2, $techRow['count']);
        $this->assertEqualsWithDelta(3.0, $techRow['average_rating'], 0.001);
    }

    /**
     * 前提: ジャンルが1つも紐づいていない本にレビュー1件
     * 操作: GET /reports
     * 期待: genre_ratings は空。ただし summary には数えられている
     *
     * 除外は filter で書いているのではなく、ジャンルが空だと内側の map が
     * 何も返さず flatMap の結果に現れない、という構造で成立している。
     * 「除外の実装が無い」ように見える箇所なので、意図どおりかを固定しておく。
     */
    public function test_ジャンル未設定の書籍はジャンル別集計に出ない(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->reviewFor($user, $book, 5);

        $stats = $this->actingAs($user)->get('/reports')->viewData('stats');

        $this->assertCount(0, $stats['genre_ratings']);
        $this->assertSame(1, $stats['summary']['total_reviews']);
    }

    /**
     * 前提: 6ジャンル。平均が 5,4,4,3,3,2 になるよう1冊ずつ割り当てる
     * 操作: GET /reports
     * 期待: 5件で打ち切られ、先頭が平均5.0 / 最下位の平均2.0のジャンルは出ない
     */
    public function test_ジャンル別集計は平均の高い順に5件までで打ち切られる(): void
    {
        $user = User::factory()->create();
        $ratings = [5, 4, 4, 3, 3, 2];
        $genreIds = [];

        foreach ($ratings as $i => $rating) {
            $genre = Genre::factory()->create(['name' => 'ジャンル' . $i]);
            $book = Book::factory()->create();
            $book->genres()->attach($genre->id);
            $this->reviewFor($user, $book, $rating);
            $genreIds[] = $genre->id;
        }

        $genres = $this->actingAs($user)->get('/reports')->viewData('stats')['genre_ratings'];

        $this->assertCount(5, $genres);
        $this->assertSame([0, 1, 2, 3, 4], $genres->keys()->all());
        $this->assertEqualsWithDelta(5.0, $genres[0]['average_rating'], 0.001);
        $this->assertNull($genres->firstWhere('id', $genreIds[5]));
    }

    /**
     * 前提: 自分のレビュー1件(★1)と、他ユーザーの同じ本へのレビュー1件(★5)
     * 操作: GET /reports
     * 期待: 4ブロックすべてが自分の★1だけを見ている
     *
     * 集計の出発点は $user->reviews() なので本来は混ざらないが、
     * 途中でジャンルや書籍のリレーションを辿り直すと(例: $genre->reviews)
     * 他ユーザーのレビューが入り込む。実装中に実際に踏みかけた道なので、
     * ここを固定しておく。同じ本・同じジャンルにしているのは、
     * 混ざったときに必ず数字が変わるようにするため。
     */
    public function test_他ユーザーのレビューは自分のレポートに混ざらない(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $genre = Genre::factory()->create(['name' => '小説']);
        $book = Book::factory()->create();
        $book->genres()->attach($genre->id);

        $this->reviewFor($user, $book, 1);
        $this->reviewFor($other, $book, 5);

        $stats = $this->actingAs($user)->get('/reports')->viewData('stats');

        $this->assertSame(1, $stats['summary']['total_reviews']);
        $this->assertEqualsWithDelta(1.0, $stats['summary']['average_rating'], 0.001);
        $this->assertSame([1, 0, 0, 0, 0], $stats['rating_distribution']->values()->all());
        $this->assertCount(0, $stats['top_rated_books']);

        $novelRow = $stats['genre_ratings']->firstWhere('id', $genre->id);
        $this->assertSame(1, $novelRow['count']);
        $this->assertEqualsWithDelta(1.0, $novelRow['average_rating'], 0.001);
    }
}
