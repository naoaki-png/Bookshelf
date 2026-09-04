<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookIndexRequest;
use App\Http\Requests\BookRequest;
use App\Http\Requests\IsbnSearchRequest;
use App\Models\Book;
use App\Models\Genre;
use Auth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BooksController extends Controller
{
    /**
     * 書籍の一覧を表示する。
     *
     * キーワード・ジャンル・並び順で絞り込み、10件ずつページ送りする。
     *
     * @param  BookIndexRequest  $request
     * @return View
     */
    public function index(BookIndexRequest $request): View
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

    /**
     * 書籍の詳細を表示する。
     *
     * @param  Book  $book
     * @return View
     */
    public function show(Book $book): View
    {
        $book->load('reviews');

        return view('books.show', compact('book'));
    }

    /**
     * 書籍の新規登録画面を表示する。
     *
     * @return View
     */
    public function create(): View
    {
        $genres = Genre::all();

        return view('books.create', compact('genres'));
    }

    /**
     * 書籍を新規登録する。
     *
     * 書籍本体の作成とジャンルの紐付けを1つのトランザクションで扱う。
     *
     * @param  BookRequest  $request
     * @return RedirectResponse
     */
    public function store(BookRequest $request): RedirectResponse
    {
        $data = $request->only('title', 'author', 'isbn', 'description', 'published_date', 'image_url');
        $user = Auth::user();
        $data['user_id'] = $user->id;
        DB::transaction(function () use ($data, $request) {
            $book = Book::create($data);
            $book->genres()->sync($request->input('genres'));
        });

        return redirect(route('books.index'))->with('success', '書籍を登録しました');
    }

    /**
     * 書籍の編集画面を表示する。
     *
     * @param  Book  $book
     * @return View
     */
    public function edit(Book $book): View
    {
        $this->authorize('update', $book);
        $genres = Genre::all();

        return view('books.edit', compact('book', 'genres'));
    }

    /**
     * 書籍を更新する。
     *
     * 書籍本体の更新とジャンルの紐付けを1つのトランザクションで扱う。
     *
     * @param  Book  $book
     * @param  BookRequest  $request
     * @return RedirectResponse
     */
    public function update(Book $book, BookRequest $request): RedirectResponse
    {
        $this->authorize('update', $book);
        $data = $request->only('title', 'author', 'isbn', 'description', 'published_date', 'image_url');
        DB::transaction(function () use ($data, $book, $request) {
            $book->update($data);
            $book->genres()->sync($request->input('genres'));
        });

        return redirect(route('books.show', $book));
    }

    /**
     * 書籍を削除する。
     *
     * @param  Book  $book
     * @return RedirectResponse
     */
    public function destroy(Book $book): RedirectResponse
    {
        $this->authorize('delete', $book);
        $book->delete();

        return redirect(route('books.index'));
    }

    /**
     * ISBN から書籍情報を検索する。
     *
     * Google Books API を呼び出し、登録フォームに流し込む値を返す。
     * 取得に失敗した場合と該当が無い場合はエラーを JSON で返すため、
     * 戻り値は JsonResponse と配列の2種類になる。
     *
     * @param  IsbnSearchRequest  $request
     * @return JsonResponse|array<string, string|null>
     */
    public function searchByIsbn(IsbnSearchRequest $request): JsonResponse|array
    {
        $isbn = $request->validated()['isbn'];
        try {
            $response = Http::timeout(3)->get('https://www.googleapis.com/books/v1/volumes', ['q' => 'isbn:' . $isbn, 'key' => config('services.google_books.api_key')]);
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
