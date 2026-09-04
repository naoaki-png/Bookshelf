<?php

namespace Database\Seeders;

use App\Enums\ReadingPlanStatus;
use App\Models\Book;
use App\Models\ReadingPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ReadingPlanSeeder extends Seeder
{
    /**
     * 読書計画のダミーデータを投入する。
     *
     * 採点時に読書計画と通知の各挙動を確認できるよう、状態と期日を固定で組む。
     * 日付は Carbon::today() 起点の相対指定なので、実行日が変わっても
     * 同じシナリオが再現される。
     *
     * reading_plans は UNIQUE(user_id, book_id) のため、1ユーザーにつき
     * 書籍1冊に計画は1件。書籍は11冊なので計画も最大11件になる。
     */
    public function run(): void
    {
        $today = Carbon::today();

        // 期日までの残り日数。0 と +3 で通知が発火し、+1 / +2 / +4 では発火しない。
        $inProgressOffsets = [0, 1, 2, 3, 4];

        // 期日からの経過日数。-3 で通知が発火し、-1 / -2 / -4 では発火しない。
        $overdueOffsets = [-1, -2, -3, -4];

        // 完了済み。通知の対象外。
        $completedOffsets = [-10, -20];

        $emails = [
            'yamada@example.com',
            'suzuki@example.com',
            'tanaka@example.com',
        ];

        $books = Book::orderBy('id')->get();

        foreach ($emails as $email) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                continue;
            }

            $index = 0;

            foreach ($inProgressOffsets as $offset) {
                ReadingPlan::factory()
                    ->for($user)
                    ->for($books[$index++])
                    ->create([
                        'target_date' => $today->copy()->addDays($offset),
                        'completed_at' => null,
                        'status' => ReadingPlanStatus::InProgress,
                    ]);
            }

            foreach ($overdueOffsets as $offset) {
                ReadingPlan::factory()
                    ->for($user)
                    ->for($books[$index++])
                    ->create([
                        'target_date' => $today->copy()->addDays($offset),
                        'completed_at' => null,
                        'status' => ReadingPlanStatus::Overdue,
                    ]);
            }

            foreach ($completedOffsets as $offset) {
                $targetDate = $today->copy()->addDays($offset);

                ReadingPlan::factory()
                    ->for($user)
                    ->for($books[$index++])
                    ->create([
                        'target_date' => $targetDate,
                        'completed_at' => $targetDate->copy()->subDay(),
                        'status' => ReadingPlanStatus::Completed,
                    ]);
            }
        }
    }
}
