<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\BookUser;
use App\Models\ReviewLike;
use App\Models\User;
class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'book_user_id',
        'rating',
        'comment',
    ];

    public function bookUser()
    {
        return $this->belongsTo(BookUser::class);
    }

    public function reviewLikes()
    {
        return $this->hasMany(ReviewLike::class);
    }

    public function likedByUsers()
    {
        return $this->belongsToMany(User::class, 'review_likes')
            ->withTimestamps();

    }
}
