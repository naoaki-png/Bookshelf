<?php

namespace Tests\Feature;

use App\Enums\ReadingPlanStatus;
use App\Models\Book;
use App\Models\ReadingPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 毎晩のリマインダーバッチ(reading-plans:remind)を確認する。
 *
 * このコマンドは2種類の仕事をする。
 *
 *   1. 通知を送る    期日の3日前 / 当日 / 3日後 の計画に、それぞれ1通ずつ
 *   2. 状態を更新する 期日を過ぎた未完了の計画を Overdue にする
 *
 * テストの土台は --date オプションにある。
 * バッチが内部で today() を呼んでいると、テストは実行した日によって
 * 結果が変わり、日付をまたいだ瞬間に落ちる。--date で基準日を外から渡せる
 * 設計にしたので、ここでは固定の日付(2026-09-01)を基準に全部を検証できる。
 *
 * 日付の比較には注意点がある。target_date は date 型で日付しか持たないが、
 * Carbon をそのまま where() に渡すと '2026-09-01 00:00:00' という日時文字列で
 * 送られる。MySQL は列が DATE 型だと知っているので黙って解釈するが、
 * テストで使う SQLite は文字列として比べるため一致しない。
 * つまり「手で動かすと成功し、テストだけが落ちる」という壊れ方をする。
 * 実装が format('Y-m-d') を通していることが、このテストの前提になっている。
 *
 * 通知の重複について1点。同じ日付で2回実行すると通知は二重に入る。
 * notifications テーブルには plan_id も timing も列として無く(data の JSON の中)、
 * 重複を判定する材料が今の設計には無いため。現状の挙動として最後に固定してあるが、
 * これは「良い」という意味ではなく未解決の論点。
 */
class SendReadingPlanRemindersTest extends TestCase
{
    use RefreshDatabase;

    /** バッチに渡す基準日。すべてのテストがこの日を「今日」として動く。 */
    private const BASE_DATE = '2026-09-01';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * 指定した期日の計画を1件作る。
     *
     * 書名を渡せるようにしているのは、通知の本文に書名が入る仕様のため。
     * ReadingPlanFactory は book_id に Book::factory() を置いているので、
     * 呼ぶたびに別の本になり、unique(['user_id','book_id']) を踏まない。
     */
    private function plan(string $targetDate, ReadingPlanStatus $status = ReadingPlanStatus::InProgress, string $title = '本'): ReadingPlan
    {
        return ReadingPlan::factory()->create([
            'user_id' => $this->user->id,
            'target_date' => $targetDate,
            'status' => $status,
            'completed_at' => $status === ReadingPlanStatus::Completed ? now() : null,
            'book_id' => Book::factory()->create(['title' => $title])->id,
        ]);
    }

    /** 基準日を渡してバッチを実行し、終了コードを返す。 */
    private function runBatch(string $date = self::BASE_DATE): int
    {
        return $this->artisan('reading-plans:remind', ['--date' => $date])->run();
    }

    /** 送られた通知の timing を一覧で返す。 */
    private function sentTimings(): array
    {
        return DatabaseNotification::all()->map(fn ($n) => $n->data['timing'])->sort()->values()->all();
    }

    // ------------------------------------------------------------------
    // 通知の送信
    // ------------------------------------------------------------------

    /**
     * 前提: 期日が3日後 / 当日 / 3日前 の計画が1件ずつ
     * 操作: reading-plans:remind --date=2026-09-01
     * 期待: 3通、それぞれ違う timing で送られる
     *
     * 3本のクエリがすべて機能していることを1本で見る。
     * どれか1本の日付計算がずれると、この配列の要素が欠ける。
     */
    public function test_3日前と当日と3日後の計画それぞれに通知が送られる(): void
    {
        $this->plan('2026-09-04');
        $this->plan('2026-09-01');
        $this->plan('2026-08-29', ReadingPlanStatus::Overdue);

        $this->runBatch();

        $this->assertSame(
            ['on_due_date', 'three_days_after', 'three_days_before'],
            $this->sentTimings()
        );
    }

