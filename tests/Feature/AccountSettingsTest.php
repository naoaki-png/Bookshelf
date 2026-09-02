<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * プロフィール更新・パスワード変更・パスワード再設定を確認する。
 *
 * 対応する Action は3つ。
 *
 *   PUT  /user/profile-information  UpdateUserProfileInformation
 *   PUT  /user/password             UpdateUserPassword
 *   POST /reset-password            ResetUserPassword
 *
 * どれも画面が用意されていない(Fortify が用意するのはルートだけで、
 * このプロジェクトはプロフィール編集画面を作っていない)。
 * それでもルートは生きているので、ログインさえしていれば誰でも叩ける。
 * 画面が無いことは、テストを書かない理由にはならない。
 *
 * 3つとも FormRequest ではなく Action の中で Validator を組み立てている。
 * profile と password は validateWithBag() を使っているため、
 * エラーは 'default' ではなく専用のエラーバッグに入る。
 * assertSessionHasErrors の第3引数でバッグ名を指定しないと検出できない。
 * 一方 ResetUserPassword は validate() なので既定のバッグに入る。
 * この差は実装を読まないと分からないので、テストの形として残しておく。
 *
 * パスワードのルールは PasswordValidationRules::passwordRules() が持っている。
 * ここを使うのは password 変更と再設定の2つだけで、会員登録は
 * トレイトを use しているのに独自に 'min:8' を書いている(RegistrationTest 参照)。
 */
