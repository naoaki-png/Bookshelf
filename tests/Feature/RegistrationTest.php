<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 会員登録(POST /register)と、その裏で動く CreateNewUser を確認する。
 *
 * 既存の AuthRedirectTest は「未ログインだと /login に飛ばされる」だけを見ていて、
 * 登録そのものを一度も通していなかった。そのため app/Actions/Fortify/ の
 * 5ファイルはカバレッジ 0% のまま残っていた。ここはその穴を塞ぐテスト。
 *
 * 登録の流れは3段になっている。
 *
 *   ルート        Fortify が用意する POST /register
 *   バリデーション CreateNewUser::create() の中の Validator(FormRequest ではない)
 *   登録後の遷移  FortifyServiceProvider で差し替えた RegisterResponse → /books
 *
 * バリデーションが FormRequest ではなく Action の中にある点が、この機能の特徴。
 * $this->post() で叩いたときに ValidationException が
 * セッションのエラーに変換されて返ってくるのは Fortify のルート側の作りによる。
 *
 * パスワードのルールにも注意点がある。CreateNewUser は
 * PasswordValidationRules トレイトを use しているが passwordRules() を呼んでおらず、
 * 'min:8' を直接書いている。つまり登録とパスワード変更でルールの出どころが違う。
 * 片方を直してももう片方は変わらないので、それぞれ別にテストする。
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 登録フォームに送る一式。個別のテストで壊したい項目だけ上書きする。
     */
    private function validPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'なおやん',
            'email' => 'nao@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }

    /**
     * 前提: 未ログイン
     * 操作: GET /register
     * 期待: 200
     */
    public function test_会員登録画面が表示される(): void
    {
        $this->get('/register')->assertOk();
    }

    /**
     * 前提: 未ログイン
     * 操作: POST /register(正しい入力)
     * 期待: ユーザーが作られ、ログイン状態になり、/books へリダイレクト
     *
     * リダイレクト先の /books は Fortify の設定ではなく、
     * FortifyServiceProvider で RegisterResponse を差し替えて実現している。
     * config/fortify.php の 'home' と二重管理になっているので、
     * どちらを変えても効かない側があることに気づけるよう、ここで固定する。
     */
    public function test_会員登録できる(): void
    {
        $this->post('/register', $this->validPayload())
            ->assertRedirect('/books');

        $this->assertDatabaseHas('users', ['name' => 'なおやん', 'email' => 'nao@example.com']);
        $this->assertAuthenticated();
    }

    /**
     * 前提: 未ログイン
     * 操作: POST /register
     * 期待: パスワードは平文で保存されない
     *
     * CreateNewUser は Hash::make() を通し、User の $casts にも 'hashed' がある。
     * 二重ハッシュになっていないこと(= 元のパスワードで照合が通ること)まで見る。
     * ここが二重になると、登録はできるのにログインできないという状態になり、
     * 登録テストだけでは絶対に気づけない。
     */
    public function test_パスワードはハッシュ化して保存される(): void
    {
        $this->post('/register', $this->validPayload());

        $user = User::where('email', 'nao@example.com')->firstOrFail();

        $this->assertNotSame('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    /**
     * 前提: 未ログイン
     * 操作: POST /register(空)
     * 期待: name / email / password すべてでエラー。ユーザーは作られない
     */
    public function test_未入力では会員登録できない(): void
    {
        $this->from('/register')
            ->post('/register', [])
            ->assertRedirect('/register')
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    /**
     * 前提: 未ログイン
     * 操作: POST /register(メールアドレスの形式が不正)
     * 期待: email でエラー
     */
    public function test_メールアドレスの形式が不正なら登録できない(): void
    {
        $this->from('/register')
            ->post('/register', $this->validPayload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * 前提: 同じメールアドレスのユーザーが既にいる
     * 操作: POST /register
     * 期待: email でエラー。ユーザーは増えない
     *
     * users テーブルにも unique があるので、ルールが抜けても二重登録はされないが、
     * その場合は QueryException で 500 になる。
     * 「既に登録されています」と伝わることが目的なので、エラーの有無で確認する。
     */
    public function test_登録済みのメールアドレスでは会員登録できない(): void
    {
        User::factory()->create(['email' => 'nao@example.com']);

        $this->from('/register')
            ->post('/register', $this->validPayload())
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    /**
     * 前提: 未ログイン
     * 操作: POST /register(確認用パスワードが違う)
     * 期待: password でエラー
     *
     * confirmed ルールは password_confirmation という名前の項目を探す。
     * ビュー側の name 属性を変えると、入力が一致していてもここで落ちる。
     */
    public function test_確認用パスワードが一致しないと会員登録できない(): void
    {
        $this->from('/register')
            ->post('/register', $this->validPayload(['password_confirmation' => 'different123']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * 前提: 未ログイン
     * 操作: POST /register(7文字のパスワード)
     * 期待: password でエラー
     *
     * min:8 の境界。8文字ちょうどが通ることは次のテストで見る。
     */
    public function test_パスワードが8文字未満なら会員登録できない(): void
    {
        $this->from('/register')
            ->post('/register', $this->validPayload([
                'password' => 'pass123',
                'password_confirmation' => 'pass123',
            ]))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * 前提: 未ログイン
     * 操作: POST /register(8文字ちょうど)
     * 期待: 登録できる
     */
    public function test_パスワードが8文字ちょうどなら会員登録できる(): void
    {
        $this->post('/register', $this->validPayload([
            'password' => 'pass1234',
            'password_confirmation' => 'pass1234',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'nao@example.com']);
    }

    // ------------------------------------------------------------------
    // ログイン / ログアウト(FortifyServiceProvider の authenticateUsing)
    // ------------------------------------------------------------------

    /**
     * 前提: 登録済みユーザー
     * 操作: POST /login(正しいパスワード)
     * 期待: ログイン状態になる
     *
     * 認証は Fortify の既定ではなく FortifyServiceProvider の
     * authenticateUsing() クロージャで自前に置き換えられている。
     * Hash::check() を手で呼んでいるので、User の $casts の 'hashed' と
     * 噛み合っているかはここでしか確認できない。
     */
    public function test_正しいパスワードでログインできる(): void
    {
        $user = User::factory()->create(['email' => 'nao@example.com']);

        $this->post('/login', ['email' => 'nao@example.com', 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    /**
     * 前提: 登録済みユーザー
     * 操作: POST /login(間違ったパスワード)
     * 期待: email にエラーが出て、ログインしていない
     *
     * エラーを password ではなく email に付けているのは、
     * 「どちらが間違っているか」を攻撃者に教えないため。
     * メッセージも「メールアドレスまたはパスワード」とまとめてある。
     * この設計判断を崩さないよう、付き先のキーまで固定する。
     */
    public function test_パスワードが違うとログインできない(): void
    {
        User::factory()->create(['email' => 'nao@example.com']);

        $this->from('/login')
            ->post('/login', ['email' => 'nao@example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * 前提: 登録されていないメールアドレス
     * 操作: POST /login
     * 期待: email にエラー。パスワード違いと同じ扱いになる
     *
     * 「未登録」と「パスワード違い」で応答が変わると、
     * どのメールアドレスが登録済みかを外から総当たりで調べられる。
     * 前のテストと同じ結果になることに意味がある。
     */
    public function test_未登録のメールアドレスではログインできない(): void
    {
        $this->from('/login')
            ->post('/login', ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * 前提: 未ログイン
     * 操作: POST /login(空)
     * 期待: email と password の両方でエラー
     */
    public function test_未入力ではログインできない(): void
    {
        $this->from('/login')
            ->post('/login', [])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }

    /**
     * 前提: ログイン済み
     * 操作: POST /logout
     * 期待: ログアウトして /login へリダイレクト
     *
     * リダイレクト先は LogoutResponse を差し替えて /login にしてある。
     * 既定では / に飛ぶので、差し替えが外れると存在しないルートに飛ぶ。
     */
    public function test_ログアウトするとログイン画面に戻る(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }
}
