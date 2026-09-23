<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
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

    /**
     * 所有者が編集画面を開くと、対象の書籍と全ジャンルがビューに渡り、
     * フォームには既存の値(タイトル・著者)と、紐づいているジャンルのチェックが入る。
     *
     * 前提: 書籍1冊(ジャンルAが紐づく)、ジャンルはAとBの2件
     * 操作: 所有者で GET /books/{book}/edit
     * 期待: book はその書籍そのもの / genres は登録されている全件(A・B) /
     *       画面にタイトル・著者が出る / ジャンルAのチェックボックスは checked、Bは checked でない
     *
     * AuthorizationTest は「他人だと403になる」までしか見ていないので、
     * こちらは「本人が開いたときに正しいデータが渡っているか」を別の観点として確認する。
     */
    public function test_書籍編集画面には対象の書籍と全ジャンルが渡り既存の値が入る(): void
    {
        $owner = User::factory()->create();
        $genreA = Genre::factory()->create(['name' => '小説']);
        $genreB = Genre::factory()->create(['name' => '技術書']);
        $book = Book::factory()->create([
            'user_id' => $owner->id,
            'title' => '編集対象のタイトル',
            'author' => '編集対象の著者',
        ]);
        $book->genres()->sync([$genreA->id]);

        $response = $this->actingAs($owner)->get('/books/' . $book->id . '/edit');

        $response->assertOk();
        $response->assertViewHas('book', function ($viewBook) use ($book) {
            return $viewBook->is($book);
        });
        $response->assertViewHas('genres', function ($viewGenres) use ($genreA, $genreB) {
            return $viewGenres->pluck('id')->sort()->values()->all()
                === collect([$genreA->id, $genreB->id])->sort()->values()->all();
        });

        $response->assertSee('編集対象のタイトル', false);
        $response->assertSee('編集対象の著者', false);

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/value="' . $genreA->id . '"[^>]*checked/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/value="' . $genreB->id . '"[^>]*checked/',
            $html
        );
    }
}
