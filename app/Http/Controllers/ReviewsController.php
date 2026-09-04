<?php

namespace App\Http\Controllers;

use Auth;
use App\Models\Review;
use App\Http\Requests\ReviewRequest;
use App\Models\Book;
use App\Models\BookUser;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class ReviewsController extends Controller
{
    /**
     * レビューを投稿する。
     *
     * 読書記録(book_users)が無ければ作成し、そこにレビューを紐付ける。
     * 2つの書き込みが揃って初めて意味を持つため、1つのトランザクションで扱う。
     *
     * @param  \App\Http\Requests\ReviewRequest  $request
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(ReviewRequest $request, Book $book): RedirectResponse
    {
        $data = $request->only('rating', 'comment');
        $user = Auth::user();
        DB::transaction(function () use ($data, $user, $book) {
            $bookUser = BookUser::firstOrCreate([
                'user_id' => $user->id,
                'book_id' => $book->id,
            ]);
            $data['book_user_id'] = $bookUser->id;
            Review::create($data);
        });
        return redirect(route('books.show', $book))->with('success', 'レビューを投稿しました');
    }

    /**
     * レビューの編集画面を表示する。
     *
     * @param  \App\Models\Review  $review
     * @return \Illuminate\View\View
     */
    public function edit(Review $review): View
    {
        $this->authorize('update', $review);
        $review->book = $review->bookUser->book;

        return view('reviews.edit', compact('review'));
    }

    /**
     * レビューを更新する。
     *
     * 更新したレビューの位置まで書籍詳細ページを開き直す。
     *
     * @param  \App\Http\Requests\ReviewRequest  $request
     * @param  \App\Models\Review  $review
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(ReviewRequest $request, Review $review): RedirectResponse
    {
        $this->authorize('update', $review);
        $data = $request->only('rating', 'comment');
        $review->update($data);
        $book = $review->bookUser->book;
        return redirect(route('books.show', $book) . '#review-' . $review->id);
    }

    /**
     * レビューを削除する。
     *
     * @param  \App\Models\Review  $review
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Review $review): RedirectResponse
    {
        $this->authorize('delete', $review);
        $book = $review->bookUser->book;
        $review->delete();
        return redirect(route('books.show', $book) . '#review-section');
    }

    /**
     * レビューへのいいねを切り替える。
     *
     * 既にいいね済みなら取り消し、未いいねなら登録する。
     *
     * @param  \App\Models\Review  $review
     * @return \Illuminate\Http\RedirectResponse
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
        $book = $review->bookUser->book;
        return redirect(route('books.show', $book) . '#review-' . $review->id);
    }
}
