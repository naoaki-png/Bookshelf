<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Auth;
use Illuminate\View\View;

class ReportsController extends Controller
{
    /**
     * ログイン中のユーザーの読書レポートを表示する。
     *
     * 自分が投稿したレビューを1回だけ取得し、
     * サマリー・評価分布・高評価の書籍・ジャンル別評価の4つに集計する。
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
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
            // 仕様: 4星以上を対象に、評価の高い順で上位5件。[id, title, author, rating] の配列で返す。
            'top_rated_books' => $reviews->where('rating', '>=', 4)->groupBy('bookUser.book_id')->map(function ($group) {
                $book = $group->first()->bookUser->book;
                return [
                    'id' => $book->id,
                    'title' => $book->title,
                    'author' => $book->author,
                    'rating' => $group->max('rating'),
                ];
            })->sortByDesc('rating')->take(5)->values(),
            // 仕様: ジャンル未設定の書籍は除外し、平均評価の高い順で上位5件。[id, name, count, average_rating] の配列で返す。
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
