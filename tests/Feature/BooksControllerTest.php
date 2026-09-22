<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BooksControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ページネーションは10項目で1ページであるか(): void
    {
        Book::factory(11)->create();
        $response = $this->get('/books');
        $response->assertViewHas('books', function ($viewBooks) {
            return $viewBooks->count() === 10;
        });
    }

    public function test_件数が15件の場合、ページネーションの2ページ目に5件が表示されているか(): void
    {
        Book::factory(15)->create();
        $response = $this->get('/books?page=2');
        $response->assertViewHas('books', function ($viewBooks) {
            return $viewBooks->count() === 5;
        });
    }

    public function test_書籍情報を取得できる(): void
    {
        $book = Book::factory(1)->create()->first();
        $response = $this->get('/books');
        $response->assertViewHas('books', function ($viewBooks) use ($book) {
            return $book->only('title', 'author', 'image_url') == $viewBooks->first()->only('title', 'author', 'image_url');
        });
    }

    public function test_書籍情報が最新順で表示される(): void
    {
        foreach ([7, 8, 9, 10, 1, 2, 3, 4, 5, 6] as $days) {
            Book::factory()->create(['created_at' => now()->subDays($days)]);
        }
        $response = $this->get('/books');
        $response->assertViewHas('books', function ($viewBooks) {
            return [5, 6, 7, 8, 9, 10, 1, 2, 3, 4] === $viewBooks->pluck('id')->all();
        });
    }

    public function test_書籍一覧に平均評価点が表示される(): void
    {
        $book = Book::factory()->create(['created_at' => now()->subDays(1)]);
        foreach ([3, 4, 5] as $rating) {
            Review::factory()->create(['book_id' => $book->id, 'rating' => $rating]);
        }

        $book = Book::factory()->create(['created_at' => now()->subDays(2)]);
        foreach ([1, 2, 3] as $rating) {
            Review::factory()->create(['book_id' => $book->id, 'rating' => $rating]);
        }

        // 3冊目はレビュー無し。withAvg が null を返すことを確かめるため。
        Book::factory()->create(['created_at' => now()->subDays(3)]);

        $response = $this->get('/books');
        $response->assertViewHas('books', function ($viewBooks) {
            return $viewBooks->pluck('reviews_avg_rating')->all() === [4.0, 2.0, null];
        });
    }
}
