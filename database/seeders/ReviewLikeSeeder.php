<?php

namespace Database\Seeders;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReviewLikeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        $reviews = Review::with('bookUser')->get();

        foreach ($reviews as $review) {
            $authorId = $review->bookUser->user_id;

            $candidateUsers = $users->reject(fn ($user) => $user->id === $authorId);

            $likeCount = rand(0, 3);

            if ($likeCount === 0) {
                continue;
            }

            $likeCount = min($likeCount, $candidateUsers->count());

            $likers = $candidateUsers->random($likeCount);

            foreach ($likers as $user) {
                $user->likedReviews()->syncWithoutDetaching([$review->id]);
            }
        }

        //
    }
}
