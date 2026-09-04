<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * 一括代入を許可する属性。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * 隠す属性。
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * ネイティブな型へキャストする属性。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * このユーザーと書籍の紐付けを保持する中間テーブルの行。
     *
     * @return HasMany
     */
    public function bookUsers(): HasMany
    {
        return $this->hasMany(BookUser::class);
    }

    /**
     * このユーザーがいいねしたレビューの紐付けを保持する中間テーブルの行。
     *
     * @return HasMany
     */
    public function reviewLikes(): HasMany
    {
        return $this->hasMany(ReviewLike::class);
    }

    /**
     * このユーザーのレビュー。
     *
     * BookUserを経由してreviewsテーブルにアクセスする。
     *
     * @return HasManyThrough
     */
    public function reviews(): HasManyThrough
    {
        return $this->hasManyThrough(
            Review::class,
            BookUser::class,
            'user_id',
            'book_user_id',
            'id',
            'id'
        );
    }

    /**
     * このユーザーがお気に入りに登録した書籍の紐付けを保持する中間テーブルの行。
     *
     * @return HasMany
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * このユーザーがお気に入りに登録した書籍。
     *
     * @return BelongsToMany
     */
    public function favoriteBooks(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'favorites')
            ->withTimestamps();
    }

    /**
     * このユーザーがいいねしたレビュー。
     *
     * @return BelongsToMany
     */
    public function likedReviews(): BelongsToMany
    {
        return $this->belongsToMany(Review::class, 'review_likes')
            ->withTimestamps();
    }

    /**
     * このユーザーが作成した読書計画。
     *
     * @return HasMany
     */
    public function readingPlans(): HasMany
    {
        return $this->hasMany(ReadingPlan::class);
    }
}
