<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Genre;

class GenresController extends Controller
{
    public function index()
    {

        $genres = Genre::withCount('books')->get();

        return view('genres.index', compact('genres'));
    }

    public function destroy(Genre $genre)
    {
        if ($genre->books()->doesntExist()) {
            $genre->delete();
            return redirect()->route('genres.index')->with('success', 'ジャンルを削除しました。');
        } else {
            return back()->with('error', '書籍数が０冊でないと削除できません。');
        }

    }
    //
}
