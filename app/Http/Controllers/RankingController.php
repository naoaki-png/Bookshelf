<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RankingController extends Controller
{
    public function index()
    {

        $rankedBooks = collect([]);

        return view('ranking.index', compact('rankedBooks'));
    }
    //
}
