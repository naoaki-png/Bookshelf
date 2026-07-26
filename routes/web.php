<?php
use App\Http\Controllers\FavoritesController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\BooksController;
use App\Http\Controllers\GenresController;
use App\Http\Controllers\ReviewsController;
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
Route::get('/books/create', [BooksController::class, 'create'])->name('books.create')->middleware('auth');
Route::get('/ranking', [RankingController::class, 'index'])->name('ranking.index');
Route::get('/books/{book}', [BooksController::class, 'show'])->name('books.show');


Route::middleware(['auth'])->group(function () {
    Route::post('/books', [BooksController::class, 'store'])->name('books.store');
    Route::delete('/books/{book}', [BooksController::class, 'destroy'])->name('books.destroy');
    Route::get('/favorites', [FavoritesController::class, 'index'])->name('favorites.index');
    Route::post('/books/{book}/favorites', [FavoritesController::class, 'toggle'])->name('favorites.toggle');
    Route::get('/genres', [GenresController::class, 'index'])->name('genres.index');
    Route::get('/books/{book}/edit', [BooksController::class, 'edit'])->name('books.edit');
    Route::put('/books/{book}', [BooksController::class, 'update'])->name('books.update');
    Route::get('/genres/create', [GenresController::class, 'create'])->name('genres.create');
    Route::get('/genres/{genre}', [GenresController::class, 'show'])->name('genres.show');
    Route::post('/books/{book}/reviews', [ReviewsController::class, 'store'])->name('reviews.store');
    Route::get('/genres/{genre}/edit', [GenresController::class, 'edit'])->name('genres.edit');
    Route::delete('/genres/{genre}', [GenresController::class, 'destroy'])->name('genres.destroy');
    Route::get('/reviews/{review}/edit', [ReviewsController::class, 'edit'])->name('reviews.edit');

    Route::post('/reviews/{review}/like', [ReviewsController::class, 'like'])->name('reviews.like');
    Route::put('/reviews/{review}', [ReviewsController::class, 'update'])->name('reviews.update');
    Route::delete('/reviews/{review}', [ReviewsController::class, 'destroy'])->name('reviews.destroy');

});
