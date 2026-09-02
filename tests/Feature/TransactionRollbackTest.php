<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 書き込みを2回行う処理が、途中で失敗したときに
 * 1回目の書き込みまで取り消されることを確認する。
 *
 * 対象は DB::transaction を適用した5箇所。
 *   1. BooksController@store        books      → book_genres
 *   2. BooksController@update       books      → book_genres
 *   3. ApiBookController@store      books      → book_genres
 *   4. ApiBookController@update     books      → book_genres
 *   5. ReviewsController@store      book_users → reviews
 *
 * 【失敗をどう起こしているか】
 *
 * 本番で2回目の書き込みが失敗する原因は、接続断・タイムアウト・デッドロック・
 * 外部キー制約違反など、テストから再現しにくいものばかりになる。
 * ジャンル ID は ApiBookRequest / BookRequest の exists:genres,id で
 * 事前に弾かれるため、不正な値を送って失敗させることもできない。
 *
 * そこで DB::listen で SQL を監視し、狙った表への書き込みが流れた瞬間に
 * 例外を投げている。「どんな理由で落ちたか」ではなく
 * 「落ちたときに1回目が残らないか」だけを見たいので、原因は何でもよい。
 *
 * 【DB::listen はクエリの実行後に発火する点について】
 *
 * このイベントは Connection::run() がクエリを実行し終えてから発火する。
 * つまり2回目の書き込み自体は一度 DB に届いている。
 * それでもテストとして成立するのは、届いた先がトランザクションの内側であり、
 * ロールバックの対象になるため。1回目も2回目も消えることを確認できる。
 *
 * 【アサーションの向き】
 *
 * 「例外が出たこと」ではなく「DB に残っていないこと」を主に見ている。
 * DB::transaction を外しても例外は同じように飛ぶので、
 * 例外の確認だけではトランザクションの有無を判別できない。
 */
class TransactionRollbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 指定した表への書き込みが流れた瞬間に例外を投げる。
     *
     * SELECT を除外しているのは、sync() が差分を求めるために
     * 先に現在の紐付けを読みにいくため。読みで落とすと
     * 2回目の書き込みに到達する前に止まってしまう。
     *
     * 一度だけ投げるようにしているのは、この後のアサーションが
     * 同じ表を読むときに巻き添えで落ちないようにするため。
     */
    private function failOnWriteTo(string $table): void
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use ($table, &$fired) {
            if ($fired) {
                return;
            }

            $sql = strtolower($query->sql);

            if (str_starts_with($sql, 'select') || ! str_contains($sql, $table)) {
                return;
            }

            $fired = true;

            throw new \DomainException('意図的に発生させた失敗: ' . $table);
        });
    }

    /**
     * 実際にトークンを発行して Authorization ヘッダを組み立てる。
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test-token')->plainTextToken];
    }

    /**
     * 例外が投げられることを確かめつつ、テストを止めずに先へ進める。
     *
     * PHPUnit の Exception は RuntimeException を継承しているため、
     * catch (\RuntimeException) にすると $this->fail() まで拾ってしまう。
     * それを避けるために DomainException を目印として使っている。
     */
    private function assertOperationFailed(callable $operation): void
    {
        $thrown = false;

        try {
            $operation();
        } catch (\DomainException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, '意図した失敗が発生しなかった。テストの前提が崩れている');
    }

    /**
     * Web の書籍登録で、ジャンルの紐付けが失敗すると書籍も残らない。
     *
     * 前提: ユーザー1人、ジャンル1件。書籍は0冊
     * 操作: book_genres への書き込みを失敗させた状態で POST /books
     * 期待: books が0件のまま
     *
     * トランザクションが無いと books に1行だけ残る。
     * これは「ジャンルが1つも紐付いていない書籍」で、
     * genres を required にしているバリデーションの前提を壊す。
     */
    public function test_書籍登録でジャンルの紐付けが失敗すると書籍も保存されない(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $this->withoutExceptionHandling();
        $this->failOnWriteTo('book_genres');

        $this->assertOperationFailed(function () use ($user, $genre) {
            $this->actingAs($user)->post('/books', [
                'title' => 'ロールバックされるはずの本',
                'author' => '著者',
                'genres' => [$genre->id],
            ]);
        });

        $this->assertDatabaseCount('books', 0);
        $this->assertDatabaseMissing('books', ['title' => 'ロールバックされるはずの本']);
    }

    /**
     * Web の書籍更新で、ジャンルの貼り替えが失敗すると本文の更新も戻る。
     *
     * 前提: 自分の書籍1冊(タイトルは「更新前のタイトル」、ジャンルA)
     * 操作: book_genres への書き込みを失敗させた状態で、タイトルとジャンルを変更する PUT
     * 期待: タイトルが「更新前のタイトル」のまま
     *
     * ジャンルをAからBへ入れ替えているのは、sync() に実際の書き込みを
     * 起こさせるため。同じジャンルを送ると差分が無く、SELECT だけで終わる。
     */
    public function test_書籍更新でジャンルの貼り替えが失敗するとタイトルの更新も取り消される(): void
    {
        $user = User::factory()->create();
        $genreA = Genre::factory()->create(['name' => '小説']);
        $genreB = Genre::factory()->create(['name' => '技術書']);

        $book = Book::factory()->create([
            'user_id' => $user->id,
            'title' => '更新前のタイトル',
        ]);
        $book->genres()->attach($genreA);

        $this->withoutExceptionHandling();
        $this->failOnWriteTo('book_genres');

        $this->assertOperationFailed(function () use ($user, $book, $genreB) {
            $this->actingAs($user)->put('/books/' . $book->id, [
                'title' => '更新後のタイトル',
                'author' => $book->author,
                'genres' => [$genreB->id],
            ]);
        });

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'title' => '更新前のタイトル',
        ]);
        $this->assertDatabaseMissing('books', ['title' => '更新後のタイトル']);
    }

    /**
     * API の書籍登録で、ジャンルの紐付けが失敗すると書籍も残らない。
     *
     * 前提: ユーザー1人、ジャンル1件。書籍は0冊
     * 操作: book_genres への書き込みを失敗させた状態で POST /api/v1/books
     * 期待: books が0件のまま
     *
     * Web 版と同じ壊れ方だが、こちらは戻り値を
     * DB::transaction の外へ持ち出している(BookShowResource に渡すため)。
     * 囲み方が違うので別のテストとして確認する。
     */
    public function test_API書籍登録でジャンルの紐付けが失敗すると書籍も保存されない(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();
        $headers = $this->bearer($user);

        $this->withoutExceptionHandling();
        $this->failOnWriteTo('book_genres');

        $this->assertOperationFailed(function () use ($genre, $headers) {
            $this->postJson('/api/v1/books', [
                'title' => 'API からロールバックされるはずの本',
                'author' => '著者',
                'genres' => [$genre->id],
            ], $headers);
        });

        $this->assertDatabaseCount('books', 0);
    }

    /**
     * API の書籍更新で、ジャンルの貼り替えが失敗すると本文の更新も戻る。
     *
     * 前提: 自分の書籍1冊(タイトルは「更新前のタイトル」、ジャンルA)
     * 操作: book_genres への書き込みを失敗させた状態で PUT /api/v1/books/{book}
     * 期待: タイトルが「更新前のタイトル」のまま
     */
    public function test_API書籍更新でジャンルの貼り替えが失敗するとタイトルの更新も取り消される(): void
    {
        $user = User::factory()->create();
        $genreA = Genre::factory()->create(['name' => '小説']);
        $genreB = Genre::factory()->create(['name' => '技術書']);
        $headers = $this->bearer($user);

        $book = Book::factory()->create([
            'user_id' => $user->id,
            'title' => '更新前のタイトル',
        ]);
        $book->genres()->attach($genreA);

        $this->withoutExceptionHandling();
        $this->failOnWriteTo('book_genres');

        $this->assertOperationFailed(function () use ($book, $genreB, $headers) {
            $this->putJson('/api/v1/books/' . $book->id, [
                'title' => '更新後のタイトル',
                'author' => $book->author,
                'genres' => [$genreB->id],
            ], $headers);
        });

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'title' => '更新前のタイトル',
        ]);
    }

    /**
     * レビュー投稿で、レビューの保存が失敗すると book_users の行も残らない。
     *
     * 前提: ユーザー1人、他人の書籍1冊。この2人の組み合わせの book_users は0件
     * 操作: reviews への書き込みを失敗させた状態で POST /books/{book}/reviews
     * 期待: book_users にも reviews にも行が残らない
     *
     * この箇所だけ書き込む表の組み合わせが違う。
     * firstOrCreate が作る book_users の行は、レビューが付いて初めて意味を持つ。
     * 取り消されないと、誰からも参照されない行が残り続ける。
     */
    public function test_レビュー投稿で保存が失敗すると中間テーブルの行も残らない(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->withoutExceptionHandling();
        $this->failOnWriteTo('reviews');

        $this->assertOperationFailed(function () use ($user, $book) {
            $this->actingAs($user)->post('/books/' . $book->id . '/reviews', [
                'rating' => 5,
                'comment' => 'ロールバックされるはずのレビュー',
            ]);
        });

        $this->assertDatabaseMissing('book_users', [
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseMissing('reviews', [
            'comment' => 'ロールバックされるはずのレビュー',
        ]);
    }
}
