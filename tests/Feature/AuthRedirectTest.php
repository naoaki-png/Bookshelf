<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 認証が必要なルートに未ログインでアクセスしたとき、/login にリダイレクトされることを確認する。
 *
 * URL に {book} {genre} {review} を含むルートでは、対象のレコードを先に作っている。
 * ルートモデルバインディング(SubstituteBindings)は auth ミドルウェアより先に動くため、
 * 存在しない id を渡すと 404 になり、リダイレクトを確認できないため。
 */
class AuthRedirectTest extends TestCase
{
    use RefreshDatabase;

    // books

    public function test_未ログインで書籍登録画面はログイン画面にリダイレクトされる(): void
    {
        $this->get('/books/create')->assertRedirect('/login');
    }

    public function test_未ログインで書籍の登録はログイン画面にリダイレクトされる(): void
    {
        $this->post('/books')->assertRedirect('/login');
    }

    public function test_未ログインで書籍編集画面はログイン画面にリダイレクトされる(): void
    {
        $book = Book::factory()->create();

        $this->get('/books/' . $book->id . '/edit')->assertRedirect('/login');
    }

    public function test_未ログインで書籍の更新はログイン画面にリダイレクトされる(): void
    {
        $book = Book::factory()->create();

        $this->put('/books/' . $book->id)->assertRedirect('/login');
    }

    public function test_未ログインで書籍の削除はログイン画面にリダイレクトされる(): void
    {
        $book = Book::factory()->create();

        $this->delete('/books/' . $book->id)->assertRedirect('/login');
    }

    // favorites

    public function test_未ログインでお気に入り一覧はログイン画面にリダイレクトされる(): void
    {
        $this->get('/favorites')->assertRedirect('/login');
    }

    public function test_未ログインでお気に入りの切り替えはログイン画面にリダイレクトされる(): void
    {
        $book = Book::factory()->create();

        $this->post('/books/' . $book->id . '/favorites')->assertRedirect('/login');
    }

    // genres

    public function test_未ログインでジャンル一覧はログイン画面にリダイレクトされる(): void
    {
        $this->get('/genres')->assertRedirect('/login');
    }

    public function test_未ログインでジャンル登録画面はログイン画面にリダイレクトされる(): void
    {
        $this->get('/genres/create')->assertRedirect('/login');
    }

    public function test_未ログインでジャンルの登録はログイン画面にリダイレクトされる(): void
    {
        $this->post('/genres')->assertRedirect('/login');
    }

    public function test_未ログインでジャンル詳細はログイン画面にリダイレクトされる(): void
    {
        $genre = Genre::factory()->create();

        $this->get('/genres/' . $genre->id)->assertRedirect('/login');
    }

    public function test_未ログインでジャンル編集画面はログイン画面にリダイレクトされる(): void
    {
        $genre = Genre::factory()->create();

        $this->get('/genres/' . $genre->id . '/edit')->assertRedirect('/login');
    }

    public function test_未ログインでジャンルの更新はログイン画面にリダイレクトされる(): void
    {
        $genre = Genre::factory()->create();

        $this->put('/genres/' . $genre->id . '/edit')->assertRedirect('/login');
    }

    public function test_未ログインでジャンルの削除はログイン画面にリダイレクトされる(): void
    {
        $genre = Genre::factory()->create();

        $this->delete('/genres/' . $genre->id)->assertRedirect('/login');
    }

    // reviews

    public function test_未ログインでレビューの投稿はログイン画面にリダイレクトされる(): void
    {
        $book = Book::factory()->create();

        $this->post('/books/' . $book->id . '/reviews')->assertRedirect('/login');
    }

    public function test_未ログインでレビュー編集画面はログイン画面にリダイレクトされる(): void
    {
        $review = Review::factory()->create();

        $this->get('/reviews/' . $review->id . '/edit')->assertRedirect('/login');
    }

    public function test_未ログインでレビューの更新はログイン画面にリダイレクトされる(): void
    {
        $review = Review::factory()->create();

        $this->put('/reviews/' . $review->id)->assertRedirect('/login');
    }

    public function test_未ログインでレビューの削除はログイン画面にリダイレクトされる(): void
    {
        $review = Review::factory()->create();

        $this->delete('/reviews/' . $review->id)->assertRedirect('/login');
    }

    public function test_未ログインでレビューのいいねはログイン画面にリダイレクトされる(): void
    {
        $review = Review::factory()->create();

        $this->post('/reviews/' . $review->id . '/like')->assertRedirect('/login');
    }
}
