<?php

namespace App\Console\Commands;

use App\Enums\ReadingPlanStatus;
use App\Models\ReadingPlan;
use App\Notifications\ReadingPlanReminder;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendReadingPlanReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reading-plans:remind {--date= : 基準日を指定するオプション。指定しない場合は本日の日付を指定。}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '読書計画のリマインダーを毎晩自動送信';

    /**
     * 読書計画のリマインダーを送信するコマンドを実行。
     *
     * @return int
     */
    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        $before = ReadingPlan::where('target_date', $date->copy()->addDays(3)->format('Y-m-d'))->where('status', '!=', ReadingPlanStatus::Completed)->with('book', 'user')->get();
        $due = ReadingPlan::where('target_date', $date->format('Y-m-d'))->where('status', '!=', ReadingPlanStatus::Completed)->with('book', 'user')->get();
        $after = ReadingPlan::where('target_date', $date->copy()->subDays(3)->format('Y-m-d'))->where('status', '!=', ReadingPlanStatus::Completed)->with('book', 'user')->get();
        foreach ($before as $plan) {
            $plan->user->notify(new ReadingPlanReminder($plan, 'three_days_before'));
        }
        foreach ($due as $plan) {
            $plan->user->notify(new ReadingPlanReminder($plan, 'on_due_date'));
        }
        foreach ($after as $plan) {
            $plan->user->notify(new ReadingPlanReminder($plan, 'three_days_after'));
        }
        ReadingPlan::where('target_date', '<', $date->format('Y-m-d'))->where('status', '!=', ReadingPlanStatus::Completed)->update(['status' => ReadingPlanStatus::Overdue]);

        return Command::SUCCESS;
    }
}
