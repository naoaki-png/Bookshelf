<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookUser;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * レビューの投稿・更新・削除・いいねが、ログイン済みユーザーの
 * 正しい操作で最後まで完走することを確認する(正常系)。
 *
 * このアプリのレビューは books に直接ぶら下がっていない。
 * users --- book_users --- reviews という構造で、book_users が
 * 「この人がこの本を読んだ」という1行を表している。
 * そのため投稿のテストでは reviews だけでなく book_users も見る必要がある。
 */
class ReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ログイン済みユーザーが書籍にレビューを投稿できる。
     *
     * 前提: ユーザー1人、書籍1冊。レビューも book_users もまだ0行
     * 操作: 正しい rating と comment で POST /books/{book}/reviews
     * 期待: reviews に1行 / book_users にも1行できる / books.show へリダイレクト
     *
     * book_users を見ている理由:
     * ReviewsController@store は、投稿者と書籍の組み合わせで BookUser を
     * 用意してから、その id を reviews.book_user_id に入れている。
     * レビューだけ増えて book_users の中身(user_id / book_id)が
     * 間違っていると、「誰のレビューか」の判定が全部ずれる。
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
            ->assertRedirect(route('books.show', $book));

        $this->assertDatabaseHas('book_users', [
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);

        $bookUser = BookUser::where('user_id', $user->id)->where('book_id', $book->id)->first();
        $this->assertDatabaseHas('reviews', [
            'book_user_id' => $bookUser->id,
            'rating' => 4,
            'comment' => '投稿したレビューのコメント',
        ]);
    }

    /**
     * 同じ人が同じ本にレビューを2回投稿しても、book_users は1行のまま増えない。
     *
     * 前提: ユーザー1人、書籍1冊。まだレビューは無い
     * 操作: 同じユーザーで同じ本に、内容の違うレビューを2回 POST する
     * 期待: reviews は2行 / book_users は1行
     *
     * ReviewsController@store は BookUser を firstOrCreate で取っている。
     * create だと2回目で book_users の unique(['user_id','book_id']) に当たって
     * 落ちるため、この1行が仕様として効いている。
     * 「2行になっていないこと」を見ないと、この firstOrCreate が create に
     * 書き換わっても気づけない。
     *
     * rating を 3 と 5 で分けているのは、2件目が1件目の上書きではなく
     * 別レコードとして増えたことを、件数以外からも確認できるようにするため。
     */
    public function test_同じ本に2回レビューしてもbook_usersは1行のまま(): void
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
        $this->assertDatabaseCount('book_users', 1);
    }

    /**
     * 投稿者は自分のレビューを更新できる。
     *
     * 前提: 自分が投稿したレビュー1件(rating 2)
     * 操作: rating と comment を書き換えて PUT /reviews/{review}
     * 期待: reviews の内容が変わる / 行は増えない / 該当レビューのアンカー付きでリダイレクト
     *
     * リダイレクト先に #review-{id} が付くところまで見ている理由:
     * 更新後に「どこへ戻すか」もコントローラーが決めている仕様の一部で、
     * $review->bookUser->book をたどってリダイレクト先を組み立てている。
     * このたどり方が壊れると 500 になるため、リダイレクト先の検証が
     * そのままリレーションの検証を兼ねる。
     *
     * 行数も見ているのは、update が create に書き換わったときに
     * 「新しい行が増えて古い行も残る」壊れ方を拾うため。
     */
    public function test_投稿者は自分のレビューを更新できる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $bookUser = BookUser::factory()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
        $review = Review::factory()->create([
            'book_user_id' => $bookUser->id,
            'rating' => 2,
            'comment' => '更新前のコメント',
        ]);

        $this->actingAs($user)
            ->put('/reviews/' . $review->id, [
                'rating' => 5,
                'comment' => '更新後のコメント',
            ])
            ->assertRedirect(route('books.show', $book) . '#review-' . $review->id);

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
     * 期待: 自分のレビューだけ消えて reviews は1行残る / レビュー欄へリダイレクト
     *
     * 他人のレビューを1件置いている理由は書籍の削除テストと同じで、
     * 消しすぎを同時に検出するため。
     * 同じ書籍に付けているのは、book_users が別々の行になることで
     * 「1冊に複数人のレビュー」という実際の使われ方に近くなるから。
     */
    public function test_投稿者は自分のレビューを削除できる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $myBookUser = BookUser::factory()->create(['user_id' => $user->id, 'book_id' => $book->id]);
        $myReview = Review::factory()->create(['book_user_id' => $myBookUser->id]);

        $othersBookUser = BookUser::factory()->create([
            'user_id' => User::factory()->create()->id,
            'book_id' => $book->id,
        ]);
        $othersReview = Review::factory()->create(['book_user_id' => $othersBookUser->id]);

        $this->actingAs($user)
            ->delete('/reviews/' . $myReview->id)
            ->assertRedirect(route('books.show', $book) . '#review-section');

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
        $bookUser = BookUser::factory()->create([
            'user_id' => User::factory()->create()->id,
            'book_id' => $book->id,
        ]);
        $review = Review::factory()->create(['book_user_id' => $bookUser->id]);

        // 1回目 -- いいねが付く
        $this->actingAs($user)
            ->post('/reviews/' . $review->id . '/like')
            ->assertRedirect(route('books.show', $book) . '#review-' . $review->id);

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
