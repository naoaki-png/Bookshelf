<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Book;
use App\Models\BookUser;
use App\Models\Review;

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
            5 => ['とても面白かったです。何度でも読み返したい一冊です。', '期待以上の内容で大満足でした。', '文句なしの傑作だと思います。'],
            4 => ['読みやすく、学びの多い本でした。', 'とても良い内容でしたが、一部冗長に感じました。', 'おすすめできる一冊です。'],
            3 => ['普通に読める内容でした。', '悪くはないですが、特別感動はしませんでした。', '合う人には合うと思います。'],
        ];

        foreach ($books as $book) {
            $reviewCount = rand(2, 4);

            $reviewers = $users->random($reviewCount);

            foreach ($reviewers as $user) {
                $bookUser = BookUser::firstOrCreate([
                    'user_id' => $user->id,
                    'book_id' => $book->id,
                ]);

                $rating = rand(3, 5);
                $comment = $comments[$rating][array_rand($comments[$rating])];

                Review::create([
                    'book_user_id' => $bookUser->id,
                    'rating' => $rating,
                    'comment' => $comment,
                ]);
            }
        }
    }
}
