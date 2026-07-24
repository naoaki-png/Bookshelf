<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Auth;
use App\Models\Review;
use App\Http\Requests\ReviewRequest;
use App\Models\Book;
use App\Models\BookUser;

class ReviewsController extends Controller
{
    public function store(ReviewRequest $request, Book $book)
    {
        $data = $request->only('rating', 'comment');
        $user = Auth::user();
        $bookUser = BookUser::firstOrCreate([
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
        $data['book_user_id'] = $bookUser->id;
        $review = Review::create($data);
        return redirect(route('books.show', $book))->with('success', 'レビューを投稿しました');

    }
    public function edit(Review $review, Book $book)
    {
        $this->authorize('update', $review);
        $review->book = $review->bookUser->book;

        return view('reviews.edit', compact('review'));


    }
    public function update(ReviewRequest $request, Review $review)
    {
        $this->authorize('update', $review);
        $data = $request->only('rating', 'comment');
        $review->update($data);
        $book = $review->bookUser->book;
        return redirect(route('books.show', $book) . '#review-' . $review->id);

    }
    public function destroy(Review $review)
    {
        $this->authorize('delete', $review);
        $book = $review->bookUser->book;
        $review->delete();
        return redirect(route('books.show', $book) . '#review-section');
    }

    public function like(Review $review)
    {
        $user = Auth::user();
        $like = $user->reviewLikes()->where('review_id', $review->id)->first();

        if ($like) {
            $like->delete();
        } else {
            $user->reviewLikes()->create(['review_id' => $review->id]);
        }
        $book = $review->bookUser->book;
        return redirect(route('books.show', $book) . '#review-' . $review->id);
    }
    //
}
