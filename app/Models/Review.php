<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\BookUser;
use App\Models\ReviewLike;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Review extends Model
{
    use HasFactory;

    /**
     * 一括代入を許可する属性。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'book_user_id',
        'rating',
        'comment',
    ];

    /**
     * このレビューがどの book_users の行に属しているか。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function bookUser(): BelongsTo
    {
        return $this->belongsTo(BookUser::class);
    }

    /**
     * このレビューを投稿したユーザー。
     *
     * BookUserを経由してusersテーブルにアクセスする。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOneThrough
     */
    public function user(): HasOneThrough
    {
        return $this->hasOneThrough(
            User::class,
            BookUser::class,
            'id',
            'id',
            'book_user_id',
            'user_id'
        );
    }

    /**
     * このレビューに付いたいいねの行の一覧。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reviewLikes(): HasMany
    {
        return $this->hasMany(ReviewLike::class);
    }

    /**
     * このレビューをいいねしたユーザー。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function likedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'review_likes')
            ->withTimestamps();

    }
}
