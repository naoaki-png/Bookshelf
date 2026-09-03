<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookShowResource;
use Illuminate\Http\Request;
use App\Models\Book;
use App\Http\Resources\BookReviewResource;
use App\Http\Requests\ApiBookIndexRequest;
use App\Http\Resources\BookIndexResource;
use App\Http\Requests\ApiBookRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ApiBookController extends Controller
{
    /**
     * 書籍一覧を表示する
     *
     * @param  \App\Http\Requests\ApiBookIndexRequest  $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(ApiBookIndexRequest $request): AnonymousResourceCollection
    {
        $keyword = $request->input('keyword');
        $genreName = $request->input('genre');
        $perPage = $request->input('per_page', 10);
        $books = Book::query()->with('genres')->withAvg('reviews', 'rating')->withCount('reviews');
        if ($keyword) {
            $books->where('title', 'like', '%' . $keyword . '%');
        }
        if ($genreName) {
            $books->whereHas('genres', function ($query) use ($genreName) {
                $query->where('name', $genreName);
            });
        }
        $books = $books->paginate($perPage);

        return BookIndexResource::collection($books);
    }

    /**
     * 書籍を登録する
     *
     * @param  \App\Http\Requests\ApiBookRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(ApiBookRequest $request): JsonResponse
    {
        $data = $request->only(['title', 'author', 'isbn', 'description', 'published_date', 'image_url',]);
        $user = $request->user();
        $data['user_id'] = $user->id;
        $book = DB::transaction(function () use ($data, $request) {
            $book = Book::create($data);
            $book->genres()->sync($request->input('genres'));
            return $book;
        });
        return (new BookShowResource($book))->response()->setStatusCode(201);
    }

    /**
     * 書籍詳細を表示する
     *
     * @param  \App\Models\Book  $book
     * @return \App\Http\Resources\BookShowResource
     */
    public function show(Book $book): BookShowResource
    {
        $book->load('reviews', 'genres');
        return new BookShowResource($book);
    }

    /**
     * 書籍を更新する
     *
     * @param  \App\Http\Requests\ApiBookRequest  $request
     * @param  \App\Models\Book  $book
     * @return \App\Http\Resources\BookShowResource
     */
    public function update(ApiBookRequest $request, Book $book): BookShowResource
    {
        $this->authorize('update', $book);
        $data = $request->only('title', 'author', 'isbn', 'description', 'published_date', 'image_url');
        DB::transaction(function () use ($data, $book, $request) {
            $book->update($data);
            $book->genres()->sync($request->input('genres'));
        });
        return new BookShowResource($book);
    }

    /**
     * 書籍を削除する
     *
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\Response
     */
    public function destroy(Book $book): Response
    {
        $this->authorize('delete', $book);
        $book->delete();
        return response('', 204);
    }
    //
}
