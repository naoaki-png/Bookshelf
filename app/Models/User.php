<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\ReviewLike;
use App\Models\Book;
use App\Models\Review;
use App\Models\BookUser;
use App\Models\Favorite;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function bookUsers()
    {
        return $this->hasMany(BookUser::class);
    }

    public function books()
    {
        return $this->belongsToMany(Book::class)
            ->withTimestamps();
    }
    public function reviewLikes()
    {
        return $this->hasMany(ReviewLike::class);
    }

    public function reviews()
    {
        return $this->belongsToMany(Review::class)
            ->withTimestamps();
    }
    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function favoriteBooks()
    {
        return $this->belongsToMany(Book::class, 'favorites')
            ->withTimestamps();
    }
    public function likedReviews()
    {
        return $this->belongsToMany(Review::class, 'review_likes')
            ->withTimestamps();
    }
}
