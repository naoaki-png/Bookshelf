<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookUser;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 評価ランキング(/ranking)の並び順と件数を確認する。
 *
 * RankingController は1行しかない。
 *
 *   Book::withAvg('reviews', 'rating')->orderByDesc('reviews_avg_rating')->take(10)->get()
 *
 * 短いが、確認すべき点は3つある。
 *
 * 1. withAvg が付ける列名は reviews_avg_rating。
 *    リレーション名 + '_avg_' + 列名という規約で決まる。ビューは
 *    $book->reviews_avg_rating を直接読んでいるので、リレーション名を
 *    reviews から変えるとビューが静かに null を表示する(エラーは出ない)。
 *
 * 2. 平均は全ユーザーのレビューを混ぜて出す。
 *    ランキングは「みんなの評価」なので、/reports(自分だけ)とは集計範囲が逆。
 *    ここを取り違えると、ログインしている人によってランキングが変わる。
 *
 * 3. reviews は hasManyThrough(Review, BookUser)。
 *    books --- book_users --- reviews と2段になっているため、
 *    レビューを作るには先に book_users の行が要る。
 *    同じ人が同じ本に2件レビューを書く場合は book_users を使い回す。
 */
class RankingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 指定ユーザーの、指定書籍に対するレビューを1件作る。
     *
     * book_users は「この人がこの本を読んだ」の1行なので firstOrCreate で使い回す。
     * ReportsControllerTest と同じ形にそろえてある。
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
     * 前提: レビュー付きの書籍1冊
     * 操作: 未ログインで GET /ranking
     * 期待: 200
     *
     * /ranking は auth グループの外に置かれている数少ないルートのひとつ。
     * 「ログインしなくても見られる」が仕様なので、うっかり auth の中に
     * 移されたときに気づけるようにしておく。
     */
    public function test_未ログインでもランキングを閲覧できる(): void
    {
        $book = Book::factory()->create();
        $this->reviewFor(User::factory()->create(), $book, 5);

        $this->get('/ranking')->assertOk();
    }

    /**
     * 前提: 平均が 2.0 / 5.0 / 3.5 になる3冊
     * 操作: GET /ranking
     * 期待: 5.0 → 3.5 → 2.0 の順に並び、平均値も一致する
     *
     * 3.5 の本だけレビューを2件にしてある。平均が正しく計算されていないと
     * (例: max や最初の1件を採っていると)この本の位置がずれる。
     * 作成順と評価順もわざとずらしている。
     */
    public function test_ランキングは平均評価の高い順に並ぶ(): void
    {
        $user = User::factory()->create();

        $low = Book::factory()->create(['title' => 'LOW']);
        $high = Book::factory()->create(['title' => 'HIGH']);
        $mid = Book::factory()->create(['title' => 'MID']);

        $this->reviewFor($user, $low, 2);
        $this->reviewFor($user, $high, 5);
        $this->reviewFor($user, $mid, 3);
        $this->reviewFor($user, $mid, 4);

        $books = $this->get('/ranking')->assertOk()->viewData('rankedBooks');

        $this->assertSame(['HIGH', 'MID', 'LOW'], $books->pluck('title')->all());
        $this->assertEqualsWithDelta(5.0, $books[0]->reviews_avg_rating, 0.001);
        $this->assertEqualsWithDelta(3.5, $books[1]->reviews_avg_rating, 0.001);
        $this->assertEqualsWithDelta(2.0, $books[2]->reviews_avg_rating, 0.001);
    }

    /**
     * 前提: レビュー付きの書籍が12冊
     * 操作: GET /ranking
     * 期待: 10件で打ち切られ、評価が下位の2冊は出ない
     *
     * take(10) と orderByDesc の両方が効いていないと通らない。
     * 並べ替えを忘れると、上位10件ではなく「id が小さい10件」が返る。
     * ここでは id 順と評価順が逆になるよう、先に作った本ほど評価を低くしている。
     */
    public function test_ランキングは上位10件で打ち切られる(): void
    {
        $user = User::factory()->create();
        $titles = [];

        // 1冊目が最低評価、12冊目が最高評価になるように作る
        foreach (range(1, 12) as $i) {
            $book = Book::factory()->create(['title' => 'BOOK' . $i]);
            $this->reviewFor($user, $book, 1);
            // rating は1〜5しか取れないので、平均で差を付けるため
            // 上位の本ほど高評価のレビューを追加する
            if ($i > 2) {
                $this->reviewFor(User::factory()->create(), $book, 5);
            }
            $titles[$i] = $book->title;
        }

        $books = $this->get('/ranking')->assertOk()->viewData('rankedBooks');

        $this->assertCount(10, $books);
        // 評価1だけの2冊(BOOK1 / BOOK2)は圏外
        $this->assertNull($books->firstWhere('title', $titles[1]));
        $this->assertNull($books->firstWhere('title', $titles[2]));
    }

    /**
     * 前提: 書籍が1冊も無い
     * 操作: GET /ranking
     * 期待: 200 で「まだレビューが投稿された書籍がありません。」が出る
     */
    public function test_書籍が0件ならランキングは空で表示される(): void
    {
        $this->get('/ranking')
            ->assertOk()
            ->assertSee('まだレビューが投稿された書籍がありません。');
    }

    /**
     * 前提: 同じ本に、別々のユーザーが ★5 と ★1 を付けている
     * 操作: 片方のユーザーでログインして GET /ranking
     * 期待: 平均は 3.0。ログインしている人によって変わらない
     *
     * /reports は Auth::user()->reviews() から始まるので自分の分だけを見るが、
     * /ranking は Book から始まるので全員分を見る。この2つは集計範囲が逆で、
     * 実装中に取り違えやすい。ログインした状態で確認しているのは、
     * うっかり Auth::user() を挟んだときに数字が 5.0 に変わるようにするため。
     */
    public function test_ランキングの平均は全ユーザーのレビューを含む(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::factory()->create();

        $this->reviewFor($me, $book, 5);
        $this->reviewFor($other, $book, 1);

        $books = $this->actingAs($me)->get('/ranking')->assertOk()->viewData('rankedBooks');

        $this->assertCount(1, $books);
        $this->assertEqualsWithDelta(3.0, $books[0]->reviews_avg_rating, 0.001);
    }

    /**
     * 前提: 平均 4.0 の書籍1冊
     * 操作: GET /ranking
     * 期待: タイトル・著者・平均値が画面に出る
     *
     * ビューは reviews_avg_rating を number_format() と round() に渡している。
     * viewData を見るだけのテストでは、この2つが一度も実行されない。
     * 描画まで通して、withAvg の列名がビューの読み先と一致していることを確認する。
     */
    public function test_ランキング画面に書籍と平均評価が表示される(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create(['title' => 'リーダブルコード', 'author' => 'Dustin Boswell']);

        $this->reviewFor($user, $book, 4);

        $this->get('/ranking')
            ->assertOk()
            ->assertSee('リーダブルコード')
            ->assertSee('Dustin Boswell')
            ->assertSee('4.00');
    }

    /**
     * 前提: レビュー付き1冊 + レビュー0件が2冊
     * 操作: GET /ranking
     * 期待: レビュー付きの1冊だけが並ぶ
     *
     * withAvg は LEFT JOIN なので、レビューが無い本も
     * reviews_avg_rating = null で付いてくる。orderByDesc で null は後ろに
     * 回るが、対象が10冊に満たないときは画面に残り、round(null) で ★0、
     * number_format(null, 2) で 0.00 と表示されてしまう。
     * has('reviews') で母集団から外していることを固定する。
     */
    public function test_レビューが0件の書籍はランキングに出ない(): void
    {
        $reviewed = Book::factory()->create(['title' => 'レビューあり']);
        Book::factory()->create(['title' => 'レビューなしA']);
        Book::factory()->create(['title' => 'レビューなしB']);

        $this->reviewFor(User::factory()->create(), $reviewed, 4);

        $response = $this->get('/ranking')->assertOk();

        $this->assertSame(['レビューあり'], $response->viewData('rankedBooks')->pluck('title')->all());
        $response->assertDontSee('レビューなしA');
        $response->assertDontSee('レビューなしB');
        $response->assertDontSee('0.00');
    }

    /**
     * 前提: 3人が別々にレビューを付けた書籍1冊
     * 操作: GET /ranking
     * 期待: reviews_count が 3、画面に「(3件のレビュー)」と出る
     *
     * ビューは $book->reviews_count を読んでいるが、コントローラが
     * withCount('reviews') を呼び忘れると null になり、エラーを出さずに
     * 「(件のレビュー)」と数字が欠けた状態で描画される。
     * viewData だけでなく描画まで通して、列名とビューの読み先を一致させておく。
     */
    public function test_ランキングにレビュー件数が表示される(): void
    {
        $book = Book::factory()->create(['title' => 'レビュー3件の本']);

        $this->reviewFor(User::factory()->create(), $book, 5);
        $this->reviewFor(User::factory()->create(), $book, 4);
        $this->reviewFor(User::factory()->create(), $book, 3);

        $response = $this->get('/ranking')->assertOk();

        $this->assertEquals(3, $response->viewData('rankedBooks')[0]->reviews_count);
        $response->assertSee('(3件のレビュー)');
    }
}
