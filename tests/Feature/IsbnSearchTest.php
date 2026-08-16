<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ISBN 検索(GET /books/isbn/{isbn})のテスト。
 *
 * 【全体の設計方針】
 *
 * 1. 期待値は assertExactJson で固定する。
 *    このエンドポイントを読むのはブラウザではなく create/edit の JS で、
 *    JS は「data.error があればエラー、無ければ成功」としか見ていない。
 *    つまり "返る箱にどのキーが入っているか" が仕様そのものになる。
 *    assertJson だと余計なキーが増えても気づけないので、
 *    キーの集合ごと固定できる assertExactJson を使う。
 *
 * 2. エラー時は {"error": "..."} 以外の形になっていないことを確かめる。
 *    FormRequest の既定は {"message": ..., "errors": {...}} を返す。
 *    この形だと JS は data.error を見つけられず「エラーではない」と判断し、
 *    フォームを空文字で上書きして「書籍情報を取得しました。」と表示する。
 *    失敗が成功に見える形なので、error キーだけが返ることを明示的に固定する。
 *    (IsbnSearchRequest::failedValidation() が消えた瞬間に落ちる)
 *
 * 3. setUp で Http::preventStrayRequests() を必ず呼ぶ。
 *    fake し忘れた通信は、無いと本当に Google に飛ぶ。
 *    テストのたびに外部を叩き、しかも気づけない。
 *    「fake を書き忘れたら例外で止まる」状態にしておく。
 *
 * 4. 検証データは volumeInfo だけを差し替える。
 *    Google のレスポンスは totalItems / items[0].volumeInfo という
 *    入れ子で、毎回全部書くと何を試しているのかが埋もれる。
 *    google応答() で外側を固定し、テスト側は volumeInfo だけ書く。
 *
 * 5. 未ログインのケースは 401 を期待する。
 *    ルートが auth グループの中にあるため。JSON リクエスト
 *    (Accept: application/json)なのでリダイレクトではなく 401 になる。
 */
class IsbnSearchTest extends TestCase
{
    use RefreshDatabase;

    /** テストで使い回す、DB に無い正常な ISBN。 */
    private const 未登録のISBN = '9784101010014';

    protected function setUp(): void
    {
        parent::setUp();

        // fake し忘れた通信を本当に外へ出さない
        Http::preventStrayRequests();
    }

    /**
     * ログイン済みユーザーとして ISBN 検索を叩く。
     */
    private function 検索(string $isbn)
    {
        return $this->actingAs(User::factory()->create())
            ->getJson('/books/isbn/' . $isbn);
    }

    /**
     * Google Books が「1件見つかった」と返す状況を作る。
     *
     * テスト側は volumeInfo の中身だけを渡せばよい。
     */
    private function google応答(array $volumeInfo): void
    {
        Http::fake(['*' => Http::response([
            'kind' => 'books#volumes',
            'totalItems' => 1,
            'items' => [['volumeInfo' => $volumeInfo]],
        ], 200)]);
    }

    /**
     * 検証① 13桁でない ISBN は、API を呼ぶ前に弾かれる。
     *
     * 「弾かれること」だけでなく「Google を呼んでいないこと」も見る。
     * バリデーションを通してから呼ぶ設計にした以上、順番が逆になっても
     * 画面上は同じに見えてしまう(結局エラーが出るので)。
     * 無駄な外部通信が発生していないことは、ここでしか固定できない。
     */
    public function test_13桁でないISBNは弾かれGoogleを呼ばない(): void
    {
        Http::fake();

        $response = $this->検索('123');

        $response->assertStatus(422);
        $response->assertExactJson(['error' => 'ISBNを確認してください。']);
        Http::assertNothingSent();
    }

    /**
     * 検証② 既に登録済みの ISBN も、API を呼ぶ前に弾かれる。
     *
     * unique:books,isbn による分岐。①と同じ文言・同じステータスになる
     * (エラー文言を固定文字列にすると決めたため)。
     * 利用者から見て①と②が区別できないのは意図した仕様なので、
     * 「同じ文言が返る」ことをここで明示しておく。
     */
    public function test_登録済みのISBNは弾かれGoogleを呼ばない(): void
    {
        Http::fake();
        Book::factory()->create(['isbn' => '9784101010021']);

        $response = $this->検索('9784101010021');

        $response->assertStatus(422);
        $response->assertExactJson(['error' => 'ISBNを確認してください。']);
        Http::assertNothingSent();
    }

