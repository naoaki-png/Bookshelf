<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Book;
use App\Models\Review;
use App\Models\Genre;
use App\Http\Requests\BookRequest;
use Auth;

class BooksController extends Controller
{
    public function index()
    {
        $books = Book::paginate(10);

        return view('books.index', compact('books'));
    }
    public function show(Book $book)
    {
        $book->load('reviews');
        return view('books.show', compact('book'));

    }
    public function create()
    {
        $genres = Genre::all();
        return view('books.create', compact('genres'));
    }
    public function store(BookRequest $request)
    {
        $data = $request->only('title', 'author', 'isbn', 'description', 'published_date', 'image_url');
        $user = Auth::user();
        $data['user_id'] = $user->id;
        $book = Book::create($data);
        $book->genres()->sync($request->input('genres'));
        return redirect(route('books.index'))->with('success', '書籍を登録しました');
    }
    public function edit(Book $book)
    {
        $genres = Genre::all();


        return view('books.edit', compact('book', 'genres'));
    }
    public function update(Book $book, BookRequest $request)
    {
        $data = $request->only('title', 'author', 'isbn', 'description', 'published_date', 'image_url', 'genres');
        $book->update($data);
        $book->genres()->sync($request->input('genres'));
        return redirect(route('books.show', $book));
    }
    public function destroy(Book $book)
    {
        $book->delete();
        return redirect(route('books.index'));
    }

    //
}
