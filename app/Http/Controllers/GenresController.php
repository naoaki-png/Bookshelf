<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenreRequest;
use App\Models\Genre;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GenresController extends Controller
{
    /**
     * ジャンルの一覧を表示する。
     *
     * 各ジャンルに属する書籍数を添えて返す。
     *
     * @return View
     */
    public function index(): View
    {
        $genres = Genre::withCount('books')->get();

        return view('genres.index', compact('genres'));
    }

    /**
     * ジャンルの新規登録画面を表示する。
     *
     * @return View
     */
    public function create(): View
    {
        return view('genres.create');
    }

    /**
     * ジャンルに属する書籍の一覧を表示する。
     *
     * @param  Genre  $genre
     * @return View
     */
    public function show(Genre $genre): View
    {
        $books = $genre->books()->paginate(10);

        return view('genres.show', compact('books', 'genre'));
    }

    /**
     * ジャンルを新規登録する。
     *
     * @param  GenreRequest  $request
     * @return RedirectResponse
     */
    public function store(GenreRequest $request): RedirectResponse
    {
        $data = $request->only('name');
        Genre::create($data);

        return redirect(route('genres.index'))->with('success', 'ジャンルを登録しました。');
    }

    /**
     * ジャンルの編集画面を表示する。
     *
     * @param  Genre  $genre
     * @return View
     */
    public function edit(Genre $genre): View
    {
        return view('genres.edit', compact('genre'));
    }

    /**
     * ジャンルを更新する。
     *
     * @param  GenreRequest  $request
     * @param  Genre  $genre
     * @return RedirectResponse
     */
    public function update(GenreRequest $request, Genre $genre): RedirectResponse
    {
        $data = $request->only('name');
        $genre->update($data);

        return redirect(route('genres.index'))->with('success', 'ジャンルを変更しました。');
    }

    /**
     * ジャンルを削除する。
     *
     * 書籍が1冊でも属している場合は削除せず、エラーを添えて元の画面へ戻す。
     *
     * @param  Genre  $genre
     * @return RedirectResponse
     */
    public function destroy(Genre $genre): RedirectResponse
    {
        if ($genre->books()->doesntExist()) {
            $genre->delete();

            return redirect(route('genres.index'))->with('success', 'ジャンルを削除しました。');
        } else {
            return back()->with('error', '書籍数が０冊でないと削除できません。');
        }
    }
}