    /**
     * 前提: 期日が3日後の計画1件
     * 操作: バッチ実行
     * 期待: three_days_before の通知が、その計画の持ち主に届く
     *
     * 「3日前に通知」を通知を出す日から見ると期日は3日後になる。
     * 名前と符号が反転するので、ここを取り違えると
     * 期日を過ぎた人に「あと3日です」が届く。
     */
    public function test_期日の3日前の通知が持ち主に届く(): void
    {
        $this->plan('2026-09-04', ReadingPlanStatus::InProgress, 'リーダブルコード');

        $this->runBatch();

        $notification = DatabaseNotification::sole();
        $this->assertSame($this->user->id, (int) $notification->notifiable_id);
        $this->assertSame('three_days_before', $notification->data['timing']);
        $this->assertSame('リーダブルコード', $notification->data['title']);
        $this->assertSame('読書計画の期限まであと3日です。', $notification->data['body']);
    }

    /**
     * 前提: 期日が当日の計画1件
     * 期待: on_due_date の通知が届く
     */
    public function test_期日当日の通知が届く(): void
    {
        $this->plan('2026-09-01');

        $this->runBatch();

        $this->assertSame('on_due_date', DatabaseNotification::sole()->data['timing']);
    }

    /**
     * 前提: 期日が3日前で、すでに Overdue になっている計画1件
     * 期待: three_days_after の通知が届く
     *
     * ★ このテストが、抽出条件を where('status', InProgress) にできない理由そのもの。
     * 3日後通知の対象は、前日までのバッチで必ず Overdue に変わっている。
     * 進行中だけに絞ると、3通目が1件も飛ばなくなる。
     * 条件が「完了していないもの」でなければならない、という設計判断を固定する。
     */
    public function test_期日を過ぎた計画にも3日後の通知が届く(): void
    {
        $this->plan('2026-08-29', ReadingPlanStatus::Overdue);

        $this->runBatch();

        $this->assertSame('three_days_after', DatabaseNotification::sole()->data['timing']);
    }

    /**
     * 前提: 期日が1日後 / 2日後 / 1日前 / 2日前 / 4日前 の計画
     * 操作: バッチ実行
     * 期待: 通知は1通も送られない
     *
     * 仕様は「3回だけ送る」。条件が範囲(< や >)になっていると、
     * 対象外の日にも毎晩送り続けることになる。
     * 送られない日を並べておくことで、= が < にすり替わった瞬間に落ちる。
     */
    public function test_対象外の日付には通知が送られない(): void
    {
        $this->plan('2026-09-02');
        $this->plan('2026-09-03');
        $this->plan('2026-08-31');
        $this->plan('2026-08-30');
        $this->plan('2026-08-28');

        $this->runBatch();

        $this->assertSame(0, DatabaseNotification::count());
    }

    /**
     * 前提: 3つの対象日すべてに、完了済みの計画を1件ずつ
     * 期待: 通知は1通も送られない
     *
     * 読み終わった本に「期限まであと3日です」は届いてはいけない。
     * 3日前(= すでに期日超過)の完了済みも含めているのは、
     * 条件が != Completed であることを3本すべてで確認するため。
     */
    public function test_完了済みの計画には通知が送られない(): void
    {
        $this->plan('2026-09-04', ReadingPlanStatus::Completed);
        $this->plan('2026-09-01', ReadingPlanStatus::Completed);
        $this->plan('2026-08-29', ReadingPlanStatus::Completed);

        $this->runBatch();

        $this->assertSame(0, DatabaseNotification::count());
    }

