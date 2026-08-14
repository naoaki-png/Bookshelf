<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookUser;
use App\Models\Genre;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 書籍一覧(/books)の検索・絞り込み・並び替えのテスト。
 *
 * 【全体の設計方針】
 *
 * 1. 検証は assertViewHas() のクロージャではなく viewData() で取り出して行う。
 *    既存の BooksControllerTest はクロージャが true/false を返す形だが、
 *    失敗したとき「false だった」としか出ず、実際に何が返ったかが分からない。
 *    viewData('books') で受けてから assertSame() すれば、期待と実際の
 *    両方が失敗メッセージに出る。並び順のテストは「どう違ったか」が
 *    分からないと原因に辿り着けないので、この形を採っている。
 *
 * 2. 並び順の比較は ID の配列で行う。
 *    タイトルや日付で比べると、同値のときにどちらが先か決まらない。
 *    ID なら一意なので、期待値が1通りに定まる。
 *
 * 3. データプロバイダは DB 構築より前に走るためモデルを作れない。
 *    そこで並び順のテストでは、期待値を 'A' 'B' 'C' という「ラベル」で渡し、
 *    テスト側で実際の ID に差し替えている。(BookApiTest で URI を
 *    テンプレート文字列で渡したのと同じ手口)
 *
 * 4. 不正な入力は 422 ではなく 302 を期待する。
 *    Web ルートには Accept: application/json が付かないため、
 *    FormRequest の検証失敗は JSON の 422 ではなく
 *    「リダイレクト + セッションにエラー」になる。API 側(BookApiTest)
 *    とはここが違う。同じ FormRequest でも、返り方は経路で変わる。
 *
 * 5. /books は未ログインでも開ける(routes/web.php で auth が付いていない)。
 *    そのため、このファイルではログインしない。
 */
class BookSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 書籍にレビューを付ける。
     *
     * reviews は books に直接ぶら下がっておらず、book_users を経由する
     * (Book::reviews() が hasManyThrough)。テストのたびに
     * BookUser → Review の2段を書くと本題が埋もれるのでまとめた。
     */
    private function giveRatings(Book $book, int ...$ratings): void
    {
        foreach ($ratings as $rating) {
            $bookUser = BookUser::factory()->create(['book_id' => $book->id]);
            Review::factory()->create(['book_user_id' => $bookUser->id, 'rating' => $rating]);
        }
    }

    /**
     * ビューに渡った $books から ID を順番どおりに取り出す。
     */
    private function idsOf($response): array
    {
        return $response->viewData('books')->pluck('id')->all();
    }

    /**
     * 検証① キーワードはタイトルと著者の両方を見る。
     *
     * 要件が「タイトル・著者で検索」なので、片方だけ動いていても
     * 画面上はそれらしく見えてしまう。ヒットする理由が
     * タイトルの本と著者の本を1冊ずつ用意して、両方が返ることを確かめる。
     */
    public function test_キーワードはタイトルと著者の両方にヒットする(): void
    {
        $タイトルが一致 = Book::factory()->create(['title' => 'PHPの教科書', 'author' => '山田太郎']);
        $著者が一致 = Book::factory()->create(['title' => 'Ruby入門', 'author' => 'PHP太郎']);
        Book::factory()->create(['title' => 'Java入門', 'author' => '鈴木花子']);

        $response = $this->get('/books?keyword=PHP');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            [$タイトルが一致->id, $著者が一致->id],
            $this->idsOf($response),
        );
    }

    /**
     * 検証② キーワードとジャンルを同時に指定したら、両方で絞られる。
     *
     * 【このテストが一番大事】
     * キーワードの OR をカッコで囲まないと、SQL はこうなる:
     *
     *   where title like ? or author like ? and exists (ジャンル条件)
     *
     * AND は OR より強く結合するので、実際には
     * 「タイトル一致」または「著者一致かつジャンル一致」と解釈される。
     * つまり "キーワードは合っているがジャンルが違う本" が混ざる。
     *
     * ジャンル違いの本($ジャンルが違う)を1冊置いてあるのはそのため。
     * カッコが外れた瞬間にこの本が結果に現れ、このテストが落ちる。
     * 画面を見るだけでは「多めに出ている」としか分からない類のバグなので、
     * 回帰テストとして明示的に固定しておく。
     */
    public function test_キーワードとジャンルは両方が同時に効く(): void
    {
        $対象ジャンル = Genre::factory()->create(['name' => '技術書']);
        $別ジャンル = Genre::factory()->create(['name' => '小説']);

        $タイトル一致 = Book::factory()->create(['title' => 'PHP入門', 'author' => '山田']);
        $タイトル一致->genres()->attach($対象ジャンル->id);

        $著者一致 = Book::factory()->create(['title' => 'Web開発', 'author' => 'PHP次郎']);
        $著者一致->genres()->attach($対象ジャンル->id);

        // キーワードは合うがジャンルが違う。カッコが外れると、この本が混ざる。
        $ジャンルが違う = Book::factory()->create(['title' => 'PHP実践', 'author' => '佐藤']);
        $ジャンルが違う->genres()->attach($別ジャンル->id);

        // ジャンルは合うがキーワードが合わない。
        $キーワードが違う = Book::factory()->create(['title' => 'Ruby実践', 'author' => '高橋']);
        $キーワードが違う->genres()->attach($対象ジャンル->id);

        $response = $this->get("/books?keyword=PHP&genre={$対象ジャンル->id}");

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            [$タイトル一致->id, $著者一致->id],
            $this->idsOf($response),
        );
    }

    /**
     * 検証③ 並び順4種。
     *
     * 【データの作り方】
     * 4つの並び順が「どれも違う順番になる」ようにわざと値をずらしてある。
     * 例えば oldest と title が同じ順番になるデータだと、
     * この2つを取り違えて実装してもテストが通ってしまう。
     *
     *        作成日   タイトル  平均評価
     *   A    -1日     Beta      5
     *   B    -2日     Alpha     なし
     *   C    -3日     Gamma     3
     *
     *   newest → A B C   /   oldest → C B A
     *   title  → B A C   /   rating → A C B
     *
     * 4つとも別の並びになるので、どれかを取り違えれば必ず落ちる。
     *
     * @dataProvider 並び順と期待する順番
     */
    public function test_並び順が指定どおりになる(?string $sort, array $期待するラベル): void
    {
        $books = [
            'A' => Book::factory()->create(['title' => 'Beta', 'created_at' => now()->subDays(1)]),
            'B' => Book::factory()->create(['title' => 'Alpha', 'created_at' => now()->subDays(2)]),
            'C' => Book::factory()->create(['title' => 'Gamma', 'created_at' => now()->subDays(3)]),
        ];

        $this->giveRatings($books['A'], 5, 5);
        $this->giveRatings($books['C'], 3, 3);
        // B にはレビューを付けない(平均は null になる)

        $url = $sort === null ? '/books' : "/books?sort={$sort}";
        $response = $this->get($url);

        $期待するID = array_map(fn ($ラベル) => $books[$ラベル]->id, $期待するラベル);

        $response->assertOk();
        $this->assertSame($期待するID, $this->idsOf($response));
    }

    /**
     * sort の値と、そのとき期待される並び。
     *
     * null は「sort を送らずに /books を開いたとき」。
     * コントローラが既定値として newest を入れているので、newest と同じ並びになる。
     * 既定値は要件に明記されていないぶん、後で変えられやすい。
     * ここで固定しておくと、変わったときに気づける。
     */
    public static function 並び順と期待する順番(): array
    {
        return [
            '新しい順' => ['newest', ['A', 'B', 'C']],
            '古い順' => ['oldest', ['C', 'B', 'A']],
            'タイトル順' => ['title', ['B', 'A', 'C']],
            '評価順' => ['rating', ['A', 'C', 'B']],
            '未指定は新しい順' => [null, ['A', 'B', 'C']],
        ];
    }

    /**
     * 検証④ 評価順のとき、レビューが無い書籍は最後に来る。
     *
     * ③の「評価順」でも同じことを確かめているが、
     * こちらは要件に「レビューがない書籍は最後に表示」と明記されている項目なので、
     * 要件と1対1で対応するテストとして独立させている。
     * ③が落ちたのか④が落ちたのかで、原因が並び全体か null の扱いかを切り分けられる。
     *
     * なお実装は orderByDesc('reviews_avg_rating') だけで、null を最後に回す
     * 細工はしていない。SQL では NULL が最小として扱われ、降順では自然に
     * 最後へ落ちるため。この前提が崩れた場合もここで落ちる。
     */
    public function test_評価順ではレビューが無い書籍が最後に来る(): void
    {
        $評価2 = Book::factory()->create();
        $レビュー無し = Book::factory()->create();
        $評価4 = Book::factory()->create();

        $this->giveRatings($評価2, 2);
        $this->giveRatings($評価4, 4);

        $response = $this->get('/books?sort=rating');

        $response->assertOk();
        $this->assertSame(
            [$評価4->id, $評価2->id, $レビュー無し->id],
            $this->idsOf($response),
        );

        $books = $response->viewData('books');
        $this->assertNull($books->last()->reviews_avg_rating);
    }

    /**
     * 検証⑤ 不正なクエリは BookIndexRequest で弾かれる。
     *
     * 弾き方を「既定値へのフォールバック」ではなく「検証エラー」にしたのは
     * 実装時の判断。ここを固定しておかないと、後から
     * 「不正な sort は無視して newest にする」に変えても気づけない。
     *
     * 返るのは 422 ではなく 302。Web ルートには Accept: application/json が
     * 無いため、ValidationException はリダイレクトに変換される。
     * どのキーでエラーになったかまで見ておかないと、
     * 「たまたま別の理由でリダイレクトした」場合を拾ってしまう。
     *
     * @dataProvider 一覧の不正なクエリ
     */
    public function test_不正なクエリはバリデーションで弾かれる(string $クエリ, string $エラーになるキー): void
    {
        Genre::factory()->create();
        Book::factory()->create();

        $response = $this->get("/books?{$クエリ}");

        $response->assertStatus(302);
        $response->assertSessionHasErrors($エラーになるキー);
    }

    public static function 一覧の不正なクエリ(): array
    {
        return [
            '一覧に無い並び順' => ['sort=abc', 'sort'],
            '存在しないジャンル' => ['genre=999999', 'genre'],
            'キーワードが256文字' => ['keyword=' . str_repeat('a', 256), 'keyword'],
        ];
    }

    /**
     * 検証⑥ 2ページ目に進んでも検索条件が保たれる。
     *
     * ここで確かめているのは2つ。
     *
     *   1. ページ送りのリンクに検索条件が乗っているか(withQueryString)
     *      これが無いと、リンクは ?page=2 だけになり、
     *      2ページ目を押した瞬間に検索条件が消える。
     *   2. 実際に ?page=2 を開いたとき、絞り込みが効いたままか
     *
     * 1 だけだとリンクの見た目しか見ていないし、
     * 2 だけだと「手で URL を打てば動く」ことしか言えない。
     * 画面の操作としてつながるのは両方が揃ったときなので、1つのテストにしている。
     *
     * 15 + 5 件にしたのは、10件で切ったときに
     * 2ページ目が「5件ちょうど」になり、絞り込み漏れ(20件)と
     * 区別が付くようにするため。
     */
    public function test_2ページ目でも検索条件が保たれる(): void
    {
        // 著者もこちらで固定する。ファクトリ任せだと、生成された著者名に
        // たまたまキーワードが含まれて件数がずれる可能性を残してしまう。
        Book::factory(15)->create(['title' => 'PHPの本', 'author' => '山田太郎']);
        Book::factory(5)->create(['title' => 'Rubyの本', 'author' => '鈴木花子']);

        $一覧 = $this->get('/books?keyword=PHP');
        $一覧->assertOk();

        $paginator = $一覧->viewData('books');
        $this->assertCount(10, $paginator);
        $this->assertStringContainsString('keyword=PHP', $paginator->nextPageUrl());

        $二ページ目 = $this->get('/books?keyword=PHP&page=2');
        $二ページ目->assertOk();

        $二ページ目の本 = $二ページ目->viewData('books');
        $this->assertCount(5, $二ページ目の本);
        foreach ($二ページ目の本 as $book) {
            $this->assertStringContainsString('PHP', $book->title);
        }
    }
}
