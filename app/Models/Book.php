<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\BookUser;
use App\Models\User;
use App\Models\BookGenre;
use App\Models\Genre;
use App\Models\Favorite;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Book extends Model
{
    use HasFactory;
    /**
     * 一括代入を許可する属性。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'author',
        'isbn',
        'description',
        'published_date',
        'image_url',
        'user_id'
    ];

    /**
     * ネイティブな型へキャストする属性。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'published_date' => 'date:Y-m-d',
    ];

    /**
     * この書籍とジャンルの紐付けを保持する中間テーブルの行。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookGenres(): HasMany
    {
        return $this->hasMany(BookGenre::class);
    }

    /**
     * この書籍に設定されたジャンル。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'book_genres')
            ->withTimestamps();
    }

    /**
     * この書籍とユーザーの紐付けを保持する中間テーブルの行。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookUsers(): HasMany
    {
        return $this->hasMany(BookUser::class);
    }
    /**
     * この書籍にレビューを投稿したユーザー。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }

    /**
     * この書籍に投稿されたお気に入り。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * この書籍をお気に入りに登録したユーザー。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')
            ->withTimestamps();
    }

    /**
     * この書籍のレビュー。
     *
     * book_usersを経由してreviewsテーブルにアクセスする。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function reviews(): HasManyThrough
    {
        return $this->hasManyThrough(
            Review::class,
            BookUser::class,
            'book_id',
            'book_user_id',
            'id',
            'id'
        );
    }

}
