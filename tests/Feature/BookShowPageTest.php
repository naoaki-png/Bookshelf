<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookUser;
use App\Models\Review;
use App\Models\ReviewLike;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 書籍詳細ページ(GET /books/{book})が最後まで描画できることを確認する。
 *
 * 【なぜこのファイルが必要だったか】
 *
 * 既存のテストは books.show を18箇所で参照しているが、
 * すべて assertRedirect(route('books.show', $book)) の形だった。
 * route() は URL の文字列を組み立てるだけで、ページは描画されない。
 * その結果、show.blade.php の中身が一度も実行されていなかった。
 *
 * 実際にそこで事故が起きた。Review モデルの likedByUsers() に
 * 戻り値の型を書いたとき use 文を足し忘れており、
 * 呼ぶと TypeError になる状態でテストが全部通っていた。
 * このページはレビューが1件でもあれば必ずその行を通る。
 *
 * 【何を見ているか】
 *
 * 表示内容の細かい検証ではなく、まず「200 で返ること」を見ている。
 * ブレードの中で例外が起きれば 500 になるので、それだけで
 * 未実行のまま壊れている行を検出できる。
 *
 * いいねボタンは @auth と @guest、いいね済みと未いいねで
 * 3つの分岐に割れており、3つとも likedByUsers を呼ぶ。
 * どの経路でも落ちないことを確かめるため、分岐ごとにテストを分けている。
 */
class BookShowPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 指定した書籍にレビューを1件付ける。
     *
     * reviews は book_users を経由して books にぶら下がっているため、
     * 中間の行を先に作る必要がある。
     */
    private function reviewOn(Book $book, User $author): Review
    {
        $bookUser = BookUser::factory()->create([
            'user_id' => $author->id,
            'book_id' => $book->id,
        ]);

        return Review::factory()->create([
            'book_user_id' => $bookUser->id,
            'rating' => 5,
            'comment' => 'テスト用のレビュー本文',
        ]);
    }

    /**
     * 未ログインでも、レビューの付いた書籍詳細ページを開ける。
     *
     * 前提: 書籍1冊、レビュー1件。閲覧者はログインしていない
     * 操作: GET /books/{book}
     * 期待: 200 が返り、レビュー本文が表示される
     *
     * books.show に auth ミドルウェアは付いていないため、
     * 未ログインの経路が本番で最も踏まれやすい。
     */
    public function test_未ログインでもレビュー付きの書籍詳細を表示できる(): void
    {
        $author = User::factory()->create();
        $book = Book::factory()->create(['title' => '詳細を開く本']);
        $this->reviewOn($book, $author);

        $this->get('/books/' . $book->id)
            ->assertOk()
            ->assertSee('詳細を開く本')
            ->assertSee('テスト用のレビュー本文');
    }

    /**
     * ログイン済みで、まだいいねしていないレビューを表示できる。
     *
     * 前提: 書籍1冊、他人のレビュー1件。閲覧者はいいねしていない
     * 操作: GET /books/{book}
     * 期待: 200 が返り、「いいね (0)」が表示される
     */
    public function test_ログイン済みで未いいねのレビューを表示できる(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $book = Book::factory()->create();
        $this->reviewOn($book, $author);

        $this->actingAs($viewer)
            ->get('/books/' . $book->id)
            ->assertOk()
            ->assertSee('いいね (0)');
    }

    /**
     * ログイン済みで、いいね済みのレビューを表示できる。
     *
     * 前提: 書籍1冊、他人のレビュー1件。閲覧者がそれをいいね済み
     * 操作: GET /books/{book}
     * 期待: 200 が返り、「いいね済み (1)」が表示される
     *
     * 件数まで見ているのは、likedByUsers が呼ばれた結果が
     * 画面に届いていることを確かめるため。200 だけでは
     * 関連が空のまま描画されても気づけない。
     */
    public function test_ログイン済みでいいね済みのレビューを表示できる(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $book = Book::factory()->create();
        $review = $this->reviewOn($book, $author);

        ReviewLike::factory()->create([
            'user_id' => $viewer->id,
            'review_id' => $review->id,
        ]);

        $this->actingAs($viewer)
            ->get('/books/' . $book->id)
            ->assertOk()
            ->assertSee('いいね済み (1)');
    }

    /**
     * レビューが1件も無い書籍でも詳細ページを開ける。
     *
     * 前提: 書籍1冊、レビュー0件
     * 操作: GET /books/{book}
     * 期待: 200 が返る
     *
     * レビューが無いとループの中に入らないため、
     * いいね関連の行は一度も実行されない。上の3本とは別の経路になる。
     */
    public function test_レビューが無い書籍でも詳細ページを表示できる(): void
    {
        $book = Book::factory()->create(['title' => 'レビューがまだ無い本']);

        $this->get('/books/' . $book->id)
            ->assertOk()
            ->assertSee('レビューがまだ無い本');
    }
}
