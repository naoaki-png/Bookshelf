<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\BookUser;
use App\Models\User;
use App\Models\BookGenre;
use App\Models\Genre;
use App\Models\Favorite;

class Book extends Model
{
    use HasFactory;
    protected $fillable = [
        'title',
        'author',
        'isbn',
        'description',
        'published_date',
        'image_url',
    ];

    protected $casts = [
        'published_date' => 'date',
    ];

    public function bookGenres()
    {
        return $this->hasMany(BookGenre::class);
    }

    public function genres()
    {
        return $this->belongsToMany(Genre::class, 'book_genres')
            ->withTimestamps();
    }

    public function bookUsers()
    {
        return $this->hasMany(BookUser::class);
    }
    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function favoritedByUsers()
    {
        return $this->belongsToMany(User::class, 'favorites')
            ->withTimestamps();
    }

}
