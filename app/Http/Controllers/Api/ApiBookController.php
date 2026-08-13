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

class ApiBookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ApiBookIndexRequest $request)
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
     * Store a newly created resource in storage.
     */
    public function store(ApiBookRequest $request)
    {
        $data = $request->only(['title', 'author', 'isbn', 'description', 'published_date', 'image_url',]);
        $user = $request->user();
        $data['user_id'] = $user->id;
        $book = Book::create($data);
        $book->genres()->sync($request->input('genres'));
        return (new BookShowResource($book))->response()->setStatusCode(201);

        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Book $book)
    {
        $book->load('reviews', 'genres');
        return new BookShowResource($book);
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ApiBookRequest $request, Book $book)
    {
        $this->authorize('update', $book);
        $data = $request->only('title', 'author', 'isbn', 'description', 'published_date', 'image_url');
        $book->update($data);
        $book->genres()->sync($request->input('genres'));
        return new BookShowResource($book);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Book $book)
    {
        $this->authorize('delete', $book);
        $book->delete();
        return response('', 204);
    }
    //
}
