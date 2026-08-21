<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Auth;

class ReportsController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $reviews = $user->reviews()
            ->with('bookUser.book.genres')
            ->get();

        $stats = [
            'summary' => [
                'total_reviews' => $reviews->count(),
                'books_read' => $reviews->pluck('bookUser.book_id')->unique()->count(),
                'average_rating' => $reviews->avg('rating') ?? 0,
            ],
            'rating_distribution' => collect(range(1, 5))->map(fn($star) => $reviews->where('rating', $star)->count()),
            // TODO: 4星以上 → 評価の高い順 → 上位5件。[id, title, author, rating] の配列
            'top_rated_books' => $reviews->where('rating', '>=', 4)->groupBy('bookUser.book_id')->map(function ($group) {
                $book = $group->first()->bookUser->book;
                return [
                    'id' => $book->id,
                    'title' => $book->title,
                    'author' => $book->author,
                    'rating' => $group->max('rating'),
                ];
            })->sortByDesc('rating')->take(5)->values(),
            // TODO: ジャンル未設定は除外 → 平均評価の高い順 → 上位5件。[id, name, count, average_rating] の配列
            'genre_ratings' => $reviews->flatMap(function ($review) {
                return $review->bookUser->book->genres->map(
                    function ($genre) use ($review) {
                        return [
                            'id' => $genre->id,
                            'name' => $genre->name,
                            'rating' => $review->rating,
                        ];
                    }
                );
            })->groupBy('id')->map(function ($group) {
                return [
                    'id' => $group->first()['id'],
                    'name' => $group->first()['name'],
                    'count' => $group->count(),
                    'average_rating' => $group->avg('rating'),
                ];
            })->sortByDesc('average_rating')->take(5)->values(),
        ];

        return view('reports.index', compact('stats'));
    }
}
