<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic unit test example.
     */
    public function test_一つの書籍に紐づく複数のレビューが取得できる(): void
    {
        $book = Book::factory()->create();
        $reviews = Review::factory()->count(3)->create(['book_id' => $book->id]);
        $book->refresh();
        $result = $book->reviews->count();
        $this->assertEquals(3, $result);
    }

    public function test_平均評価の計算が正しいか(): void
    {
        $book = Book::factory()->create();
        Review::factory()->create(['book_id' => $book->id, 'rating' => 3]);
        Review::factory()->create(['book_id' => $book->id, 'rating' => 4]);
        Review::factory()->create(['book_id' => $book->id, 'rating' => 5]);
        $book->refresh();
        $result = $book->reviews->avg('rating');

        $this->assertEquals(4, $result);
    }
}
