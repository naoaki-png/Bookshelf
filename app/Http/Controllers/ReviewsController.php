<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewRequest;
use App\Models\Book;
use App\Models\Review;
use Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ReviewsController extends Controller
{
    /**
     * レビューを投稿する。
     *
     * 投稿者と対象書籍を添えて、レビューを1件作る。
     *
     * @param  ReviewRequest  $request
     * @param  Book  $book
     * @return RedirectResponse
     */
    public function store(ReviewRequest $request, Book $book): RedirectResponse
    {
        $data = $request->only('rating', 'comment');
        $user = Auth::user();
        $data['user_id'] = $user->id;
        $data['book_id'] = $book->id;
        Review::create($data);

        return redirect(route('books.show', $book))->with('success', 'レビューを投稿しました。');
    }

    /**
     * レビューの編集画面を表示する。
     *
     * @param  Review  $review
     * @return View
     */
    public function edit(Review $review): View
    {
        $this->authorize('update', $review);

        return view('reviews.edit', compact('review'));
    }

    /**
     * レビューを更新する。
     *
     * レビューは書籍詳細ページ内にあるので、更新後はその書籍の詳細へ戻す。
     *
     * @param  ReviewRequest  $request
     * @param  Review  $review
     * @return RedirectResponse
     */
    public function update(ReviewRequest $request, Review $review): RedirectResponse
    {
        $this->authorize('update', $review);
        $data = $request->only('rating', 'comment');
        $review->update($data);
        $book = $review->book;

        return redirect(route('books.show', $book))->with('success', 'レビューを更新しました。');
    }

    /**
     * レビューを削除する。
     *
     * @param  Review  $review
     * @return RedirectResponse
     */
    public function destroy(Review $review): RedirectResponse
    {
        $this->authorize('delete', $review);
        $book = $review->book;
        $review->delete();

        return redirect(route('books.show', $book))->with('success', 'レビューを削除しました。');
    }

    /**
     * レビューへのいいねを切り替える。
     *
     * 既にいいね済みなら取り消し、未いいねなら登録する。
     *
     * @param  Review  $review
     * @return RedirectResponse
     */
    public function like(Review $review): RedirectResponse
    {
        $user = Auth::user();
        $like = $user->reviewLikes()->where('review_id', $review->id)->first();

        if ($like) {
            $like->delete();
        } else {
            $user->reviewLikes()->create(['review_id' => $review->id]);
        }
        $book = $review->book;

        return redirect(route('books.show', $book));
    }
}
