<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Book;
use App\Models\BookUser;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
class BookReviewTest extends TestCase
{
    use RefreshDatabase;
    /**
     * A basic unit test example.
     */
    public function test_一つの書籍に紐づく複数のレビューが取得できる(): void
    {
        $book = Book::factory()->create();
        $bookUser = BookUser::factory()->create(['book_id' => $book->id]);
        $reviews = Review::factory()->count(3)->create(['book_user_id' => $bookUser->id]);
        $book->refresh();
        $result = $book->reviews->count();
        $this->assertEquals(3, $result);
    }

    public function test_平均評価の計算が正しいか(): void
    {
        $book = Book::factory()->create();
        $bookUser = BookUser::factory()->create(['book_id' => $book->id]);
        Review::factory()->create(['book_user_id' => $bookUser->id, 'rating' => 3]);
        Review::factory()->create(['book_user_id' => $bookUser->id, 'rating' => 4]);
        Review::factory()->create(['book_user_id' => $bookUser->id, 'rating' => 5]);
        $book->refresh();
        $result = $book->reviews->avg('rating');

        $this->assertEquals(4, $result);
    }
}
