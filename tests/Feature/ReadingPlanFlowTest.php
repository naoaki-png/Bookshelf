<?php

namespace Tests\Feature;

use App\Enums\ReadingPlanStatus;
use App\Models\Book;
use App\Models\ReadingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 読書計画(/reading-plans)の一覧・作成・編集・更新・削除・読了を確認する。
 *
 * このコントローラーは7メソッドあり、性質が3種類に分かれる。
 *
 *   index / create        取得して表示するだけ。DB は変わらない
 *   edit                  表示だけだが Policy を通る
 *   store / update /      DB を変える。FormRequest がバリデーションを持つ
 *   destroy / complete
 *
 * 確認の軸も3つある。
 *
 * 1. 他人の計画に触れないこと。
 *    edit / update / destroy / complete は $this->authorize() を通す。
 *    ReadingPlanPolicy は user_id の一致だけを見ているので、
 *    「他人の id を URL に直打ちしたら 403」を各メソッドで固定する。
 *    ルートが /reading-plans/{plan} で id が丸見えなので、実際に起こりうる。
 *
 * 2. index は Auth::user()->readingPlans() から始まる。
 *    出発点がユーザーなので本来は混ざらないが、途中で ReadingPlan::query() に
 *    書き換えると全員分が出る。他ユーザーの計画を必ず1件置いて監視する。
 *
 * 3. status は enum にキャストされている。
 *    絞り込みはクエリ文字列(文字列)で来て、DB には文字列で入り、
 *    取り出すと ReadingPlanStatus になる。この往復が壊れていないかを見る。
 *
 * なお reading_plans には unique(['user_id','book_id']) が張ってあり、
 * StoreReadingPlanRequest 側にも同じ意味の unique ルールがある。
 * DB 制約に到達する前にバリデーションで止まることを確認しておく
 * (到達すると QueryException になり、try-catch の「予期せぬエラー」に化ける)。
 */
class ReadingPlanFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 指定ユーザーの計画を1件作る。
     *
     * ReadingPlanFactory は book_id に Book::factory() を置いているので、
     * 呼ぶたびに別の本になる。unique(['user_id','book_id']) を踏まないのはそのため。
     * 同じ本を使いたいテストでは book_id を明示的に渡す。
     */
    private function planFor(User $user, array $attributes = []): ReadingPlan
    {
        return ReadingPlan::factory()->create($attributes + ['user_id' => $user->id]);
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    /**
     * 前提: 自分の計画2件、他ユーザーの計画1件
     * 操作: GET /reading-plans
     * 期待: 200 で、自分の2件だけがビューに渡る
     */
    public function test_一覧には自分の読書計画だけが表示される(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = [$this->planFor($user)->id, $this->planFor($user)->id];
        $theirs = $this->planFor($other);

        $plans = $this->actingAs($user)->get('/reading-plans')->assertOk()->viewData('readingPlans');

        $this->assertCount(2, $plans);
        $this->assertEqualsCanonicalizing($mine, $plans->pluck('id')->all());
        $this->assertNull($plans->firstWhere('id', $theirs->id));
    }

    /**
     * 前提: 期日が 10日後 → 1日後 → 5日後 の順で作られた3件
     * 操作: GET /reading-plans
     * 期待: 1日後 → 5日後 → 10日後 の順に並ぶ
     *
     * 作成順と期日順をわざとずらしている。orderBy('target_date') が抜けると
     * id 順(= 作成順)で返るため、揃えてしまうと抜けに気づけない。
     */
    public function test_一覧は期日の昇順で並ぶ(): void
    {
        $user = User::factory()->create();

        $late = $this->planFor($user, ['target_date' => now()->addDays(10)->toDateString()]);
        $soon = $this->planFor($user, ['target_date' => now()->addDay()->toDateString()]);
        $mid = $this->planFor($user, ['target_date' => now()->addDays(5)->toDateString()]);

        $plans = $this->actingAs($user)->get('/reading-plans')->viewData('readingPlans');

        $this->assertSame([$soon->id, $mid->id, $late->id], $plans->pluck('id')->all());
    }

    /**
     * 前提: 進行中1件・完了1件・期日遅れ1件
     * 操作: GET /reading-plans?status=completed
     * 期待: 完了の1件だけが返り、currentStatus に 'completed' が渡る
     *
     * currentStatus はビューの <select> で @selected() に使われている。
     * 絞り込みが効いていても currentStatus を渡し忘れると、
     * 絞り込み後の画面でプルダウンだけ「すべて」に戻って見える。
     */
    public function test_一覧はステータスで絞り込める(): void
    {
        $user = User::factory()->create();

        $this->planFor($user);
        $completed = $this->planFor($user, [
            'status' => ReadingPlanStatus::Completed,
            'completed_at' => now(),
        ]);
        $this->planFor($user, ['status' => ReadingPlanStatus::Overdue]);

        $response = $this->actingAs($user)->get('/reading-plans?status=completed')->assertOk();

        $plans = $response->viewData('readingPlans');
        $this->assertCount(1, $plans);
        $this->assertSame($completed->id, $plans->first()->id);
        $this->assertSame('completed', $response->viewData('currentStatus'));
    }

    /**
     * 前提: 3種類のステータスが1件ずつ
     * 操作: GET /reading-plans(status なし)
     * 期待: 3件すべてが返り、currentStatus は空文字
     *
     * 絞り込みは when($currentStatus, ...) で書かれている。
     * status を付けない場合は '' が入り、'' は falsy なので where が付かない。
     * ここが when() ではなく where() で無条件に書かれていると 0件になる。
     */
    public function test_ステータス未指定なら全件が表示される(): void
    {
        $user = User::factory()->create();

        $this->planFor($user);
        $this->planFor($user, ['status' => ReadingPlanStatus::Completed, 'completed_at' => now()]);
        $this->planFor($user, ['status' => ReadingPlanStatus::Overdue]);

        $response = $this->actingAs($user)->get('/reading-plans')->assertOk();

        $this->assertCount(3, $response->viewData('readingPlans'));
        $this->assertSame('', $response->viewData('currentStatus'));
    }

    /**
     * 前提: 3種類のステータスが1件ずつ
     * 操作: GET /reading-plans
     * 期待: 画面に3つのラベル(進行中 / 完了 / 期日遅れ)が出る
     *
     * ReadingPlanStatus::label() と badgeClass() はビューからしか呼ばれない。
     * viewData だけを見るテストでは一度も実行されないため、ここは描画後の HTML を見る。
     * match に列挙漏れがあると UnhandledMatchError で 500 になる。
     */
    public function test_一覧にステータスのラベルが表示される(): void
    {
        $user = User::factory()->create();

        $this->planFor($user);
        $this->planFor($user, ['status' => ReadingPlanStatus::Completed, 'completed_at' => now()]);
        $this->planFor($user, ['status' => ReadingPlanStatus::Overdue]);

        $this->actingAs($user)->get('/reading-plans')
            ->assertOk()
            ->assertSee('進行中')
            ->assertSee('完了')
            ->assertSee('期日遅れ');
    }

    /**
     * 前提: 計画0件
     * 操作: GET /reading-plans
     * 期待: 200 で「該当する読書計画はありません。」が出る
     */
    public function test_読書計画が0件でも一覧が表示される(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reading-plans')
            ->assertOk()
            ->assertSee('該当する読書計画はありません。');
    }

    // ------------------------------------------------------------------
    // create
    // ------------------------------------------------------------------

    /**
     * 前提: 書籍が3冊
     * 操作: GET /reading-plans/create
     * 期待: 200 で書籍3冊がタイトル順に渡り、列は id / title / author の3つだけ
     *
     * select() で列を絞っているので、ビューが他の列を触るようになったら null になる。
     * 列の集合まで固定しておくと、select を消して全列取得に戻したときに気づける。
     */
    public function test_作成画面に書籍がタイトル順で渡る(): void
    {
        $user = User::factory()->create();

        Book::factory()->create(['title' => 'CCC']);
        Book::factory()->create(['title' => 'AAA']);
        Book::factory()->create(['title' => 'BBB']);

        $books = $this->actingAs($user)->get('/reading-plans/create')->assertOk()->viewData('books');

        $this->assertSame(['AAA', 'BBB', 'CCC'], $books->pluck('title')->all());
        $this->assertSame(['id', 'title', 'author'], array_keys($books->first()->getAttributes()));
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    /**
     * 前提: ログイン済み、書籍1冊
     * 操作: POST /reading-plans(book_id と target_date)
     * 期待: 自分の計画として登録され、一覧へリダイレクト
     *
     * user_id はフォームから来ない。$fillable にも入っていない。
     * Auth::user()->readingPlans()->create() のリレーション経由で入る仕組みなので、
     * assertDatabaseHas で user_id まで見ないと「誰のものになったか」を確認できない。
     * status は DB のデフォルト 'in_progress' が入る。
     */
    public function test_読書計画を登録できる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $target = now()->addDays(7)->toDateString();

        $this->actingAs($user)
            ->post('/reading-plans', ['book_id' => $book->id, 'target_date' => $target])
            ->assertRedirect(route('reading-plans.index'))
            ->assertSessionHas('success', '読書計画を登録しました');

        $this->assertDatabaseHas('reading_plans', [
            'user_id' => $user->id,
            'book_id' => $book->id,
            'target_date' => $target,
            'status' => 'in_progress',
            'completed_at' => null,
        ]);
    }

    /**
     * 前提: ログイン済み
     * 操作: POST /reading-plans(空)
     * 期待: book_id と target_date の両方でエラー
     */
    public function test_書籍と期日が未入力なら登録できない(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reading-plans.create'))
            ->post('/reading-plans', [])
            ->assertRedirect(route('reading-plans.create'))
            ->assertSessionHasErrors(['book_id', 'target_date']);

        $this->assertDatabaseCount('reading_plans', 0);
    }

    /**
     * 前提: 存在しない book_id
     * 操作: POST /reading-plans
     * 期待: book_id でエラー
     *
     * exists ルールが無いと外部キー制約まで到達して QueryException になり、
     * コントローラーの catch が「予期せぬエラー」として握りつぶす。
     * ユーザーには原因が伝わらないので、バリデーションで止まることを固定する。
     */
    public function test_存在しない書籍では登録できない(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('reading-plans.create'))
            ->post('/reading-plans', [
                'book_id' => 999999,
                'target_date' => now()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('book_id');

        $this->assertDatabaseCount('reading_plans', 0);
    }

    /**
     * 前提: 昨日の日付
     * 操作: POST /reading-plans
     * 期待: target_date でエラー
     *
     * after_or_equal:today の境界。今日ちょうどが通ることは次のテストで見る。
     */
    public function test_過去の期日では登録できない(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->actingAs($user)
            ->from(route('reading-plans.create'))
            ->post('/reading-plans', [
                'book_id' => $book->id,
                'target_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('target_date');

        $this->assertDatabaseCount('reading_plans', 0);
    }

    /**
     * 前提: 今日の日付
     * 操作: POST /reading-plans
     * 期待: 登録できる
     *
     * after_or_equal は「以降」なので今日は通る。after にすり替わると落ちる。
     */
    public function test_今日を期日にして登録できる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->actingAs($user)
            ->post('/reading-plans', [
                'book_id' => $book->id,
                'target_date' => now()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reading_plans', ['user_id' => $user->id, 'book_id' => $book->id]);
    }

    /**
     * 前提: 同じ本を自分がすでに計画に入れている
     * 操作: POST /reading-plans(同じ book_id)
     * 期待: book_id でエラーになり、DB は1件のまま
     *
     * DB にも unique(['user_id','book_id']) があるので、バリデーションが抜けても
     * 二重登録はされない。ただしその場合は QueryException → catch → 「予期せぬエラー」
     * になり、ユーザーに出る文言が変わる。ここで見たいのは
     * 「重複だと分かるエラーが返ること」なので assertSessionHasErrors で確認する。
     */
    public function test_同じ書籍を二重に登録できない(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $this->planFor($user, ['book_id' => $book->id]);

        $this->actingAs($user)
            ->from(route('reading-plans.create'))
            ->post('/reading-plans', [
                'book_id' => $book->id,
                'target_date' => now()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('book_id');

        $this->assertDatabaseCount('reading_plans', 1);
    }

    /**
     * 前提: 他ユーザーが同じ本を計画に入れている
     * 操作: POST /reading-plans(同じ book_id)
     * 期待: 登録できる
     *
     * unique ルールは ->where('user_id', $this->user()->id) で自分に限定している。
     * この where が抜けると「誰かが先に登録した本は他の誰も登録できない」になる。
     * 前のテストと対で、unique の効き方が「自分の中だけ」であることを固定する。
     */
    public function test_他ユーザーが計画中の書籍でも登録できる(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::factory()->create();
        $this->planFor($other, ['book_id' => $book->id]);

        $this->actingAs($user)
            ->post('/reading-plans', [
                'book_id' => $book->id,
                'target_date' => now()->addDay()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reading_plans', ['user_id' => $user->id, 'book_id' => $book->id]);
        $this->assertDatabaseCount('reading_plans', 2);
    }

    // ------------------------------------------------------------------
    // edit
    // ------------------------------------------------------------------

    /**
     * 前提: 自分の計画1件
     * 操作: GET /reading-plans/{id}/edit
     * 期待: 200 で、その計画がビューに渡る
     *
     * ビューに渡る変数名は $readingPlan(引数名の $plan ではない)。
     * 名前を変えるとビューが壊れるので、変数名まで固定する。
     */
    public function test_自分の読書計画の編集画面を開ける(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor($user);

        $viewPlan = $this->actingAs($user)
            ->get('/reading-plans/' . $plan->id . '/edit')
            ->assertOk()
            ->viewData('readingPlan');

        $this->assertSame($plan->id, $viewPlan->id);
    }

    /**
     * 前提: 他ユーザーの計画1件
     * 操作: GET /reading-plans/{他人のid}/edit
     * 期待: 403
     */
    public function test_他人の読書計画の編集画面は開けない(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor(User::factory()->create());

        $this->actingAs($user)->get('/reading-plans/' . $plan->id . '/edit')->assertForbidden();
    }

    // ------------------------------------------------------------------
    // update
    // ------------------------------------------------------------------

    /**
     * 前提: 自分の計画1件
     * 操作: PUT /reading-plans/{id}(新しい期日)
     * 期待: 期日が変わり、一覧へリダイレクト
     */
    public function test_自分の読書計画の期日を更新できる(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor($user, ['target_date' => now()->addDay()->toDateString()]);
        $newDate = now()->addDays(20)->toDateString();

        $this->actingAs($user)
            ->put('/reading-plans/' . $plan->id, ['target_date' => $newDate])
            ->assertRedirect(route('reading-plans.index'))
            ->assertSessionHas('success', '読書計画を更新しました');

        $this->assertDatabaseHas('reading_plans', ['id' => $plan->id, 'target_date' => $newDate]);
    }

    /**
     * 前提: 自分の計画1件
     * 操作: PUT /reading-plans/{id}(過去の期日)
     * 期待: エラーになり、期日は変わらない
     */
    public function test_過去の期日には更新できない(): void
    {
        $user = User::factory()->create();
        $original = now()->addDays(5)->toDateString();
        $plan = $this->planFor($user, ['target_date' => $original]);

        $this->actingAs($user)
            ->from(route('reading-plans.edit', $plan))
            ->put('/reading-plans/' . $plan->id, ['target_date' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('target_date');

        $this->assertDatabaseHas('reading_plans', ['id' => $plan->id, 'target_date' => $original]);
    }

    /**
     * 前提: 他ユーザーの計画1件
     * 操作: PUT /reading-plans/{他人のid}
     * 期待: 403 で、期日は変わらない
     *
     * 403 だけでなく DB も見る。authorize() より先に $plan->update() が動く並びだと、
     * 403 を返しながら更新は済んでいる、という状態がありうる。
     */
    public function test_他人の読書計画は更新できない(): void
    {
        $user = User::factory()->create();
        $original = now()->addDays(5)->toDateString();
        $plan = $this->planFor(User::factory()->create(), ['target_date' => $original]);

        $this->actingAs($user)
            ->put('/reading-plans/' . $plan->id, ['target_date' => now()->addDays(20)->toDateString()])
            ->assertForbidden();

        $this->assertDatabaseHas('reading_plans', ['id' => $plan->id, 'target_date' => $original]);
    }

    // ------------------------------------------------------------------
    // destroy
    // ------------------------------------------------------------------

    /**
     * 前提: 自分の計画1件
     * 操作: DELETE /reading-plans/{id}
     * 期待: DB から消え、一覧へリダイレクト
     */
    public function test_自分の読書計画を削除できる(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor($user);

        $this->actingAs($user)
            ->delete('/reading-plans/' . $plan->id)
            ->assertRedirect(route('reading-plans.index'))
            ->assertSessionHas('success', '読書計画を削除しました');

        $this->assertDatabaseMissing('reading_plans', ['id' => $plan->id]);
    }

    /**
     * 前提: 他ユーザーの計画1件
     * 操作: DELETE /reading-plans/{他人のid}
     * 期待: 403 で、レコードは残る
     */
    public function test_他人の読書計画は削除できない(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor(User::factory()->create());

        $this->actingAs($user)->delete('/reading-plans/' . $plan->id)->assertForbidden();

        $this->assertDatabaseHas('reading_plans', ['id' => $plan->id]);
    }

    // ------------------------------------------------------------------
    // complete
    // ------------------------------------------------------------------

    /**
     * 前提: 進行中の自分の計画1件
     * 操作: POST /reading-plans/{id}/complete
     * 期待: status が completed になり completed_at が入る
     *
     * status は $fillable に入っていない。complete() はプロパティに直接代入して
     * save() しているので、mass assignment とは別の経路で書き込んでいる。
     * completed_at も同時に入ることまで見ないと、片方だけ動いていても通ってしまう。
     */
    public function test_自分の読書計画を読了にできる(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor($user);

        $this->actingAs($user)
            ->post('/reading-plans/' . $plan->id . '/complete')
            ->assertRedirect(route('reading-plans.index'))
            ->assertSessionHas('success', '読書計画を完了しました');

        $plan->refresh();
        $this->assertSame(ReadingPlanStatus::Completed, $plan->status);
        $this->assertNotNull($plan->completed_at);
    }

    /**
     * 前提: 期日遅れの自分の計画1件
     * 操作: POST /reading-plans/{id}/complete
     * 期待: 読了にできる
     *
     * 判定は「Completed かどうか」だけなので、Overdue からも完了へ進める。
     * ここを「InProgress のときだけ」に変えると、期日を過ぎた本を
     * 読み終えても完了にできなくなる。仕様として固定しておく。
     */
    public function test_期日遅れの読書計画も読了にできる(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor($user, ['status' => ReadingPlanStatus::Overdue]);

        $this->actingAs($user)
            ->post('/reading-plans/' . $plan->id . '/complete')
            ->assertSessionHas('success', '読書計画を完了しました');

        $this->assertSame(ReadingPlanStatus::Completed, $plan->refresh()->status);
    }

    /**
     * 前提: すでに完了済みの自分の計画1件(10日前に完了)
     * 操作: POST /reading-plans/{id}/complete
     * 期待: エラーメッセージが返り、completed_at は上書きされない
     *
     * 二重送信で完了日が「今」に書き換わると、いつ読み終えたかの記録が消える。
     * リダイレクト先は成功時と同じなので、flash の中身でしか区別できない。
     */
    public function test_完了済みの読書計画は再度読了にできない(): void
    {
        $user = User::factory()->create();
        $completedAt = now()->subDays(10);
        $plan = $this->planFor($user, [
            'status' => ReadingPlanStatus::Completed,
            'completed_at' => $completedAt,
        ]);

        $this->actingAs($user)
            ->post('/reading-plans/' . $plan->id . '/complete')
            ->assertRedirect(route('reading-plans.index'))
            ->assertSessionHas('error', 'この計画はすでに完了済みです');

        $this->assertSame(
            $completedAt->toDateTimeString(),
            $plan->refresh()->completed_at->toDateTimeString()
        );
    }

    /**
     * 前提: 他ユーザーの進行中の計画1件
     * 操作: POST /reading-plans/{他人のid}/complete
     * 期待: 403 で、進行中のまま
     */
    public function test_他人の読書計画は読了にできない(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor(User::factory()->create());

        $this->actingAs($user)->post('/reading-plans/' . $plan->id . '/complete')->assertForbidden();

        $this->assertSame(ReadingPlanStatus::InProgress, $plan->refresh()->status);
    }

    // ------------------------------------------------------------------
    // モデル
    // ------------------------------------------------------------------

    /**
     * 前提: 計画1件
     * 期待: user / book のリレーションが引ける。status は enum、target_date は Carbon になる
     *
     * ビューは $plan->book->title と $plan->target_date->format() を呼んでいる。
     * $casts から 'date:Y-m-d' が抜けると target_date は文字列のままになり、
     * ->format() が「文字列にメソッド呼び出し」で 500 になる。
     * DB から取り直しているのは、作った直後のインスタンスだと
     * factory に渡した値がそのまま残っていてキャストを通らないことがあるため。
     */
    public function test_読書計画はユーザーと書籍を持ちステータスと期日がキャストされる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $plan = $this->planFor($user, ['book_id' => $book->id]);

        $fresh = ReadingPlan::find($plan->id);

        $this->assertSame($user->id, $fresh->user->id);
        $this->assertSame($book->id, $fresh->book->id);
        $this->assertInstanceOf(ReadingPlanStatus::class, $fresh->status);
        $this->assertInstanceOf(Carbon::class, $fresh->target_date);
    }
}