    /**
     * 検証③ Google がエラーステータスを返したら 502。
     *
     * 【このテストが重要】
     * Http::get() は 4xx / 5xx でも例外を投げない。$response->failed() で
     * 自分で聞かない限り、エラー本文がそのまま素通りする。
     * 実際、実装の初期は Google の 429 をそのまま 200 で返していた。
     * しかも Google のエラー本文には "error" キーがあるため、JS は
     * エラーと判定しつつ中身がオブジェクトなので [object Object] と表示する。
     * failed() のチェックが外れた瞬間にこのテストが落ちる。
     */
    public function test_Googleがエラーを返したら502を返す(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 429]], 429)]);

        $response = $this->検索(self::未登録のISBN);

        $response->assertStatus(502);
        $response->assertExactJson([
            'error' => '書籍情報の取得に失敗しました。時間をおいて再度お試しください。',
        ]);
    }

    /**
     * 検証④ 通信そのものが失敗したら 502。
     *
     * ③とは捕まえ方が違う。③は「返事は来たが中身が異常」なので
     * failed() で聞けるが、こちらは返事が来ないので $response 自体が
     * 存在せず、ConnectionException が投げられる。
     * try/catch が無いと 500 になり、JS には「通信エラーが発生しました。」
     * としか出ない。片方だけ実装しても、もう片方は普段は表に出ない。
     */
    public function test_通信に失敗したら502を返す(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $response = $this->検索(self::未登録のISBN);

        $response->assertStatus(502);
        $response->assertExactJson([
            'error' => '書籍情報の取得に失敗しました。時間をおいて再度お試しください。',
        ]);
    }

    /**
     * 検証⑤ 該当する書籍が無ければ 404。
     *
     * Google Books は見つからない場合も 200 を返し、totalItems が 0 で
     * items キー自体が存在しない。ステータスだけ見ていると成功と判断して
     * items[0] を掴みに行き、全項目が null のまま 200 で返ってしまう。
     * その状態でも JS は成功側に入るため、フォームが空で上書きされ
     * 「書籍情報を取得しました。」と表示される。
     */
    public function test_該当する書籍が無ければ404を返す(): void
    {
        Http::fake(['*' => Http::response([
            'kind' => 'books#volumes',
            'totalItems' => 0,
        ], 200)]);

        $response = $this->検索(self::未登録のISBN);

        $response->assertStatus(404);
        $response->assertExactJson(['error' => '該当する書籍が見つかりませんでした。']);
    }

    /**
     * 検証⑥ 取得できたら、フォームが期待する5項目に詰め替えて返す。
     *
     * Google のキー名(authors / publishedDate / imageLinks.thumbnail)と
     * こちらが返すキー名(author / published_date / image_url)は別物で、
     * 揃っているほうが偶然。詰め替えを丸ごと固定する。
     */
    public function test_取得できた書籍情報をフォームの項目に詰め替えて返す(): void
    {
        $this->google応答([
            'title' => 'ノルウェイの森',
            'authors' => ['村上 春樹', '共著者'],
            'publishedDate' => '2004-09',
            'description' => '説明文',
            'imageLinks' => ['thumbnail' => 'http://books.google.com/books/content?id=X'],
        ]);

        $response = $this->検索(self::未登録のISBN);

        $response->assertOk();
        $response->assertExactJson([
            'title' => 'ノルウェイの森',
            'author' => '村上 春樹,共著者',
            'description' => '説明文',
            'image_url' => 'https://books.google.com/books/content?id=X',
            'published_date' => '2004-09-01',
        ]);
    }

    /**
     * 検証⑦ 出版日は粒度が粗くても日まで補完される。
     *
     * Google の publishedDate は "2004" / "2004-09" / "2004-09-01" の
     * 3通りで返ってくる。フォームの published_date は date 型の入力なので、
     * YYYY-MM-DD に揃えてから渡す。
     *
     * @dataProvider 出版日と補完後の値
     */
    public function test_出版日は日まで補完して返す(string $返ってくる値, string $期待する値): void
    {
        $this->google応答([
            'title' => '本',
            'publishedDate' => $返ってくる値,
        ]);

        $response = $this->検索(self::未登録のISBN);

        $response->assertOk();
        $this->assertSame($期待する値, $response->json('published_date'));
    }

    public static function 出版日と補完後の値(): array
    {
        return [
            '年だけ' => ['2004', '2004-01-01'],
            '年月' => ['2004-09', '2004-09-01'],
            '年月日はそのまま' => ['2004-09-01', '2004-09-01'],
        ];
    }

    /**
     * 検証⑧ サムネイルの URL は https に直す。ただし先頭だけ。
     *
     * Google のサムネイルは http:// で返る。アプリは https で配信されるため
     * そのまま埋めると混在コンテンツになる。
     *
     * 「先頭が http:// のときだけ」と決めたので、既に https のものは
     * 触らない。また URL のクエリ文字列の中に http:// が現れても
     * 巻き込まない(3件目)。
     *
     * @dataProvider サムネイルのURLと変換後の値
     */
    public function test_サムネイルのURLは先頭のhttpだけhttpsに直す(string $返ってくる値, string $期待する値): void
    {
        $this->google応答([
            'title' => '本',
            'imageLinks' => ['thumbnail' => $返ってくる値],
        ]);

        $response = $this->検索(self::未登録のISBN);

        $response->assertOk();
        $this->assertSame($期待する値, $response->json('image_url'));
    }

    public static function サムネイルのURLと変換後の値(): array
    {
        return [
            'httpは直す' => [
                'http://books.google.com/books/content?id=X',
                'https://books.google.com/books/content?id=X',
            ],
            '既にhttpsなら触らない' => [
                'https://books.google.com/books/content?id=X',
                'https://books.google.com/books/content?id=X',
            ],
            '途中のhttpは巻き込まない' => [
                'https://books.google.com/redirect?to=http://example.com',
                'https://books.google.com/redirect?to=http://example.com',
            ],
        ];
    }

    /**
     * 検証⑨ 情報が欠けている本でも、キーは5つ揃って返る。
     *
     * description が無い本、imageLinks ごと無い本、authors が無い本は
     * 普通にある。JS は data.title || '' で受けるので値が空でも構わないが、
     * キーが欠けたり、途中で 500 になったりしてはいけない。
     *
     * authors が無いときに implode へ null を渡すと TypeError で落ちる、
     * publishedDate が無いときに strlen へ null を渡すと Deprecation が出る
     * (しかも Laravel はこれをログに回すのでテストには現れない)。
     * どちらも「?? [] / ?? ''」で受けているかどうかで決まる。
     */
    public function test_情報が欠けている本でもキーは揃って返る(): void
    {
        $this->google応答(['title' => '情報の少ない本']);

        $response = $this->検索(self::未登録のISBN);

        $response->assertOk();
        $response->assertExactJson([
            'title' => '情報の少ない本',
            'author' => '',
            'description' => null,
            'image_url' => '',
            'published_date' => '',
        ]);
    }

    /**
     * 検証⑩ Google Books へは、ISBN 条件付きで1回だけ問い合わせる。
     *
     * 問い合わせ先と条件はサーバー側の裁量なので、画面を見ても分からない。
     * q=isbn:{isbn} の形が崩れると、ISBN 以外の本が引っかかっても
     * 「1件目」を掴んで返してしまい、別の本の情報でフォームが埋まる。
     */
    public function test_GoogleBooksへはISBN条件で1回だけ問い合わせる(): void
    {
        $this->google応答(['title' => '本']);

        $this->検索(self::未登録のISBN);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $クエリ);

            return str_starts_with($request->url(), 'https://www.googleapis.com/books/v1/volumes')
                && ($クエリ['q'] ?? null) === 'isbn:' . self::未登録のISBN;
        });
    }

    /**
     * 検証⑪ 未ログインでは使えない。
     *
     * create / edit 画面が auth の中にあるので、このエンドポイントも
     * auth の中に置いた。Accept: application/json が付いているため
     * ログイン画面へのリダイレクトではなく 401 になる。
     */
    public function test_未ログインではISBN検索を使えない(): void
    {
        Http::fake();

        $response = $this->getJson('/books/isbn/' . self::未登録のISBN);

        $response->assertStatus(401);
        Http::assertNothingSent();
    }

    /**
     * 検証⑫【既知の制約】編集中の本の ISBN を再検索すると弾かれる。
     *
     * URL(/books/isbn/{isbn})に書籍 ID が含まれないため、サーバー側で
     * 「編集中の本」と「他人の本」を区別できない。BookRequest の更新時は
     * unique:books,isbn,{id} で自分を除外しているが、ここには除外する
     * 材料が無い。URL はテンプレートが決めているので変更できない。
     *
     * 実害が小さいと判断して許容した挙動。バグとして直しに行かないよう、
     * 「意図してこうなっている」ことをテストとして残しておく。
     */
    public function test_編集中の本のISBNを再検索すると登録済みとして弾かれる(): void
    {
        Http::fake();
        $自分の本 = Book::factory()->create(['isbn' => '9784101010038']);

        $response = $this->検索($自分の本->isbn);

        $response->assertStatus(422);
        $response->assertExactJson(['error' => 'ISBNを確認してください。']);
    }
}