    /**
     * 前提: 別々のユーザーが、同じ日を期日にした計画を持っている
     * 期待: それぞれの持ち主に1通ずつ届く
     *
     * 通知先は $plan->user なので本来は間違えようがないが、
     * ループの外で $user を1回だけ引くような書き方に変えると、
     * 全員分が最初の1人に届く。宛先が計画ごとに解決されることを固定する。
     */
    public function test_通知はそれぞれの計画の持ち主に届く(): void
    {
        $other = User::factory()->create();
        $this->plan('2026-09-01');
        ReadingPlan::factory()->create([
            'user_id' => $other->id,
            'target_date' => '2026-09-01',
            'book_id' => Book::factory()->create()->id,
        ]);

        $this->runBatch();

        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $this->user->id)->count());
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $other->id)->count());
    }

    // ------------------------------------------------------------------
    // Overdue への更新
    // ------------------------------------------------------------------

    /**
     * 前提: 期日が前日 / 3日前 の未完了の計画
     * 期待: どちらも Overdue になる
     *
     * 「期日の翌日から Overdue」なので、前日が境界の内側にある。
     * 3日前も一緒に見ているのは、条件が範囲(<)であることの確認。
     * 特定の1日だけを拾う書き方だと、バッチが落ちた日の計画が
     * 永久に Overdue にならなくなる。
     */
    public function test_期日を過ぎた未完了の計画はOverdueになる(): void
    {
        $yesterday = $this->plan('2026-08-31');
        $threeDaysAgo = $this->plan('2026-08-29');

        $this->runBatch();

        $this->assertSame(ReadingPlanStatus::Overdue, $yesterday->refresh()->status);
        $this->assertSame(ReadingPlanStatus::Overdue, $threeDaysAgo->refresh()->status);
    }

    /**
     * 前提: 期日が当日の未完了の計画
     * 期待: 進行中のまま
     *
     * ★ 境界。Overdue は「期日の翌日から」であって当日ではない。
     * ここが1日ずれると、期日当日に「期日遅れ」バッジが出る。
     *
     * 実装が where('target_date', '<', $date) に Carbon をそのまま渡すと、
     * SQLite では '2026-09-01' < '2026-09-01 00:00:00' が真になり
     * (前半が同じで短いほうが小さいと判定されるため)このテストが落ちる。
     * 日付を文字列で渡していることを守る番人でもある。
     */
    public function test_期日当日の計画はOverdueにならない(): void
    {
        $today = $this->plan('2026-09-01');

        $this->runBatch();

        $this->assertSame(ReadingPlanStatus::InProgress, $today->refresh()->status);
    }

    /**
     * 前提: 期日が未来の計画
     * 期待: 進行中のまま
     */
    public function test_期日が未来の計画はOverdueにならない(): void
    {
        $future = $this->plan('2026-09-04');

        $this->runBatch();

        $this->assertSame(ReadingPlanStatus::InProgress, $future->refresh()->status);
    }

    /**
     * 前提: 期日を過ぎているが完了済みの計画(10日前に完了)
     * 期待: 完了のまま。完了日時も書き換わらない
     *
     * 読み終わった本が「期日遅れ」に戻ると、履歴として意味が壊れる。
     * completed_at まで見ているのは、status だけ守られて
     * 日時が現在時刻で上書きされる書き方があり得るため。
     */
    public function test_完了済みの計画はOverdueにならない(): void
    {
        $completedAt = Carbon::parse('2026-08-22 10:00:00');
        $plan = $this->plan('2026-08-29', ReadingPlanStatus::Completed);
        $plan->forceFill(['completed_at' => $completedAt])->save();

        $this->runBatch();

        $plan->refresh();
        $this->assertSame(ReadingPlanStatus::Completed, $plan->status);
        $this->assertSame($completedAt->toDateTimeString(), $plan->completed_at->toDateTimeString());
    }

    /**
     * 前提: すでに Overdue の計画
     * 期待: Overdue のまま(実行しても壊れない)
     *
     * 状態の更新は「毎日、条件に合うものを全部拾う」形なので、
     * すでに Overdue のものも毎晩もう一度更新対象に入る。
     * 何度実行しても同じ状態に落ち着くことを確認する。
     * 通知と違い、状態の更新のほうは繰り返しても安全である、という対比。
     */
    public function test_すでにOverdueの計画を再度実行しても壊れない(): void
    {
        $plan = $this->plan('2026-08-31', ReadingPlanStatus::Overdue);

        $this->runBatch();
        $this->runBatch();

        $this->assertSame(ReadingPlanStatus::Overdue, $plan->refresh()->status);
    }

    // ------------------------------------------------------------------
    // 基準日の受け取り方
    // ------------------------------------------------------------------

    /**
     * 前提: 実行時刻を 2026-09-01 に固定し、期日が当日の計画1件
     * 操作: --date を付けずに実行
     * 期待: 実行日を基準日として通知が届く
     *
     * 毎晩の自動実行は引数なしで走る(年365回のうち355回以上はこちら)。
     * オプションが未指定のときに today() へ落ちる分岐を確認する。
     */
    public function test_dateを省略すると実行日が基準日になる(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 23:00:00'));
        $this->plan('2026-09-01');

        $this->artisan('reading-plans:remind')->run();

        $this->assertSame('on_due_date', DatabaseNotification::sole()->data['timing']);
    }

    /**
     * 前提: 実行日は 2026-09-05 だが、基準日として 2026-09-01 を渡す
     * 期待: 2026-09-01 を基準に通知が届く
     *
     * ★ --date を作った理由そのもの。バッチが止まっていた日のぶんを
     * あとから流し直せる(追いつき実行)。実行日と基準日が別々に動くことを、
     * わざと4日ずらして確認する。
     */
    public function test_dateを指定すると実行日と無関係にその日を基準にする(): void
    {
        $this->travelTo(Carbon::parse('2026-09-05 10:00:00'));
        $this->plan('2026-09-01');
        $this->plan('2026-09-05');

        $this->runBatch('2026-09-01');

        $this->assertSame('on_due_date', DatabaseNotification::sole()->data['timing']);
    }

    /**
     * 前提: 計画が1件もない
     * 期待: 終了コード 0 で正常終了する
     *
     * handle() の戻り値はシェルに返す終了コードで、cron はこの数値だけを見ている。
     * 対象0件は異常ではないので 0 を返さなければならない。
     */
    public function test_対象が0件でも正常終了する(): void
    {
        $this->assertSame(0, $this->runBatch());
        $this->assertSame(0, DatabaseNotification::count());
    }

    /**
     * 前提: 3つの対象日すべてに計画がある
     * 期待: 終了コード 0
     */
    public function test_正常に処理できたら終了コード0を返す(): void
    {
        $this->plan('2026-09-04');
        $this->plan('2026-09-01');
        $this->plan('2026-08-29');

        $this->assertSame(0, $this->runBatch());
    }

    // ------------------------------------------------------------------
    // クエリの本数
    // ------------------------------------------------------------------

    /**
     * books と users への SELECT が何本飛んだかを数える。
     *
     * 総クエリ数では測れない。通知は1件ごとに INSERT が1本増えるので、
     * 計画が増えれば総数は必ず増える。N+1 かどうかを見たいのは
     * 「関連を読むためのクエリが件数に比例して増えるか」なので、
     * books と users への SELECT だけを取り出して数える。
     */
    private function relationSelectCount(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "books"') || str_contains($q['query'], 'from "users"'))
            ->count();
    }

    /**
     * 前提: 3つの対象日に計画が1件ずつ → そのあと3件ずつ(合計9件)
     * 期待: どちらも books / users への SELECT は6本のまま
     *
     * 実装は with('book', 'user') で本と持ち主をまとめて読み込んでいる。
     * 対象日3つ × 関連2つ = 6本が、計画が何件あっても変わらない。
     *
     * これが外れると、通知を作るたびに $plan->book と $plan->user で
     * 1本ずつクエリが飛ぶ。計画9件なら 6本が18本に増え、
     * 100件なら200本を超える。件数を3倍にして本数が変わらないことを見る。
     */
    public function test_計画が増えても関連の読み込みクエリは増えない(): void
    {
        $this->plan('2026-09-04');
        $this->plan('2026-09-01');
        $this->plan('2026-08-29');

        DB::enableQueryLog();
        $this->runBatch();
        $withOneEach = $this->relationSelectCount();
        DB::disableQueryLog();

        DatabaseNotification::query()->delete();
        ReadingPlan::query()->delete();

        foreach (['2026-09-04', '2026-09-01', '2026-08-29'] as $date) {
            for ($i = 0; $i < 3; $i++) {
                $this->plan($date);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->runBatch();
        $withThreeEach = $this->relationSelectCount();
        DB::disableQueryLog();

        $this->assertSame(9, DatabaseNotification::count());
        $this->assertSame(6, $withOneEach);
        $this->assertSame(6, $withThreeEach);
    }

    // ------------------------------------------------------------------
    // スケジュール登録
    // ------------------------------------------------------------------

    /**
     * 期待: 毎日23時(日本時間)に、引数なしで実行される予定が登録されている
     *
     * app.timezone は UTC なので、timezone('Asia/Tokyo') が無いと
     * 23:00 UTC = 翌朝8時(日本時間)に動く。「毎晩」ではなくなる。
     *
     * また、予定に渡すコマンドは引数なしでなければならない。
     * $signature の {--date=} は「受け取れる」という宣言であって値ではないので、
     * これを呼び出し側に書くと毎晩 "No arguments expected" で失敗する。
     * cron は終了コードしか見ないので、気づかないまま動かない状態が続く。
     *
     * なお schedule:run から通知が入るところまではテストできない。
     * $schedule->command() は別プロセスで artisan を起動するため、
     * :memory: の SQLite が新しいプロセスからは空に見えるため。
     * 予定の登録内容までをここで確認し、通し実行は手で schedule:run を打つ。
     */
    public function test_毎晩23時に引数なしで実行される予定が登録されている(): void
    {
        $this->artisan('schedule:list')->run();
        $events = $this->app->make(Schedule::class)->events();

        $event = collect($events)->firstOrFail(
            fn ($e) => str_contains($e->command, 'reading-plans:remind')
        );

        $this->assertSame('0 23 * * *', $event->expression);
        $this->assertSame('Asia/Tokyo', $event->timezone);
        $this->assertStringNotContainsString('--date', $event->command);

        $this->travelTo(Carbon::parse('2026-09-01 14:00:00', 'UTC')); // 23:00 JST
        $this->assertTrue($event->isDue($this->app));

        $this->travelTo(Carbon::parse('2026-09-01 22:00:00', 'UTC')); // 翌07:00 JST
        $this->assertFalse($event->isDue($this->app));
    }

    // ------------------------------------------------------------------
    // 既知の制約
    // ------------------------------------------------------------------

    /**
     * 前提: 期日が当日の計画1件
     * 操作: 同じ基準日でバッチを2回実行する
     * 期待: 通知が2通入る
     *
     * ★ これは望ましい挙動ではなく、現状の挙動を記録するためのテスト。
     *
     * 重複を防ぐには「この計画のこのタイミングの通知を、もう送ったか」を
     * 判定する必要があるが、notifications テーブルには plan_id も timing も
     * 列として無い(data の JSON の中にある)。判定する材料が今の設計に無い。
     *
     * 自動実行は1日1回なので通常は起きない。ただし --date を使った
     * 追いつき実行や、途中で失敗したあとのやり直しでは確実に起きる。
     * 対策は列の追加か別テーブルが必要で、仕様の追加にあたるため
     * メンターへの確認事項として持ち越している。
     *
     * 状態の更新のほうは何度実行しても同じ結果になる
     * (test_すでにOverdueの計画を再度実行しても壊れない)。
     * 同じバッチの中で、繰り返しに強い処理と弱い処理が同居している。
     */
    public function test_同じ日に2回実行すると通知が重複する_既知の制約(): void
    {
        $this->plan('2026-09-01');

        $this->runBatch();
        $this->runBatch();

        $this->assertSame(2, DatabaseNotification::count());
    }
}
