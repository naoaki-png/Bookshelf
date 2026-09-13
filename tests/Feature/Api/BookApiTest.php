<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookUser;
use App\Models\Favorite;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 書籍 API(/api/v1/books)のテスト。
 *
 * 【全体の設計方針】
 *
 * 1. 認証は Sanctum::actingAs() ではなく実トークンで行う。
 *    actingAs() を使えば1行で認証を通せるが、それでは
 *    「Bearer トークン(Authorization ヘッダ)による認証方式を採用すること」
 *    という要件そのものを素通りしてしまう。要件が方式を名指ししている以上、
 *    発行 → ヘッダ付与 → 認証 の実経路を通す。→ bearer()
 *
 * 2. リクエストは $this->json() 系で送る。
 *    これらは Accept: application/json を自動で付ける。このヘッダが無いと
 *    Laravel は未認証を 401 ではなくログイン画面への 302 で返し、
 *    バリデーション失敗も 422 ではなくリダイレクトになる。
 *    「JSON API のクライアントは Accept を送る」という前提を置いた上で、
 *    ヘッダ無しの挙動は要件の対象外として扱っている。
 *
 * 3. 同じ答えを返す検証は @dataProvider でまとめる。
 *    401 が3エンドポイント、403 が2エンドポイント、404 が3エンドポイントあるが、
 *    確かめている内容は各1種類しかない。エンドポイントをデータにすれば
 *    「検証は複数回走るのに、読むコードは1つ」にできる。
 *    データプロバイダは DB 構築より前に実行されるためモデルを作れない。
 *    そのため URI は {id} を含むテンプレートで渡し、テスト側で差し替えている。
 */
class BookApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/books';

    /**
     * 重複チェック用に、あらかじめ使用済みにしておく ISBN。
     * データプロバイダからも参照するため定数にしている。
     */
    private const EXISTING_ISBN = '9789999999999';

    /**
     * 実際にトークンを発行して Authorization ヘッダを組み立てる。
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test-token')->plainTextToken];
    }

    /**
     * 登録・更新で使う「バリデーションを通る」ペイロード。
     * 各テストはここから必要な項目だけ上書きする。
     * 正常系の値を毎回書き直すと、どこを不正にしたのかが読み取れなくなるため。
     */
    private function validPayload(int $genreId, array $overrides = []): array
    {
        return array_merge([
            'title' => '正しいタイトル',
            'author' => '正しい著者',
            'isbn' => '9784111111111',
            'description' => '説明文',
            'published_date' => '2024-05-01',
            'image_url' => 'https://example.com/cover.jpg',
            'genres' => [$genreId],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // 1. 書籍一覧
    // ------------------------------------------------------------------

    /**
     * 読み取りは公開、という仕様判断の確認。
     * 書き込みだけを auth:sanctum で囲っているため、一覧は素通りできる。
     */
    public function test_未認証でも書籍一覧を取得できる(): void
    {
        Book::factory()->create();

        $this->getJson(self::BASE)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * 要件「各書籍にはジャンル情報、平均評価、レビュー件数を含めること」の確認。
     *
     * 評価 3 と 5 の2件を付けて平均 4 を作る。平均値は SQLite が float と string の
     * どちらで返すか環境差があるため、型まで見る assertJsonPath ではなく
     * 値だけを比較する assertEquals で確かめている。
     */
    public function test_一覧にジャンル_平均評価_レビュー件数が含まれる(): void
    {
        $genre = Genre::factory()->create(['name' => '技術書']);
        $book = Book::factory()->create();
        $book->genres()->attach($genre);

        foreach ([3, 5] as $rating) {
            $bookUser = BookUser::factory()->create(['book_id' => $book->id]);
            Review::factory()->create(['book_user_id' => $bookUser->id, 'rating' => $rating]);
        }

        $response = $this->getJson(self::BASE);

        $response->assertOk()
            ->assertJsonPath('data.0.genres', ['技術書'])
            ->assertJsonPath('data.0.reviews_count', 2);

        $this->assertEquals(4, $response->json('data.0.reviews_avg_rating'));
    }

    /**
     * ヒットする本とヒットしない本を両方置く。
     * 「対象が返ること」だけでは絞り込みの証明にならず、
     * 「対象外が返らないこと」と対で見て初めて確定する。
     *
     * タイトル側と著者側を1本のテストで両方見ている理由:
     * 機能要件は「キーワードに部分一致する書籍(title または author)」であり、
     * 2つの仕様ではなく1つの OR 条件で1つの仕様。分けて書くと、
     * title と author を AND で繋いだ実装(両方に含まれる本しか出ない)が
     * どちらのテストでも落ちるのに、原因がどちらとも読めなくなる。
     *
     * 著者でヒットさせる本のタイトルにキーワードを入れていないのは、
     * title 側の条件だけで拾えてしまうと author 側を検証したことに
     * ならないため。
     *
     * 件名の一致は pluck して assertContains で見ている。
     * 並び順を指定していないので data の添字は保証されない。
     */
    public function test_キーワードはタイトルと著者のどちらにも部分一致する(): void
    {
        Book::factory()->create(['title' => 'Laravel入門', 'author' => '田中太郎']);
        Book::factory()->create(['title' => 'やさしい入門書', 'author' => 'Laravel 太郎']);
        Book::factory()->create(['title' => 'Python入門', 'author' => '鈴木花子']);

        $response = $this->getJson(self::BASE . '?' . http_build_query(['keyword' => 'Laravel']))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Laravel入門', $titles);
        $this->assertContains('やさしい入門書', $titles);
    }

    /**
     * 0件のときにページネーションが壊れないことの確認。
     * total が 0 になる経路は、ここでしか通らない。
     */
    public function test_ヒットしないキーワードでは空の一覧が返る(): void
    {
        Book::factory(3)->create();

        $this->getJson(self::BASE . '?' . http_build_query(['keyword' => '該当なし']))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * ジャンル絞り込みは genre_id(ジャンルの id)で受ける。
     *
     * もともと API だけが genre(ジャンル名)を受けていたが、Web 画面側は
     * 最初から id で絞る作り(BookIndexRequest の exists:genres,id)で、
     * 評価基準も genre_id を指している。#86 で Web 側に揃え、
     * 絞り込み自体も Book::scopeOfGenre() を共用する形にした。
     */
    public function test_genre_idでジャンルを絞り込める(): void
    {
        $target = Genre::factory()->create(['name' => '技術書']);
        $other = Genre::factory()->create(['name' => '小説']);

        $hit = Book::factory()->create(['title' => '対象の本']);
        $hit->genres()->attach($target);

        $miss = Book::factory()->create(['title' => '対象外の本']);
        $miss->genres()->attach($other);

        $this->getJson(self::BASE . '?' . http_build_query(['genre_id' => $target->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', '対象の本');
    }

    /**
     * per_page の指定が効くことの確認。
     * meta.per_page はクエリ文字列由来で string になる場合があるため、
     * 型が安定している data の件数と meta.total で確かめる。
     */
    public function test_per_pageで1ページの件数を変えられる(): void
    {
        Book::factory(15)->create();

        $this->getJson(self::BASE . '?' . http_build_query(['per_page' => 5]))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 15);
    }

    /**
     * per_page を指定しないときの1ページは20件。
     *
     * 既定値はコントローラーの input('per_page', 20) の第2引数にしか無く、
     * 指定ありのテストだけでは 10 に戻されても気づけない。
     * 21件用意して「返るのは20件、total は21」を見ることで、
     * 「たまたま全件が20件以下だった」状態と区別している。
     *
     * meta.per_page を見ないのは、クエリ文字列由来で型が安定しないため
     * (per_page 指定のテストと同じ理由)。
     */
    public function test_per_pageを指定しないと1ページ20件になる(): void
    {
        Book::factory(21)->create();

        $this->getJson(self::BASE)
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 21);
    }

    /**
     * 一覧はジャンルを eager load していて、書籍が増えてもクエリ本数が変わらない。
     *
     * BookIndexResource は書籍ごとにジャンルを出力するため、with('genres') が
     * 外れると1冊につき1本ずつ SELECT が増える(N+1)。
     * 返る JSON は同じなので、レスポンスだけを見るテストでは検出できない。
     * 実際に #86 の作業中に with('genres') が一度落ちている。
     *
     * 総クエリ数ではなく genres への SELECT だけを数えているのは、
     * 書籍数が変われば books 側のクエリの中身も変わるため。
     * 見たいのは「関連を読むクエリが件数に比例して増えるか」だけ。
     *
     * 3冊と9冊で比べているのは、1冊だと N+1 でも1本になり
     * eager load と区別が付かないため。
     */
    public function test_一覧のジャンル取得はN1にならない(): void
    {
        $genre = Genre::factory()->create();
        Book::factory(3)->create()->each(fn (Book $book) => $book->genres()->attach($genre));

        DB::enableQueryLog();

        DB::flushQueryLog();
        $this->getJson(self::BASE)->assertOk();
        $withThreeBooks = $this->genreSelectCount();

        Book::factory(6)->create()->each(fn (Book $book) => $book->genres()->attach($genre));

        DB::flushQueryLog();
        $this->getJson(self::BASE)->assertOk();
        $withNineBooks = $this->genreSelectCount();

        $this->assertSame($withThreeBooks, $withNineBooks);
    }

    /**
     * genres テーブルへの SELECT が何本飛んだかを数える。
     */
    private function genreSelectCount(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains($query['query'], 'from "genres"'))
            ->count();
    }

    /**
     * 存在しないジャンルIDを 422 にするのは意図した仕様。
     * ApiBookIndexRequest が exists:genres,id で検証しているため、
     * 「該当なしの空 200」ではなくバリデーションエラーになる。
     *
     * @dataProvider 一覧の不正なクエリ
     */
    public function test_一覧の不正なクエリはバリデーションエラーになる(array $query, string $errorKey): void
    {
        Genre::factory()->create(['name' => '技術書']);

        $this->getJson(self::BASE . '?' . http_build_query($query))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$errorKey]);
    }

    public static function 一覧の不正なクエリ(): array
    {
        return [
            'per_pageが上限超え' => [['per_page' => 101], 'per_page'],
            '存在しないジャンルID' => [['genre_id' => 999], 'genre_id'],
            'pageが0' => [['page' => 0], 'page'],
        ];
    }

    // ------------------------------------------------------------------
    // 2. 書籍詳細
    // ------------------------------------------------------------------

    /**
     * 要件「ジャンル情報とレビュー(投稿者名・評価・コメント・投稿日時)を含めること」の確認。
     *
     * レビューは book_users を経由した hasManyThrough で取っており、
     * 投稿者名は Review::user()(hasOneThrough)からさらに引いている。
     * 中間テーブルを2つ跨いで名前まで届くことを、ここで一度だけ通しで確かめる。
     */
    public function test_詳細にジャンルとレビューが含まれる(): void
    {
        $genre = Genre::factory()->create(['name' => '技術書']);
        $reviewer = User::factory()->create(['name' => 'レビュー投稿者']);

        $book = Book::factory()->create();
        $book->genres()->attach($genre);

        $bookUser = BookUser::factory()->create(['book_id' => $book->id, 'user_id' => $reviewer->id]);
        Review::factory()->create([
            'book_user_id' => $bookUser->id,
            'rating' => 4,
            'comment' => 'おもしろかった',
        ]);

        $this->getJson(self::BASE . '/' . $book->id)
            ->assertOk()
            ->assertJsonPath('data.genres', ['技術書'])
            ->assertJsonPath('data.reviews.0.user', 'レビュー投稿者')
            ->assertJsonPath('data.reviews.0.rating', 4)
            ->assertJsonPath('data.reviews.0.comment', 'おもしろかった')
            ->assertJsonStructure(['data' => ['reviews' => [['created_at']]]]);
    }

    /**
     * ISBN と出版日は任意項目(テンプレートの入力欄にも必須マークが無い)。
     *
     * BookShowResource は published_date に対して format() を呼ぶため、
     * null 安全に書けていないと、ここが 200 ではなく 500 になる。
     * 「任意項目が空でも詳細が壊れない」ことの確認であると同時に、
     * ?-> を外したら落ちる回帰テストでもある。
     */
    public function test_ISBNと出版日が空の書籍でも詳細を取得できる(): void
    {
        $book = Book::factory()->create(['isbn' => null, 'published_date' => null]);

        $this->getJson(self::BASE . '/' . $book->id)
            ->assertOk()
            ->assertJsonPath('data.isbn', null)
            ->assertJsonPath('data.published_date', null);
    }

    // ------------------------------------------------------------------
    // 3. 書籍登録
    // ------------------------------------------------------------------

    /**
     * 登録の正常系。ステータス・所有者・ジャンル紐付けを1回のセットアップで確かめる。
     *
     * user_id の確認が要点。登録者を books.user_id に入れておかないと、
     * 後段の BookPolicy(所有者のみ更新・削除可)が誰も通さなくなる。
     */
    public function test_認証済みユーザーは書籍を登録できる(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $this->postJson(self::BASE, $this->validPayload($genre->id, ['title' => '新しい本']), $this->bearer($user))
            ->assertCreated()
            ->assertJsonPath('data.title', '新しい本');

        $this->assertDatabaseHas('books', [
            'title' => '新しい本',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('book_genres', [
            'book_id' => Book::where('title', '新しい本')->value('id'),
            'genre_id' => $genre->id,
        ]);
    }

    /**
     * ISBN と出版日を省略しても登録できることの確認。
     *
     * バリデーションが nullable であることと、レスポンス生成(BookShowResource)が
     * null を扱えることの両方を通る。どちらかが崩れると 422 か 500 になり、
     * 「作成成功 201」という要件を満たさなくなる。
     */
    public function test_ISBNと出版日を省略して登録できる(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $this->postJson(self::BASE, [
            'title' => '任意項目なしの本',
            'author' => '著者',
            'genres' => [$genre->id],
        ], $this->bearer($user))
            ->assertCreated()
            ->assertJsonPath('data.isbn', null)
            ->assertJsonPath('data.published_date', null);
    }

    /**
     * 要件「バリデーションエラー時は日本語のエラーメッセージを返すこと」の確認。
     *
     * 期待するメッセージまで assert しているのが要点。ステータス 422 だけを見ると、
     * 英語のデフォルトメッセージが返っていても通ってしまい、要件を検証できない。
     *
     * 重複チェック用の書籍は全ケースで作る。ケースごとに前準備を分けると
     * 「どのケースが何を必要としているか」が読み取りにくくなるため、
     * 前提は揃えて、変わるのは上書きする値だけ、という形にしている。
     *
     * @dataProvider 登録の不正な入力
     */
    public function test_登録のバリデーションエラーは日本語で返る(array $overrides, string $errorKey, string $message): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();
        Book::factory()->create(['isbn' => self::EXISTING_ISBN]);

        $this->postJson(self::BASE, $this->validPayload($genre->id, $overrides), $this->bearer($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$errorKey => $message]);
    }

    public static function 登録の不正な入力(): array
    {
        return [
            'タイトル未入力' => [['title' => ''], 'title', 'タイトルは必須項目です。'],
            'ISBNが12桁' => [['isbn' => '123456789012'], 'isbn', 'ISBN-13は13桁の整数で入力してください。'],
            'ISBNが重複' => [['isbn' => self::EXISTING_ISBN], 'isbn', 'このISBN-13は既に使用されています。'],
            'ジャンルが配列でない' => [['genres' => '技術書'], 'genres', 'ジャンルは配列形式で送信してください。'],
            '存在しないジャンルID' => [['genres' => [999999]], 'genres.0', '選択されたジャンルは存在しません。'],
        ];
    }

    // ------------------------------------------------------------------
    // 4. 書籍更新
    // ------------------------------------------------------------------

    public function test_所有者は書籍を更新できる(): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        $genre = Genre::factory()->create();

        $this->putJson(
            self::BASE . '/' . $book->id,
            $this->validPayload($genre->id, ['title' => '更新後のタイトル']),
            $this->bearer($owner)
        )->assertOk()->assertJsonPath('data.title', '更新後のタイトル');

        $this->assertDatabaseHas('books', ['id' => $book->id, 'title' => '更新後のタイトル']);
    }

    /**
     * 要件「ISBNの一意性チェックでは自身を除外」の確認。
     *
     * タイトルだけ直したいときに ISBN をそのまま送り返すのは自然な使い方で、
     * 除外指定(unique:books,isbn,{id})が無いと自分自身と衝突して 422 になる。
     */
    public function test_自分のISBNをそのまま送っても更新できる(): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id, 'isbn' => self::EXISTING_ISBN]);
        $genre = Genre::factory()->create();

        $this->putJson(
            self::BASE . '/' . $book->id,
            $this->validPayload($genre->id, ['isbn' => self::EXISTING_ISBN, 'title' => 'タイトルだけ変更']),
            $this->bearer($owner)
        )->assertOk();
    }

    /**
     * 要件「バリデーションルールは書籍登録と同等」の確認。
     *
     * 更新のルールは分岐の中で isbn の配列ごと差し替えているため、
     * 登録側だけを nullable にすると、更新時だけ必須に戻るというズレが起きる。
     * ISBN 無しで登録した本が更新できなくなるので、実害のある形で現れる。
     */
    public function test_ISBNを省略して更新できる(): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id, 'isbn' => null]);
        $genre = Genre::factory()->create();

        $this->putJson(self::BASE . '/' . $book->id, [
            'title' => 'ISBNなしのまま更新',
            'author' => '著者',
            'genres' => [$genre->id],
        ], $this->bearer($owner))->assertOk();
    }

    /**
     * 自身は除外するが、他人との重複は弾く。
     * 直前のテストと対で見て初めて「除外が効きすぎていない」ことが確定する。
     */
    public function test_他人のISBNと重複する更新はエラーになる(): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        Book::factory()->create(['isbn' => self::EXISTING_ISBN]);
        $genre = Genre::factory()->create();

        $this->putJson(
            self::BASE . '/' . $book->id,
            $this->validPayload($genre->id, ['isbn' => self::EXISTING_ISBN]),
            $this->bearer($owner)
        )->assertStatus(422)
            ->assertJsonValidationErrors(['isbn' => 'このISBN-13は既に使用されています。']);
    }

    // ------------------------------------------------------------------
    // 5. 書籍削除
    // ------------------------------------------------------------------

    /**
     * 要件「関連データ(レビュー・お気に入り・ジャンル紐付け)も適切に処理されること」の確認。
     *
     * 関連の削除は DB の ON DELETE CASCADE が担っている。
     * reviews は books を直接参照しておらず、books → book_users → reviews と
     * 2段でカスケードするため、そこまで届くかを実データで確かめる必要がある。
     *
     * 巻き添えになってはいけない書籍を1冊置いているのが要点。
     * 「消えたこと」だけを見ると、全部消す実装でもテストは通ってしまう。
     * 残るべき行を置いて初めて「消すべきものだけ消えた」と言える。
     */
    public function test_所有者が削除すると関連データも消える(): void
    {
        $owner = User::factory()->create();
        $genre = Genre::factory()->create();

        $book = Book::factory()->create(['user_id' => $owner->id]);
        $book->genres()->attach($genre);
        $bookUser = BookUser::factory()->create(['book_id' => $book->id, 'user_id' => $owner->id]);
        $review = Review::factory()->create(['book_user_id' => $bookUser->id]);
        $favorite = Favorite::factory()->create(['book_id' => $book->id, 'user_id' => $owner->id]);

        $survivor = Book::factory()->create(['user_id' => $owner->id]);
        $survivor->genres()->attach($genre);
        $survivorBookUser = BookUser::factory()->create(['book_id' => $survivor->id, 'user_id' => $owner->id]);
        $survivorReview = Review::factory()->create(['book_user_id' => $survivorBookUser->id]);
        $survivorFavorite = Favorite::factory()->create(['book_id' => $survivor->id, 'user_id' => $owner->id]);

        $this->deleteJson(self::BASE . '/' . $book->id, [], $this->bearer($owner))
            ->assertNoContent();

        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('book_genres', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('book_users', ['id' => $bookUser->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseMissing('favorites', ['id' => $favorite->id]);

        $this->assertDatabaseHas('books', ['id' => $survivor->id]);
        $this->assertDatabaseHas('book_genres', ['book_id' => $survivor->id]);
        $this->assertDatabaseHas('book_users', ['id' => $survivorBookUser->id]);
        $this->assertDatabaseHas('reviews', ['id' => $survivorReview->id]);
        $this->assertDatabaseHas('favorites', ['id' => $survivorFavorite->id]);
    }

    // ------------------------------------------------------------------
    // 6. 認証・認可・存在しないID(型でまとめる)
    // ------------------------------------------------------------------

    /**
     * 要件「未認証時のHTTPステータスコードを適切に設定すること」の確認。
     *
     * 既存の書籍を対象にしているのが要点。存在しないIDを使うと
     * SubstituteBindings が auth:sanctum より先に走るため 404 が返り、
     * 認証を確かめたつもりが別のものを確かめることになる。
     *
     * @dataProvider 書き込みエンドポイント
     */
    public function test_未認証では書き込み系エンドポイントを使えない(string $method, string $uriTemplate): void
    {
        $book = Book::factory()->create(['user_id' => User::factory()]);

        $this->json($method, str_replace('{id}', (string) $book->id, $uriTemplate))
            ->assertUnauthorized();
    }

    public static function 書き込みエンドポイント(): array
    {
        return [
            '登録' => ['POST', '/api/v1/books'],
            '更新' => ['PUT', '/api/v1/books/{id}'],
            '削除' => ['DELETE', '/api/v1/books/{id}'],
        ];
    }

    /**
     * 要件「認可エラー時のHTTPステータスコードを適切に設定すること」と、
     * BookPolicy が Response::deny() で返す日本語メッセージの確認。
     *
     * 送るペイロードは正常な値にしてある。更新は FormRequest の検証が
     * コントローラ本体より先に走るため、不正な値を混ぜると 403 ではなく
     * 422 が返り、認可を確かめられなくなる。
     *
     * 最後に書籍が変わっていないことも見る。403 が返っていても
     * 処理が進んでいれば意味がないため。
     *
     * @dataProvider 所有者限定エンドポイント
     */
    public function test_他人の書籍は更新も削除もできない(string $method, string $uriTemplate, string $message): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        $genre = Genre::factory()->create();

        $this->json(
            $method,
            str_replace('{id}', (string) $book->id, $uriTemplate),
            $this->validPayload($genre->id, ['title' => '奪おうとしたタイトル']),
            $this->bearer($other)
        )->assertForbidden()->assertJsonPath('message', $message);

        $this->assertDatabaseHas('books', ['id' => $book->id, 'title' => $book->title]);
    }

    public static function 所有者限定エンドポイント(): array
    {
        return [
            '更新' => ['PUT', '/api/v1/books/{id}', '他の人が登録した書籍は更新できません。'],
            '削除' => ['DELETE', '/api/v1/books/{id}', '他の人が登録した書籍は削除できません。'],
        ];
    }

    /**
     * 要件「存在しないIDが指定された場合はエラーレスポンスを返すこと」の確認。
     * ルートモデルバインディングが 404 を投げるため、コントローラ側に記述は要らない。
     *
     * @dataProvider 単一書籍を指すエンドポイント
     */
    public function test_存在しないIDはNotFoundになる(string $method): void
    {
        $user = User::factory()->create();

        $this->json($method, self::BASE . '/999999', [], $this->bearer($user))
            ->assertNotFound();
    }

    public static function 単一書籍を指すエンドポイント(): array
    {
        return [
            '詳細' => ['GET'],
            '更新' => ['PUT'],
            '削除' => ['DELETE'],
        ];
    }
}
