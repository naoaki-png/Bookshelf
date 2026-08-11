<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 入力が要件を満たさないとき、FormRequest が保存を止めることを確認する。
 *
 * 見ているのは次の2点。
 *   1. 想定したキーにバリデーションエラーが付くか(assertInvalid)
 *   2. その結果 DB が変わっていないか
 *
 * 2番を必ず添えている理由:
 * エラーが出ていても保存されていたら意味がないし、逆に「エラーが出ること」
 * だけを見ていると、別の原因(例外・認可落ち)で止まっただけの状態と
 * 区別がつかない。エラーの中身と DB の両方を見て、はじめて
 * 「バリデーションで止まった」と言える。
 *
 * 書籍が6本中4本を占めているのは、BookRequest だけルールが7項目あり、
 * unique の除外指定という他に無い仕掛けが入っているため。
 * ReviewRequest は2項目、GenreRequest は1項目しかない。
 */
class ValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 書籍の登録で、すべての項目を正しく埋めた入力を返す。
     *
     * 各テストはこの配列を受け取って、検証したい1項目だけを差し替える。
     * こうしておくと「今このテストが何を壊しているか」が
     * 差し替えた行を見るだけで分かる。
     */
    private function validBookData(array $overrides = []): array
    {
        return array_merge([
            'title' => '正しいタイトル',
            'author' => '正しい著者',
            'isbn' => '9784000000001',
            'description' => '正しい説明',
            'published_date' => '2020-01-01',
            'image_url' => 'https://example.com/cover.jpg',
            'genres' => [Genre::factory()->create()->id],
        ], $overrides);
    }

    /**
     * 必須項目が空だと書籍は登録できない。
     *
     * 前提: ログイン済みユーザー1人。書籍は0冊
     * 操作: 何も入れずに POST /books
     * 期待: title / author / isbn / published_date / genres にエラー / books は0冊のまま
     *
     * 5項目をまとめて1本にしている理由:
     * required は5項目とも同じルールで、1項目ずつテストを分けても
     * 検証していることは変わらない。「必須項目が空なら登録できない」で
     * 1つの仕様として扱ったほうが読みやすい。
     *
     * 逆に description と image_url は nullable なので、
     * ここでエラーが出てはいけない。assertInvalid は指定したキーだけを
     * 見るので、この2つが混ざっていないことは別途 assertValid で確かめる。
     */
    public function test_必須項目が空だと書籍は登録できない(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/books', []);

        $response->assertInvalid(['title', 'author', 'isbn', 'published_date', 'genres']);
        $response->assertValid(['description', 'image_url']);
        $this->assertDatabaseCount('books', 0);
    }

    /**
     * ISBNが13桁の数字でないと書籍は登録できない。
     *
     * 前提: ログイン済みユーザー1人
     * 操作: 12桁の数字と、13文字の英字で、それぞれ POST /books
     * 期待: どちらも isbn にエラー / books は0冊のまま
     *
     * 2パターン投げている理由:
     * digits:13 は「数字であること」と「13桁であること」を同時に見ている。
     * 桁数だけ試すと、ルールが numeric に弱まったときに気づけないし、
     * 文字種だけ試すと桁数の制限が外れたときに気づけない。
     * どちらの緩み方も拾えるように、両方から1回ずつ突いている。
     */
    public function test_ISBNが13桁の数字でないと書籍は登録できない(): void
    {
        $user = User::factory()->create();

        // 数字だが12桁
        $this->actingAs($user)
            ->post('/books', $this->validBookData(['isbn' => '123456789012']))
            ->assertInvalid(['isbn']);

        // 13文字だが数字ではない
        $this->actingAs($user)
            ->post('/books', $this->validBookData(['isbn' => 'abcdefghijklm']))
            ->assertInvalid(['isbn']);

        $this->assertDatabaseCount('books', 0);
    }

    /**
     * ISBNは他の書籍と重複できないが、自分自身のISBNなら更新できる。
     *
     * 前提: ISBN 9784000000001 の書籍が1冊(自分の本)
     * 操作1: 同じISBNで別の書籍を POST /books
     * 操作2: その書籍のISBNを変えずに、タイトルだけ変えて PUT /books/{book}
     * 期待1: isbn にエラー / 書籍は増えない
     * 期待2: エラーにならず更新される
     *
     * 操作2を必ずセットにしている理由:
     * BookRequest は編集時だけ unique に自分の id を渡して、自分自身を
     * 重複チェックの対象から外している。この除外指定が抜けると、
     * 「編集画面で何も変えずに保存しただけでISBNが重複エラーになる」
     * という、実際によく起きるバグになる。
     * 重複を弾く側だけを見ていると、この抜けを一切検出できない。
     */
    public function test_ISBNは重複できないが自分自身のISBNなら更新できる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create([
            'user_id' => $user->id,
            'isbn' => '9784000000001',
            'title' => '更新前のタイトル',
        ]);

        // 他の書籍と同じ ISBN では登録できない
        $this->actingAs($user)
            ->post('/books', $this->validBookData(['isbn' => '9784000000001']))
            ->assertInvalid(['isbn']);

        $this->assertDatabaseCount('books', 1);

        // 自分自身の ISBN はそのままでよい
        $this->actingAs($user)
            ->put('/books/' . $book->id, $this->validBookData([
                'isbn' => '9784000000001',
                'title' => '更新後のタイトル',
            ]))
            ->assertValid()
            ->assertRedirect(route('books.show', $book));

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'title' => '更新後のタイトル',
            'isbn' => '9784000000001',
        ]);
    }

    /**
     * 画像URLがURLの形式でないと書籍は登録できない。
     *
     * 前提: ログイン済みユーザー1人
     * 操作: image_url に URL ではない文字列を入れて POST /books
     * 期待: image_url にエラー / books は0冊のまま
     *
     * image_url は nullable なので「空でも通る」が正しい。
     * つまりこの項目は「入っているときだけ形式を見る」という仕様で、
     * 空のときに通ることは必須項目のテストで確認済み。
     * ここでは入っている場合だけを見ている。
     */
    public function test_画像URLがURL形式でないと書籍は登録できない(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/books', $this->validBookData(['image_url' => 'これはURLではない']))
            ->assertInvalid(['image_url']);

        $this->assertDatabaseCount('books', 0);
    }

    /**
     * 評価が1〜5の範囲外、またはコメントが空だとレビューは投稿できない。
     *
     * 前提: ログイン済みユーザー1人、書籍1冊
     * 操作: rating 0 / rating 6 / comment 空 の3パターンで POST
     * 期待: それぞれ該当キーにエラー / reviews は0行のまま
     *
     * 0 と 6 を選んでいる理由:
     * min:1 max:5 の境界のすぐ外側だから。3や10のような
     * 明らかに外れた値を使うと、境界が min:0 にずれていても通ってしまう。
     * ちょうど1つ外側を突くと、境界のずれをそのまま検出できる。
     */
    public function test_評価が範囲外かコメントが空だとレビューは投稿できない(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        // 下限の1つ外
        $this->actingAs($user)
            ->post('/books/' . $book->id . '/reviews', ['rating' => 0, 'comment' => '正しいコメント'])
            ->assertInvalid(['rating']);

        // 上限の1つ外
        $this->actingAs($user)
            ->post('/books/' . $book->id . '/reviews', ['rating' => 6, 'comment' => '正しいコメント'])
            ->assertInvalid(['rating']);

        // コメントが空
        $this->actingAs($user)
            ->post('/books/' . $book->id . '/reviews', ['rating' => 3, 'comment' => ''])
            ->assertInvalid(['comment']);

        $this->assertDatabaseCount('reviews', 0);
        // 投稿が止まっているので、book_users も作られていないこと
        $this->assertDatabaseCount('book_users', 0);
    }

    /**
     * ジャンル名は重複できないが、自分自身の名前なら更新できる。
     *
     * 前提: 「小説」というジャンルが1件
     * 操作1: 同じ「小説」で POST /genres
     * 操作2: そのジャンルを名前を変えずに PUT /genres/{genre}/edit
     * 期待1: name にエラー / genres は1件のまま
     * 期待2: エラーにならず、一覧へリダイレクト
     *
     * 書籍のISBNと同じ構造の検証。GenreRequest も編集時だけ
     * unique に自分の id を渡して除外している。
     * 名前を変えずに保存する操作は実際に起こりうるので、
     * ここが通ることまでを仕様として固定しておく。
     */
    public function test_ジャンル名は重複できないが自分自身の名前なら更新できる(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create(['name' => '小説']);

        // 他のジャンルと同じ名前では登録できない
        $this->actingAs($user)
            ->post('/genres', ['name' => '小説'])
            ->assertInvalid(['name']);

        $this->assertDatabaseCount('genres', 1);

        // 自分自身の名前はそのままでよい
        $this->actingAs($user)
            ->put('/genres/' . $genre->id . '/edit', ['name' => '小説'])
            ->assertValid()
            ->assertRedirect(route('genres.index'));

        $this->assertDatabaseHas('genres', ['id' => $genre->id, 'name' => '小説']);
    }
}
