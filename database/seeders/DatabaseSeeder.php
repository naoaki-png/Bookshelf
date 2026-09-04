<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            GenreSeeder::class,
            BookSeeder::class,
            ReadingPlanSeeder::class,
            ReviewSeeder::class,
            FavoriteSeeder::class,
            ReviewLikeSeeder::class,
        ]);

        // 通知はバッチ実行で作られる。Sail には cron が無くスケジュールが動かないため、
        // 採点時に通知機能を確認できるよう、シード時に1回だけ実行しておく。
        Artisan::call('reading-plans:remind');
    }
}
