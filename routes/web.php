<?php
use App\Http\Controllers\FavoritesController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\BooksController;
use App\Http\Controllers\GenresController;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/books', [BooksController::class, 'index'])->name('books.index');
Route::get('/ranking', [RankingController::class, 'index'])->name('ranking.index');



Route::middleware(['auth'])->group(function () {
    Route::get('/books/create', [BooksController::class, 'create'])->name('books.create');
    Route::get('/favorites', [FavoritesController::class, 'index'])->name('favorites.index');
    Route::get('/genres', [GenresController::class, 'index'])->name('genres.index');
});
