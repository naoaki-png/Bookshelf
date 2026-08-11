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
 * 所有者以外が更新・削除しようとしたときに 403 が返ることを確認する。
 *
 * update 系は FormRequest の検証がコントローラー本体より先に走るため、
 * バリデーションを通る正しいデータを送っている。
 * 不正なデータだと authorize() に到達せず 302(バリデーションエラー)になり、
 * 403 を検証できないため。
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // books - 所有者は books.user_id

    public function test_他人の書籍の編集画面は403になる(): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);

        $this->actingAs(User::factory()->create())
            ->get('/books/' . $book->id . '/edit')
            ->assertForbidden();
    }

    public function test_他人の書籍を更新すると403になる(): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        $genre = Genre::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put('/books/' . $book->id, [
                'title' => '更新後のタイトル',
                'author' => '更新後の著者',
                'isbn' => '9784000000001',
                'description' => '説明',
                'published_date' => '2020-01-01',
                'image_url' => 'https://example.com/image.jpg',
                'genres' => [$genre->id],
            ])
            ->assertForbidden();
    }

    public function test_他人の書籍を削除すると403になる(): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);

        $this->actingAs(User::factory()->create())
            ->delete('/books/' . $book->id)
            ->assertForbidden();
    }

    // reviews - 所有者は review->bookUser->user_id

    public function test_他人のレビューの編集画面は403になる(): void
    {
        $owner = User::factory()->create();
        $bookUser = BookUser::factory()->create(['user_id' => $owner->id]);
        $review = Review::factory()->create(['book_user_id' => $bookUser->id]);

        $this->actingAs(User::factory()->create())
            ->get('/reviews/' . $review->id . '/edit')
            ->assertForbidden();
    }

    public function test_他人のレビューを更新すると403になる(): void
    {
        $owner = User::factory()->create();
        $bookUser = BookUser::factory()->create(['user_id' => $owner->id]);
        $review = Review::factory()->create(['book_user_id' => $bookUser->id]);

        $this->actingAs(User::factory()->create())
            ->put('/reviews/' . $review->id, [
                'rating' => 5,
                'comment' => '更新後のコメント',
            ])
            ->assertForbidden();
    }

    public function test_他人のレビューを削除すると403になる(): void
    {
        $owner = User::factory()->create();
        $bookUser = BookUser::factory()->create(['user_id' => $owner->id]);
        $review = Review::factory()->create(['book_user_id' => $bookUser->id]);

        $this->actingAs(User::factory()->create())
            ->delete('/reviews/' . $review->id)
            ->assertForbidden();
    }
}
