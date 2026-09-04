<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\View\View;

class RankingController extends Controller
{
    /**
     * 平均評価の高い書籍を上位10件まで表示する。
     *
     * @return View
     */
    public function index(): View
    {
        $rankedBooks = Book::withAvg('reviews', 'rating')->orderByDesc('reviews_avg_rating')->take(10)->get();

        return view('ranking.index', compact('rankedBooks'));
    }
}
