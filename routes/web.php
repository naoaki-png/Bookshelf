<?php
use App\Http\Controllers\FavoritesController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\BooksController;
use App\Http\Controllers\GenresController;
use App\Http\Controllers\ReviewsController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ReadingPlansController;
use App\Http\Controllers\NotificationsController;
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
    Route::get('/books/isbn/{isbn}', [BooksController::class, 'searchByIsbn']);
    Route::get('/genres/create', [GenresController::class, 'create'])->name('genres.create');
    Route::post('/genres', [GenresController::class, 'store'])->name('genres.store');
    Route::get('/genres/{genre}', [GenresController::class, 'show'])->name('genres.show');
    Route::post('/books/{book}/reviews', [ReviewsController::class, 'store'])->name('reviews.store');
    Route::get('/genres/{genre}/edit', [GenresController::class, 'edit'])->name('genres.edit');
    Route::put('/genres/{genre}/edit', [GenresController::class, 'update'])->name('genres.update');
    Route::delete('/genres/{genre}', [GenresController::class, 'destroy'])->name('genres.destroy');
    Route::get('/reviews/{review}/edit', [ReviewsController::class, 'edit'])->name('reviews.edit');

    Route::post('/reviews/{review}/like', [ReviewsController::class, 'like'])->name('reviews.like');
    Route::put('/reviews/{review}', [ReviewsController::class, 'update'])->name('reviews.update');
    Route::delete('/reviews/{review}', [ReviewsController::class, 'destroy'])->name('reviews.destroy');
    Route::get('/notifications', [NotificationsController::class, 'index'])->name('notifications.index');
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reading-plans', [ReadingPlansController::class, 'index'])->name('reading-plans.index');
    Route::post('/reading-plans', [ReadingPlansController::class, 'store'])->name('reading-plans.store');
    Route::get('/reading-plans/create', [ReadingPlansController::class, 'create'])->name('reading-plans.create');
    Route::get('/reading-plans/{plan}/edit', [ReadingPlansController::class, 'edit'])->name('reading-plans.edit');
    Route::put('/reading-plans/{plan}', [ReadingPlansController::class, 'update'])->name('reading-plans.update');
    Route::delete('/reading-plans/{plan}', [ReadingPlansController::class, 'destroy'])->name('reading-plans.destroy');
    Route::post('/reading-plans/{plan}/complete', [ReadingPlansController::class, 'complete'])->name('reading-plans.complete');

});
