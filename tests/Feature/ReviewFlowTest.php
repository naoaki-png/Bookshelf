<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * レビューの投稿・更新・削除・いいねが、ログイン済みユーザーの
 * 正しい操作で最後まで完走することを確認する(正常系)。
 *
 * reviews は投稿者と対象書籍を user_id / book_id として直接持つ。
 * そのため投稿の検証は reviews の1行を見れば足りる。
 */
class ReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ログイン済みユーザーが書籍にレビューを投稿できる。
     *
     * 前提: ユーザー1人、書籍1冊。レビューはまだ0行
     * 操作: 正しい rating と comment で POST /books/{book}/reviews
     * 期待: reviews に1行 /
     *       books.show へリダイレクト + 「レビューを投稿しました。」
     *
     * user_id と book_id まで見ている理由:
     * rating と comment だけを見ると、「誰の、どの本への」レビューかが
     * 入れ替わっていても通ってしまう。ReviewsController@store は
     * ログイン中のユーザーとルートの書籍をこの2列に入れているので、
     * そこが正しいことまで含めて1行で確かめる。
     */
    public function test_ログイン済みユーザーは書籍にレビューを投稿できる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->actingAs($user)
            ->post('/books/' . $book->id . '/reviews', [
                'rating' => 4,
                'comment' => '投稿したレビューのコメント',
            ])
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHas('success', 'レビューを投稿しました。');

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => 4,
            'comment' => '投稿したレビューのコメント',
        ]);
        $this->assertDatabaseCount('reviews', 1);
    }

    /**
     * 同じ人が同じ本に2回レビューすると、詳細ページに2件とも表示される。
     *
     * 前提: ユーザー1人、書籍1冊。まだレビューは無い
     * 操作: 同じユーザーで同じ本に、内容の違うレビューを2回 POST する
     * 期待: reviews が2行 / 書籍詳細ページに2件とも出る
     *
     * このテストが縛っているのは「reviews に unique(user_id, book_id) を
     * 張らない」という設計判断。要件シート シート11 DR04 はレビューに
     * ユニーク制約を求めていない —— 必要な箇所(DR01 の ISBN、DR02 の
     * ジャンル名、DR07 のメールアドレス)には「(ユニーク)」と明記されている。
     *
     * DB の件数だけでなく画面まで見ているのは、制約を足されたときに
     * 2件目の POST が落ちて表示が1件に減ることまで拾うため。
     * コメントを別々にしているのは、assertSee が「その文字列があるか」しか
     * 見ないので、2件とも同じ文面だと1件しか出ていなくても通ってしまうから。
     */
    public function test_同じ本に2回レビューすると詳細ページに2件とも表示される(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->actingAs($user)
            ->post('/books/' . $book->id . '/reviews', [
                'rating' => 3,
                'comment' => '1件目のレビュー',
            ])
            ->assertRedirect(route('books.show', $book));

        $this->actingAs($user)
            ->post('/books/' . $book->id . '/reviews', [
                'rating' => 5,
                'comment' => '2件目のレビュー',
            ]);

        $this->assertDatabaseCount('reviews', 2);

        $this->actingAs($user)
            ->get(route('books.show', $book))
            ->assertOk()
            ->assertSee('1件目のレビュー')
            ->assertSee('2件目のレビュー');
    }

    /**
     * 投稿者は自分のレビューを更新できる。
     *
     * 前提: 自分が投稿したレビュー1件(rating 2)
     * 操作: rating と comment を書き換えて PUT /reviews/{review}
     * 期待: reviews の内容が変わる / 行は増えない /
     *       その書籍の詳細へリダイレクト + 「レビューを更新しました。」
     *
     * リダイレクト先を見ている理由:
     * 更新後に「どこへ戻すか」もコントローラーが決めている仕様の一部で、
     * $review->book をたどって戻り先の書籍を組み立てている。
     * このたどり方が壊れると 500 になるため、リダイレクト先の検証が
     * そのままリレーションの検証を兼ねる。
     *
     * 以前は #review-{id} を付けて戻していたが、対応する id がビューに
     * 存在せず機能していなかったため #85 で外した。
     * 将来アンカーを有効にする場合、ページ上部のフラッシュメッセージが
     * 画面外へ出るため、表示位置とセットで考える必要がある。
     *
     * 行数も見ているのは、update が create に書き換わったときに
     * 「新しい行が増えて古い行も残る」壊れ方を拾うため。
     */
    public function test_投稿者は自分のレビューを更新できる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $review = Review::factory()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => 2,
            'comment' => '更新前のコメント',
        ]);

        $this->actingAs($user)
            ->put('/reviews/' . $review->id, [
                'rating' => 5,
                'comment' => '更新後のコメント',
            ])
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHas('success', 'レビューを更新しました。');

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'rating' => 5,
            'comment' => '更新後のコメント',
        ]);
        $this->assertDatabaseCount('reviews', 1);
    }

    /**
     * 投稿者は自分のレビューを削除でき、他人のレビューは巻き込まれない。
     *
     * 前提: 同じ書籍に、自分のレビュー1件と他人のレビュー1件
     * 操作: 自分のレビューに DELETE /reviews/{review}
     * 期待: 自分のレビューだけ消えて reviews は1行残る /
     *       その書籍の詳細へリダイレクト + 「レビューを削除しました。」
     *
     * 他人のレビューを1件置いている理由は書籍の削除テストと同じで、
     * 消しすぎを同時に検出するため。
     * 同じ書籍に付けているのは「1冊に複数人のレビュー」という
     * 実際の使われ方に近くなるから。
     */
    public function test_投稿者は自分のレビューを削除できる(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::factory()->create();

        $myReview = Review::factory()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
        $othersReview = Review::factory()->create([
            'user_id' => $other->id,
            'book_id' => $book->id,
        ]);

        $this->actingAs($user)
            ->delete('/reviews/' . $myReview->id)
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHas('success', 'レビューを削除しました。');

        $this->assertDatabaseMissing('reviews', ['id' => $myReview->id]);
        $this->assertDatabaseHas('reviews', ['id' => $othersReview->id]);
        $this->assertDatabaseCount('reviews', 1);
    }

    /**
     * レビューのいいねは、同じ URL を叩くたびに付いたり外れたりする(トグル)。
     *
     * 前提: 他人のレビュー1件。いいねはまだ0行
     * 操作: POST /reviews/{review}/like を2回叩く
     * 期待: 1回目で review_likes に1行 / 2回目でその行が消える
     *
     * 1本のテストで往復させている理由:
     * この機能の仕様は「付く」でも「外れる」でもなく「同じ操作で切り替わる」ことなので、
     * 2回叩いて初めて仕様を1つ検証したことになる。
     * 別々のテストに分けると、2本目のために「いいね済みの状態」を
     * テスト側で作ることになり、本番と違う経路で作った状態を消すテストになってしまう。
     *
     * 他人のレビューにしているのは、自分のレビューに自分でいいねするのは
     * 実際の使われ方として不自然なため(コントローラーは区別していない)。
     */
    public function test_レビューのいいねは2回叩くと元に戻る(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $review = Review::factory()->create([
            'user_id' => User::factory()->create()->id,
            'book_id' => $book->id,
        ]);

        // 1回目 -- いいねが付く
        $this->actingAs($user)
            ->post('/reviews/' . $review->id . '/like')
            ->assertRedirect(route('books.show', $book));

        $this->assertDatabaseHas('review_likes', [
            'user_id' => $user->id,
            'review_id' => $review->id,
        ]);

        // 2回目 -- 同じ URL でいいねが外れる
        $this->actingAs($user)
            ->post('/reviews/' . $review->id . '/like');

        $this->assertDatabaseMissing('review_likes', [
            'user_id' => $user->id,
            'review_id' => $review->id,
        ]);
        $this->assertDatabaseCount('review_likes', 0);
    }
}
