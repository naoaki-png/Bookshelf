<?php

namespace App\Http\Controllers;
use App\Models\Book;
use App\Models\Favorite;
use Auth;

use Illuminate\Http\Request;

class FavoritesController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $books = $user->favoriteBooks()->paginate(10);
        return view('favorites.index', compact('books'));
    }
    public function toggle(Book $book)
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
    //
}
