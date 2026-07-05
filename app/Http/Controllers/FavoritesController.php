<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FavoritesController extends Controller
{
    public function index()
    {

        $books = collect([]);

        return view('favorites.index', compact('books'));
    }
    //
}
