<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Book;
use App\Models\Review;
use App\Models\Genre;
use App\Http\Requests\BookRequest;
use App\Http\Requests\BookIndexRequest;
use App\Http\Requests\IsbnSearchRequest;
use Illuminate\Support\Facades\Http;
use Auth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

class BooksController extends Controller
{
    public function index(BookIndexRequest $request)
    {
        $genres = Genre::all();
        $books = Book::withAvg('reviews', 'rating')->with('genres');

        $keyword = $request->input('keyword');
        if ($keyword) {
            $books->where(function ($q) use ($keyword) {
                $q->where('title', 'like', '%' . $keyword . '%')
                    ->orWhere('author', 'like', '%' . $keyword . '%');
            });
        }

        $genreId = $request->input('genre');
        if ($genreId) {
            $books->whereHas('genres', function ($query) use ($genreId) {
                $query->where('genres.id', $genreId);
            });
        }
        $sort = $request->input('sort') ?? 'newest';
        if ($sort === 'newest') {
            $books->orderByDesc('created_at')->orderByDesc('id');
        } elseif ($sort === 'oldest') {
            $books->orderBy('created_at')->orderBy('id');
        } elseif ($sort === 'rating') {
            $books->orderByDesc('reviews_avg_rating');
        } elseif ($sort === 'title') {
            $books->orderBy('title');
        }
        $books = $books->paginate(10)->withQueryString();
        return view('books.index', compact('books', 'genres'));
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
        $this->authorize('update', $book);
        $genres = Genre::all();


        return view('books.edit', compact('book', 'genres'));
    }
    public function update(Book $book, BookRequest $request)
    {
        $this->authorize('update', $book);
        $data = $request->only('title', 'author', 'isbn', 'description', 'published_date', 'image_url');
        $book->update($data);
        $book->genres()->sync($request->input('genres'));
        return redirect(route('books.show', $book));
    }
    public function destroy(Book $book)
    {
        $this->authorize('delete', $book);
        $book->delete();
        return redirect(route('books.index'));
    }

    public function searchByIsbn(IsbnSearchRequest $request)
    {
        $isbn = $request->validated()['isbn'];
        try {
            $response = Http::timeout(3)->get('https://www.googleapis.com/books/v1/volumes', ['q' => 'isbn:' . $isbn, 'key' => config('services.google_books.api_key'),]);
        } catch (ConnectionException $e) {
            return response()->json(['error' => '書籍情報の取得に失敗しました。時間をおいて再度お試しください。'], 502);
        }
        if ($response->failed()) {
            return response()->json(['error' => '書籍情報の取得に失敗しました。時間をおいて再度お試しください。'], 502);
        }
        if ($response->json('totalItems') == 0 || $response->json('items') == null) {
            return response()->json(['error' => '該当する書籍が見つかりませんでした。'], 404);
        }
        $volumeInfo = $response->json('items.0.volumeInfo');
        $length = strlen($volumeInfo['publishedDate'] ?? '');
        if ($length == 4) {
            $volumeInfo['publishedDate'] = $volumeInfo['publishedDate'] . '-01-01';
        } elseif ($length == 7) {
            $volumeInfo['publishedDate'] = $volumeInfo['publishedDate'] . '-01';
        }

        $imageUrl = $volumeInfo['imageLinks']['thumbnail'] ?? '';
        $imageUrl = Str::replaceStart('http://', 'https://', $imageUrl);
        return [
            'title' => $volumeInfo['title'] ?? null,
            'author' => implode('・', $volumeInfo['authors'] ?? []),
            'description' => $volumeInfo['description'] ?? null,
            'image_url' => $imageUrl,
            'published_date' => $volumeInfo['publishedDate'] ?? '',
        ];

    }
}
