<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FavoritesController extends Controller
{
    /**
     * ログイン中のユーザーがお気に入り登録した書籍の一覧を表示する。
     *
     * @return View
     */
    public function index(): View
    {
        $user = Auth::user();
        $books = $user->favoriteBooks()->paginate(10);

        return view('favorites.index', compact('books'));
    }

    /**
     * 書籍のお気に入り登録を切り替える。
     *
     * 既に登録済みなら解除し、未登録なら登録する。
     *
     * @param  Book  $book
     * @return RedirectResponse
     */
    public function toggle(Book $book): RedirectResponse
    {
        $user = Auth::user();
        $favorite = $user->favorites()->where('book_id', $book->id)->first();

        if ($favorite) {
            $favorite->delete();
        } else {
            $user->favorites()->create(['book_id' => $book->id]);
        }

        return back();
    }
}