class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // プロフィール更新
    // ------------------------------------------------------------------

    /**
     * 前提: ログイン済み
     * 操作: PUT /user/profile-information
     * 期待: 名前とメールアドレスが更新される
     *
     * User は MustVerifyEmail を実装していないので、メールを変えても
     * email_verified_at は消えず、確認メールも飛ばない(else 側を通る)。
     * 将来 MustVerifyEmail を付けると分岐が変わるため、
     * 「今はメール変更で再確認を求めない」ことを固定しておく。
     */
    public function test_プロフィールを更新できる(): void
    {
        $user = User::factory()->create(['name' => '旧名', 'email' => 'old@example.com']);
        $verifiedAt = $user->email_verified_at;

        $this->actingAs($user)
            ->put('/user/profile-information', ['name' => '新名', 'email' => 'new@example.com'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('新名', $user->name);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame($verifiedAt->toDateTimeString(), $user->email_verified_at->toDateTimeString());
    }

    /**
     * 前提: ログイン済み
     * 操作: PUT /user/profile-information(空)
     * 期待: updateProfileInformation バッグに name / email のエラー
     *
     * validateWithBag('updateProfileInformation') なので、
     * 既定のバッグを見ても何も入っていない。バッグ名まで含めて固定する。
     */
    public function test_名前とメールアドレスが未入力ならプロフィールを更新できない(): void
    {
        $user = User::factory()->create(['name' => '旧名']);

        $this->actingAs($user)
            ->from('/books')
            ->put('/user/profile-information', ['name' => '', 'email' => ''])
            ->assertSessionHasErrors(['name', 'email'], null, 'updateProfileInformation');

        $this->assertSame('旧名', $user->refresh()->name);
    }

    /**
     * 前提: 他のユーザーが使っているメールアドレス
     * 操作: PUT /user/profile-information
     * 期待: email でエラー。変更されない
     */
    public function test_他人が使用中のメールアドレスには変更できない(): void
    {
        $user = User::factory()->create(['email' => 'mine@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user)
            ->from('/books')
            ->put('/user/profile-information', ['name' => '名前', 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email', null, 'updateProfileInformation');

        $this->assertSame('mine@example.com', $user->refresh()->email);
    }

    /**
     * 前提: ログイン済み
     * 操作: PUT /user/profile-information(メールは自分のまま、名前だけ変更)
     * 期待: 更新できる
     *
     * unique ルールは ->ignore($user->id) で自分を除外している。
     * この ignore が抜けると「メールアドレスを変えないと名前を変更できない」
     * という状態になる。実際に踏みやすいので対で固定しておく。
     */
    public function test_メールアドレスを変えずに名前だけ更新できる(): void
    {
        $user = User::factory()->create(['name' => '旧名', 'email' => 'mine@example.com']);

        $this->actingAs($user)
            ->put('/user/profile-information', ['name' => '新名', 'email' => 'mine@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('新名', $user->refresh()->name);
    }

    /**
     * 前提: 未ログイン
     * 操作: PUT /user/profile-information
     * 期待: /login へリダイレクト。更新されない
     */
    public function test_未ログインではプロフィールを更新できない(): void
    {
        $user = User::factory()->create(['name' => '旧名']);

        $this->put('/user/profile-information', ['name' => '新名', 'email' => 'x@example.com'])
            ->assertRedirect('/login');

        $this->assertSame('旧名', $user->refresh()->name);
    }

    // ------------------------------------------------------------------
    // パスワード変更
    // ------------------------------------------------------------------

    /**
     * 前提: ログイン済み(パスワードは password)
     * 操作: PUT /user/password
     * 期待: 新しいパスワードで照合が通るようになる
     */
    public function test_パスワードを変更できる(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password-123', $user->refresh()->password));
    }

    /**
     * 前提: ログイン済み
     * 操作: PUT /user/password(現在のパスワードが違う)
     * 期待: current_password でエラー。パスワードは変わらない
     *
     * ここが素通りすると、セッションを盗まれた時点でパスワードごと奪われる。
     * 「今のパスワードを知っている」の確認は、画面が無いルートでも効いている必要がある。
     */
    public function test_現在のパスワードが違うと変更できない(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/books')
            ->put('/user/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertSessionHasErrors('current_password', null, 'updatePassword');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    /**
     * 前提: ログイン済み
     * 操作: PUT /user/password(確認用が一致しない)
     * 期待: password でエラー。変わらない
     */
    public function test_確認用パスワードが一致しないと変更できない(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/books')
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors('password', null, 'updatePassword');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    /**
     * 前提: ログイン済み
     * 操作: PUT /user/password(7文字)
     * 期待: password でエラー
     *
     * こちらのルールは passwordRules() = Password::default() から来る。
     * 会員登録側の 'min:8' とは出どころが別なので、片方だけ変えても
     * もう片方は変わらない。両方に境界のテストを置いてある。
     */
    public function test_短すぎるパスワードには変更できない(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/books')
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'short12',
                'password_confirmation' => 'short12',
            ])
            ->assertSessionHasErrors('password', null, 'updatePassword');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    // ------------------------------------------------------------------
    // パスワード再設定
    // ------------------------------------------------------------------

    /**
     * 前提: 登録済みユーザー
     * 操作: POST /forgot-password
     * 期待: 再設定メールの通知が送られる
     *
     * Notification::fake() で止めているのは、実際の送信内容ではなく
     * 「誰に送られたか」を見たいから。ここを止めないと
     * MAIL_MAILER=array に溜まるだけで、宛先の確認がしにくい。
     */
    public function test_パスワード再設定メールを送れる(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'nao@example.com']);

        $this->post('/forgot-password', ['email' => 'nao@example.com'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * 前提: 有効な再設定トークン
     * 操作: POST /reset-password
     * 期待: パスワードが新しいものに変わる
     *
     * ResetUserPassword は validate() を使っているので、
     * エラーは既定のバッグに入る(profile / password 変更とはここが違う)。
     */
    public function test_トークンでパスワードを再設定できる(): void
    {
        $user = User::factory()->create(['email' => 'nao@example.com']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'nao@example.com',
            'password' => 'reset-password-123',
            'password_confirmation' => 'reset-password-123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('reset-password-123', $user->refresh()->password));
    }

    /**
     * 前提: でたらめなトークン
     * 操作: POST /reset-password
     * 期待: email でエラー。パスワードは変わらない
     *
     * トークンが無効なとき、エラーは password ではなく email に付く。
     * ブローカーが「メールとトークンの組」で照合しているため。
     */
    public function test_無効なトークンではパスワードを再設定できない(): void
    {
        $user = User::factory()->create(['email' => 'nao@example.com']);

        $this->from('/reset-password/invalid-token')
            ->post('/reset-password', [
                'token' => 'invalid-token',
                'email' => 'nao@example.com',
                'password' => 'reset-password-123',
                'password_confirmation' => 'reset-password-123',
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    /**
     * 前提: 有効なトークンだが、新しいパスワードが短い
     * 操作: POST /reset-password
     * 期待: password でエラー。変わらない
     *
     * ここも passwordRules() 経由。再設定の経路でもルールが効いていることを見る。
     */
    public function test_短すぎるパスワードには再設定できない(): void
    {
        $user = User::factory()->create(['email' => 'nao@example.com']);
        $token = Password::createToken($user);

        $this->from('/reset-password/' . $token)
            ->post('/reset-password', [
                'token' => $token,
                'email' => 'nao@example.com',
                'password' => 'short12',
                'password_confirmation' => 'short12',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }
}
