<?php

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API 認証(/api/v1/login, /api/v1/logout)のテスト。
 *
 * 書籍 CRUD とファイルを分けている理由:
 * こちらは「トークンの発行と失効」という認証基盤の話で、書籍という
 * リソースの話とは寿命が違う。書籍側の仕様が変わっても認証の期待値は動かず、
 * 逆も同じなので、混ぜると変更のたびに無関係なテストを読むことになる。
 *
 * 全テストで実際のトークンを発行し Authorization ヘッダに載せる。
 * 要件が「Bearer トークン(Authorization ヘッダ)による認証方式」と
 * 方式まで指定しているため、Sanctum::actingAs() で近道すると
 * 要件そのものが未検証になる。
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = '/api/v1/login';

    private const LOGOUT = '/api/v1/logout';

    /**
     * UserFactory が password を 'password' 固定で作るため、
     * ログイン時の平文パスワードはこれを使う。
     */
    private const PASSWORD = 'password';

    /**
     * ログイン成功でトークンが返り、DB にも1本記録されることの確認。
     *
     * 平文トークンが見えるのは発行の瞬間だけで、DB にはハッシュしか入らない。
     * そのため「返ってきた文字列」と「保存された行」は直接比較できず、
     * レスポンスの構造と件数で確かめている。
     */
    public function test_正しい認証情報でトークンを発行できる(): void
    {
        User::factory()->create(['email' => 'taro@example.com']);

        $this->postJson(self::LOGIN, [
            'email' => 'taro@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type'])
            ->assertJsonPath('token_type', 'Bearer');

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /**
     * ログイン失敗のメッセージを、メール未登録とパスワード違いで分けない、
     * という仕様判断の確認。
     *
     * 分けてしまうと「このメールアドレスは登録済み」と外から判別でき、
     * アカウント列挙(存在するメールアドレスの割り出し)ができてしまう。
     * 2ケースで同じ文字列を期待しているのが、この判断そのものの表明になっている。
     *
     * トークンが発行されていないことも併せて見る。422 が返っていても
     * その手前でトークンを作っていたら意味がないため。
     *
     * @dataProvider 認証に失敗する入力
     */
    public function test_認証失敗は原因を問わず同じメッセージになる(string $email, string $password): void
    {
        User::factory()->create(['email' => 'toroku@example.com']);

        $this->postJson(self::LOGIN, ['email' => $email, 'password' => $password])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'メールアドレスまたはパスワードが間違っています。']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function 認証に失敗する入力(): array
    {
        return [
            'パスワードが違う' => ['toroku@example.com', 'wrong-password'],
            'メールアドレスが未登録' => ['unknown@example.com', self::PASSWORD],
        ];
    }

    /**
     * ログインで得たトークンで、保護された書き込みエンドポイントを通れることの確認。
     *
     * 発行 → ヘッダ付与 → 認証 → 登録 までを1本で通す唯一のテスト。
     * 個々の部品が動くことと、繋いで動くことは別なので、
     * 実際の利用手順をそのままなぞる形を1つ用意している。
     */
    public function test_発行したトークンで保護されたエンドポイントを通れる(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $token = $this->postJson(self::LOGIN, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('token');

        $this->postJson('/api/v1/books', [
            'title' => 'トークンで登録した本',
            'author' => '著者',
            'genres' => [$genre->id],
        ], ['Authorization' => 'Bearer ' . $token])
            ->assertCreated();

        $this->assertDatabaseHas('books', [
            'title' => 'トークンで登録した本',
            'user_id' => $user->id,
        ]);
    }

    /**
     * ログアウトで、使ったトークンが無効になることの確認。
     *
     * 200 が返っただけでは何も証明できない。同じトークンでもう一度叩いて
     * 401 になって初めて「消えた」と確定する。
     * DB の件数と、2回目の 401 を両方見ているのはそのため。
     *
     * forgetGuards() が必須。Sanctum が使う RequestGuard は一度解決した
     * ユーザーを保持し、以降はトークンを見ずにそれを返す。テストは
     * 同一メソッド内でコンテナを作り直さないため、これを捨てずに叩くと
     * DB からトークンが消えていても 200 が返る。
     * 「消えたことの確認」のつもりが、記憶されたユーザーを見るだけになる。
     */
    public function test_ログアウトすると使ったトークンは無効になる(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer ' . $user->createToken('test-token')->plainTextToken];

        $this->postJson(self::LOGOUT, [], $headers)->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->postJson(self::LOGOUT, [], $headers)->assertUnauthorized();
    }

    /**
     * ログアウトは「今使っているトークン1本だけ」を消す、という仕様判断の確認。
     *
     * currentAccessToken()->delete() は現在のトークンだけを消すため、
     * スマホでログアウトしても PC のログインは維持される。
     * 全端末からログアウトさせる実装(tokens()->delete())との違いが、
     * ここで残る1本によって区別される。
     *
     * 最後の1本を叩く前に forgetGuards() を挟むのは、これが無いと
     * 記憶された mobile のユーザーで認証が通ってしまい、
     * desktop のトークンが実際には消えていても 200 になるため。
     * 残った1本が「本当に使える」ことを確かめたいので、記憶を捨てて叩き直す。
     */
    public function test_ログアウトしても他のトークンは残る(): void
    {
        $user = User::factory()->create();
        $mobile = $user->createToken('mobile')->plainTextToken;
        $desktop = $user->createToken('desktop')->plainTextToken;

        $this->postJson(self::LOGOUT, [], ['Authorization' => 'Bearer ' . $mobile])
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->app['auth']->forgetGuards();

        $this->postJson(self::LOGOUT, [], ['Authorization' => 'Bearer ' . $desktop])
            ->assertOk();
    }

    /**
     * 存在しないトークンでは認証されないことの確認。
     * ログアウト後の 401 と違い、そもそも発行されたことのない文字列を弾く経路。
     */
    public function test_でたらめなトークンは認証されない(): void
    {
        $this->postJson(self::LOGOUT, [], ['Authorization' => 'Bearer this-is-not-a-valid-token'])
            ->assertUnauthorized();
    }
}
