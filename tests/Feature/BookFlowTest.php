<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 書籍の登録・更新・削除が、ログイン済みユーザーの正しい操作で
 * 最後まで完走することを確認する(正常系)。
 *
 * このファイルで見ているのは次の3点。
 *   1. DB が意図したとおりに変わったか
 *   2. 中間テーブル(book_genres)が意図したとおりに変わったか
 *   3. 正しい画面へリダイレクトされたか
 *
 * 「200 が返ったか」ではなく「DB がどう変わったか」を主にしているのは、
 * リダイレクト先が合っていても保存内容が間違っている、という壊れ方を
 * 拾えるようにするため。
 */
class BookFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ログイン済みユーザーが正しい入力で書籍を登録できる。
     *
     * 前提: ユーザー1人、ジャンル2件。書籍はまだ0冊
     * 操作: すべての項目を正しく埋めて POST /books
     * 期待: books に1行 / user_id が投稿者本人 / book_genres に2行 / books.index へリダイレクト
     *
     * user_id を検証している理由:
     * BooksController@store は user_id をリクエストから受け取らず、
     * Auth::user()->id を自分で入れている。ここが取り違えられていると
     * 所有者判定(BookPolicy)が全部崩れるが、画面上はしばらく気づけない。
     *
     * ジャンルを2件にしている理由:
     * sync() に配列がそのまま渡っていることを確かめるため。1件だと
     * 「たまたま先頭の1件だけ入った」状態と区別がつかない。
     */
    public function test_ログイン済みユーザーは書籍を登録できる(): void
    {
        $user = User::factory()->create();
        $genreA = Genre::factory()->create(['name' => '小説']);
        $genreB = Genre::factory()->create(['name' => '技術書']);

        $this->actingAs($user)
            ->post('/books', [
                'title' => '登録した本のタイトル',
                'author' => '登録した本の著者',
                'isbn' => '9784000000001',
                'description' => '登録した本の説明',
                'published_date' => '2020-01-01',
                'image_url' => 'https://example.com/cover.jpg',
                'genres' => [$genreA->id, $genreB->id],
            ])
            ->assertRedirect(route('books.index'));

        $this->assertDatabaseHas('books', [
            'title' => '登録した本のタイトル',
            'author' => '登録した本の著者',
            'isbn' => '9784000000001',
            'published_date' => '2020-01-01',
            'user_id' => $user->id,
        ]);

        // 中間テーブルは「2行あること」と「その2行が選んだジャンルであること」を分けて見る。
        // 件数だけだと、別のジャンル id が2件入っていても通ってしまう。
        $book = Book::where('isbn', '9784000000001')->first();
        $this->assertDatabaseCount('book_genres', 2);
        $this->assertDatabaseHas('book_genres', ['book_id' => $book->id, 'genre_id' => $genreA->id]);
        $this->assertDatabaseHas('book_genres', ['book_id' => $book->id, 'genre_id' => $genreB->id]);
    }

    /**
     * 所有者は自分の書籍を更新でき、ジャンルの選び直しが反映される。
     *
     * 前提: 自分の書籍1冊にジャンルA・Bが紐づいている。別にジャンルCも存在する
     * 操作: タイトルと著者を書き換え、ジャンルは C だけを選んで PUT /books/{book}
     * 期待: books の内容が変わる / book_genres は C の1行だけになる / books.show へリダイレクト
     *
     * 「Cが入ったこと」だけでなく「A・Bが消えたこと」を見ている理由:
     * BooksController@update は sync() を使っている。sync() は
     * 「渡した集合と一致させる」メソッドで、渡さなかったものを外す。
     * これを attach() に書き換えると A・B が残ったまま C が足されるが、
     * 追加されたことしか見ていないテストはそれを通してしまう。
     * 外れたことまで見て、はじめて sync と attach の差が検出できる。
     */
    public function test_所有者は自分の書籍を更新できる(): void
    {
        $user = User::factory()->create();
        $genreA = Genre::factory()->create(['name' => '小説']);
        $genreB = Genre::factory()->create(['name' => '技術書']);
        $genreC = Genre::factory()->create(['name' => 'ビジネス']);

        $book = Book::factory()->create([
            'user_id' => $user->id,
            'title' => '更新前のタイトル',
            'author' => '更新前の著者',
        ]);
        $book->genres()->sync([$genreA->id, $genreB->id]);

        $this->actingAs($user)
            ->put('/books/' . $book->id, [
                'title' => '更新後のタイトル',
                'author' => '更新後の著者',
                'isbn' => '9784000000002',
                'description' => '更新後の説明',
                'published_date' => '2021-02-03',
                'image_url' => 'https://example.com/updated.jpg',
                'genres' => [$genreC->id],
            ])
            ->assertRedirect(route('books.show', $book));

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'title' => '更新後のタイトル',
            'author' => '更新後の著者',
            'isbn' => '9784000000002',
            'published_date' => '2021-02-03',
        ]);

        // 選び直しの結果、この書籍に紐づくジャンルは C ただ1件になる。
        $this->assertDatabaseCount('book_genres', 1);
        $this->assertDatabaseHas('book_genres', ['book_id' => $book->id, 'genre_id' => $genreC->id]);
        $this->assertDatabaseMissing('book_genres', ['book_id' => $book->id, 'genre_id' => $genreA->id]);
        $this->assertDatabaseMissing('book_genres', ['book_id' => $book->id, 'genre_id' => $genreB->id]);
    }

    /**
     * 所有者は自分の書籍を削除でき、他人の書籍は巻き込まれない。
     *
     * 前提: 自分の書籍1冊、他人の書籍1冊。合わせて2冊
     * 操作: 自分の書籍に DELETE /books/{book}
     * 期待: 自分の書籍だけが消えて books は1冊残る / books.index へリダイレクト
     *
     * 他人の書籍を1冊置いている理由:
     * 「消えたこと」だけを見ると、条件を間違えて全件消すコードでも通ってしまう。
     * 残るべき行を1つ用意しておくと、消しすぎを同時に検出できる。
     */
    public function test_所有者は自分の書籍を削除できる(): void
    {
        $user = User::factory()->create();
        $myBook = Book::factory()->create(['user_id' => $user->id]);
        $othersBook = Book::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($user)
            ->delete('/books/' . $myBook->id)
            ->assertRedirect(route('books.index'));

        $this->assertDatabaseMissing('books', ['id' => $myBook->id]);
        $this->assertDatabaseHas('books', ['id' => $othersBook->id]);
        $this->assertDatabaseCount('books', 1);
    }
}
