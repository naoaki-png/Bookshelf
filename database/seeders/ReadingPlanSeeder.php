<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\ReadingPlan;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReadingPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        $books = Book::all();

        foreach ($users as $user) {
            $selected = $books->random(5);
            ReadingPlan::factory()->dueInDays(3)->for($user)->for($selected[0])->create();
            ReadingPlan::factory()->dueInDays(0)->for($user)->for($selected[1])->create();
            ReadingPlan::factory()->dueInDays(-3)->for($user)->for($selected[2])->create();
            ReadingPlan::factory()->completed()->for($user)->for($selected[3])->create();
            ReadingPlan::factory()->overdue()->for($user)->for($selected[4])->create();
        }
    }
}
