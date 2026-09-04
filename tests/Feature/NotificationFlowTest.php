<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ReadingPlan;
use App\Models\User;
use App\Notifications\ReadingPlanReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/**
 * 通知一覧(/notifications)と既読処理、および ReadingPlanReminder の中身を確認する。
 *
 * 通知は database チャンネルなので、実体は notifications テーブルの1行になる。
 * 本文は data 列に JSON でまとまって入っていて、列としては存在しない。
 * そのため assertDatabaseHas で本文を見ることはできず、
 * $notification->data['...'] を読むか、描画後の HTML を見ることになる。
 *
 * 確認したいのは3つ。
 *
 * 1. 他人の通知に触れないこと。
 *    NotificationsController は Auth::user()->notifications()->findOrFail($id) で
 *    引いている。この「自分の通知の中から探す」形が崩れて
 *    DatabaseNotification::findOrFail($id) になると、他人の通知を既読にできてしまう。
 *    通知 id は UUID なので当てずっぽうでは踏めないが、
 *    一度でも見えた id は使い回せる。403 ではなく 404 になるのは、
 *    「自分の通知の中に無い」= 見つからない、という引き方だから。
 *
 * 2. 一覧が新しい順であること。
 *    並べ替えはコントローラーではなく Notifiable トレイトの notifications() が
 *    持っている(->latest() が入っている)。コントローラーには一切書かれていないので、
 *    ここを orderBy で上書きしたときに気づけるようにしておく。
 *
 * 3. timing 3値それぞれで本文が出ること。
 *    ReadingPlanReminder::toArray() の match には default が無い。
 *    バッチが4つ目の値を渡した瞬間 UnhandledMatchError になる。
 *    これは「default を書かない」と決めた上での設計なので、
 *    3値が揃っていることをテストで固定しておく必要がある。
 */
class NotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 指定ユーザーに読書計画リマインダーを1件送る。
     *
     * ReadingPlanReminder は toArray() で $plan->book->title を読むので、
     * 計画には必ず本がぶら下がっている必要がある。factory 任せで作る。
     */
    private function notify(User $user, string $timing = 'three_days_before'): ReadingPlan
    {
        $plan = ReadingPlan::factory()->create(['user_id' => $user->id]);
        $user->notify(new ReadingPlanReminder($plan, $timing));

        return $plan;
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    /**
     * 前提: 自分に2件、他ユーザーに1件の通知
     * 操作: GET /notifications
     * 期待: 200 で、自分の2件だけがビューに渡る
     */
    public function test_通知一覧には自分の通知だけが表示される(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->notify($user);
        $this->notify($user);
        $this->notify($other);

        $notifications = $this->actingAs($user)->get('/notifications')->assertOk()->viewData('notifications');

        $this->assertCount(2, $notifications);
        $this->assertSame(3, DatabaseNotification::count());
    }

    /**
     * 前提: 通知0件
     * 操作: GET /notifications
     * 期待: 200 で「通知はありません。」が出る
     */
    public function test_通知が0件でも一覧が表示される(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/notifications')
            ->assertOk()
            ->assertSee('通知はありません。');
    }

    /**
     * 前提: 3件の通知を、作成日時をずらして作る
     * 操作: GET /notifications
     * 期待: 新しい順に並ぶ
     *
     * 並べ替えは Notifiable::notifications() の ->latest() が担当していて、
     * コントローラーには書かれていない。created_at を明示的にずらしているのは、
     * 同一トランザクション内だと3件とも同じ秒になり、順序が不定になるため。
     */
    public function test_通知一覧は新しい順に並ぶ(): void
    {
        $user = User::factory()->create();

        $this->notify($user);
        $this->notify($user);
        $this->notify($user);

        $ids = DatabaseNotification::orderBy('id')->pluck('id');
        foreach ($ids as $i => $id) {
            DatabaseNotification::where('id', $id)
                ->update(['created_at' => now()->subDays(3 - $i)]);
        }

        $notifications = $this->actingAs($user)->get('/notifications')->viewData('notifications');

        $this->assertSame($ids->reverse()->values()->all(), $notifications->pluck('id')->all());
    }

    /**
     * 前提: timing が3値それぞれの通知を1件ずつ
     * 操作: GET /notifications
     * 期待: 3種類の本文がすべて画面に出る
     *
     * ビューは data['timing'] を match で色分けしているが、こちらは default を持つ。
     * 一方 ReadingPlanReminder::toArray() の match には default が無い。
     * 3値が揃っていることをここで固定しておかないと、
     * 値を1つ増やしたときにバッチ実行中の UnhandledMatchError で初めて気づくことになる。
     */
    public function test_3種類のタイミングの通知本文が表示される(): void
    {
        $user = User::factory()->create();

        $this->notify($user, 'three_days_before');
        $this->notify($user, 'on_due_date');
        $this->notify($user, 'three_days_after');

        $this->actingAs($user)->get('/notifications')
            ->assertOk()
            ->assertSee('読書計画の期限まであと3日です。')
            ->assertSee('読書計画の期限は本日です。')
            ->assertSee('読書計画の期限から3日が経過しました。');
    }

    /**
     * 前提: 未読の通知1件
     * 操作: GET /notifications
     * 期待: 「未読」バッジと「既読にする」ボタンが出る
     */
    public function test_未読の通知には既読ボタンが表示される(): void
    {
        $user = User::factory()->create();
        $this->notify($user);

        $this->actingAs($user)->get('/notifications')
            ->assertOk()
            ->assertSee('未読')
            ->assertSee('既読にする');
    }

    /**
     * 前提: 既読済みの通知1件
     * 操作: GET /notifications
     * 期待: 「既読にする」ボタンは出ない
     *
     * 未読判定は read_at === null で書かれている。
     * ここが緩むと、既読の通知にもボタンが出続ける。
     */
    public function test_既読の通知には既読ボタンが表示されない(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $user->notifications()->first()->markAsRead();

        $this->actingAs($user)->get('/notifications')
            ->assertOk()
            ->assertDontSee('既読にする');
    }

    // ------------------------------------------------------------------
    // read
    // ------------------------------------------------------------------

    /**
     * 前提: 未読の通知1件
     * 操作: POST /notifications/{id}/read
     * 期待: read_at が入り、一覧へリダイレクト
     */
    public function test_自分の通知を既読にできる(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $notification = $user->notifications()->first();

        $this->actingAs($user)
            ->post('/notifications/' . $notification->id . '/read')
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('success', '通知を既読にしました');

        $this->assertNotNull($notification->refresh()->read_at);
    }

    /**
     * 前提: 他ユーザーの未読通知1件
     * 操作: POST /notifications/{他人のid}/read
     * 期待: 404 で、未読のまま
     *
     * findOrFail は Auth::user()->notifications() の上で呼ばれている。
     * 他人の id は「自分の通知の中に無い」ので 404 になる。
     * 403 ではないのは所有者チェックをしているのではなく、そもそも探す範囲が
     * 自分に限定されているから。ステータスコードの理由が違うので混同しない。
     */
    public function test_他人の通知は既読にできない(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->notify($other);
        $notification = $other->notifications()->first();

        $this->actingAs($user)
            ->post('/notifications/' . $notification->id . '/read')
            ->assertNotFound();

        $this->assertNull($notification->refresh()->read_at);
    }

    /**
     * 前提: 存在しない通知 id
     * 操作: POST /notifications/{でたらめなid}/read
     * 期待: 404
     */
    public function test_存在しない通知を既読にしようとすると404になる(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/notifications/00000000-0000-0000-0000-000000000000/read')
            ->assertNotFound();
    }

    /**
     * 前提: すでに既読の通知1件(3日前に既読)
     * 操作: POST /notifications/{id}/read
     * 期待: 成功扱いだが read_at は上書きされない
     *
     * markAsRead() は Laravel 側で「未読のときだけ書き込む」実装になっている。
     * 二重送信で「いつ読んだか」が現在時刻に書き換わらないことを確認する。
     * コントローラー側には二重実行のガードが無いので、
     * この安全性はフレームワークに依存している。依存していること自体を固定する。
     */
    public function test_既読の通知をもう一度既読にしても既読日時は変わらない(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $notification = $user->notifications()->first();

        $readAt = now()->subDays(3);
        $notification->forceFill(['read_at' => $readAt])->save();

        $this->actingAs($user)
            ->post('/notifications/' . $notification->id . '/read')
            ->assertSessionHas('success', '通知を既読にしました');

        $this->assertSame(
            $readAt->toDateTimeString(),
            $notification->refresh()->read_at->toDateTimeString()
        );
    }

    // ------------------------------------------------------------------
    // ReadingPlanReminder そのもの
    // ------------------------------------------------------------------

    /**
     * 前提: 書籍タイトルが分かっている計画1件
     * 期待: data に timing / title / body の3キーが入り、title は書籍名になる
     *
     * バッチはここに 'three_days_before' などの文字列を渡す。
     * 通知の中身がどういう形で保存されるかを1か所に固定しておくと、
     * ビュー側(data['title'] / data['body'] を読んでいる)と食い違ったときに
     * どちらが変わったのかを切り分けられる。
     */
    public function test_通知データには書籍タイトルと本文が入る(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create(['title' => 'テスト駆動開発']);
        $plan = ReadingPlan::factory()->create(['user_id' => $user->id, 'book_id' => $book->id]);

        $user->notify(new ReadingPlanReminder($plan, 'on_due_date'));

        $data = $user->notifications()->first()->data;

        $this->assertSame(['timing', 'title', 'body'], array_keys($data));
        $this->assertSame('on_due_date', $data['timing']);
        $this->assertSame('テスト駆動開発', $data['title']);
        $this->assertSame('読書計画の期限は本日です。', $data['body']);
    }

    /**
     * 前提: 計画1件
     * 期待: 配信チャンネルは database だけ
     *
     * mail が混ざると、バッチ実行のたびに全ユーザーへメールが飛ぶ。
     * via() は1行しかないメソッドだが、影響範囲が一番大きいので固定する。
     */
    public function test_通知の配信チャンネルはデータベースのみ(): void
    {
        $user = User::factory()->create();
        $plan = ReadingPlan::factory()->create(['user_id' => $user->id]);

        $this->assertSame(['database'], (new ReadingPlanReminder($plan, 'on_due_date'))->via($user));
    }

    /**
     * 前提: timing に定義外の値を渡した通知
     * 期待: UnhandledMatchError になる
     *
     * toArray() の match に default が無いことを、意図として固定する。
     * 「静かに空の本文が保存される」より「その場で落ちる」を選んだ、という設計判断。
     * バッチから呼ぶ以上、落ちたときに残りの通知がどうなるかは別途詰める必要がある
     * (issue F の宿題)。ここではまず落ちること自体を明示しておく。
     */
    public function test_定義外のタイミングを渡すと例外になる(): void
    {
        $user = User::factory()->create();
        $plan = ReadingPlan::factory()->create(['user_id' => $user->id]);

        $this->expectException(\UnhandledMatchError::class);

        (new ReadingPlanReminder($plan, 'one_week_before'))->toArray($user);
    }
}
