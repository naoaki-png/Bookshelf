<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\BookUser;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        $books = Book::all();

        $comments = [
            1 => '期待していた内容とは違いました。',
            2 => 'もう少し具体的な説明がほしいと感じました。',
            3 => '普通に読める内容でした。',
            4 => '読みやすく、学びの多い本でした。',
            5 => 'とても面白かったです。何度でも読み返したい一冊です。',
        ];

        foreach ($books as $book) {
            $reviewCount = rand(2, 4);
            $reviewers = $users->random($reviewCount);

            foreach ($reviewers as $user) {
                $bookUser = BookUser::firstOrCreate([
                    'user_id' => $user->id,
                    'book_id' => $book->id,
                ]);

                $rating = rand(1, 5);

                Review::create([
                    'book_user_id' => $bookUser->id,
                    'rating' => $rating,
                    'comment' => $comments[$rating],
                ]);
            }
        }
    }
}
