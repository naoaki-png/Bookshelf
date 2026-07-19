<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Book;
use App\Models\Review;

class BookUser extends Model
{
    use HasFactory;
    protected $table = 'book_users';

    protected $fillable = [
        'user_id',
        'book_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    public function review()
    {
        return $this->hasMany(Review::class);
    }
}

