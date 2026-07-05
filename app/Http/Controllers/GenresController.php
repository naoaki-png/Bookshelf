<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class GenresController extends Controller
{
    public function index()
    {

        $genres = collect([]);

        return view('genres.index', compact('genres'));
    }
    //
}
