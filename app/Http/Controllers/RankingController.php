<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Book;
use App\Models\Review;
class RankingController extends Controller
{
    public function index()
    {
        $rankedBooks = Book::withAvg('reviews', 'rating')->orderByDesc('reviews_avg_rating')->take(10)->get();
        return view('ranking.index', compact('rankedBooks'));
    }
    //
}
