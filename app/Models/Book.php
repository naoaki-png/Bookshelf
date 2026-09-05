<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'user_id',
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
     * @return HasMany
     */
    public function bookGenres(): HasMany
    {
        return $this->hasMany(BookGenre::class);
    }

    /**
     * この書籍に設定されたジャンル。
     *
     * @return BelongsToMany
     */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'book_genres')
            ->withTimestamps();
    }

    /**
     * この書籍とユーザーの紐付けを保持する中間テーブルの行。
     *
     * @return HasMany
     */
    public function bookUsers(): HasMany
    {
        return $this->hasMany(BookUser::class);
    }

    /**
     * この書籍にレビューを投稿したユーザー。
     *
     * @return BelongsToMany
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }

    /**
     * この書籍に投稿されたお気に入り。
     *
     * @return HasMany
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * この書籍をお気に入りに登録したユーザー。
     *
     * @return BelongsToMany
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
     * @return HasManyThrough
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

    /**
     * タイトルまたは著者にキーワードが部分一致する書籍に絞り込む。
     *
     * キーワードが未指定のときは絞り込みを行わない。
     * ORが他の条件へ波及しないよう、2つのLIKEは無名関数で囲って括弧に閉じ込めている。
     *
     * @param  Builder  $query
     * @param  string|null  $keyword
     * @return Builder
     */
    public function scopeKeyword(Builder $query, ?string $keyword): Builder
    {
        if (! $keyword) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($keyword) {
            $q->where('title', 'like', '%' . $keyword . '%')
                ->orWhere('author', 'like', '%' . $keyword . '%');
        });
    }

    /**
     * 指定したジャンルが設定された書籍に絞り込む。
     *
     * ジャンルが未指定のときは絞り込みを行わない。
     *
     * @param  Builder  $query
     * @param  int|null  $genreId
     * @return Builder
     */
    public function scopeOfGenre(Builder $query, ?int $genreId): Builder
    {
        if (! $genreId) {
            return $query;
        }

        return $query->whereHas('genres', function (Builder $q) use ($genreId) {
            $q->where('genres.id', $genreId);
        });
    }

    /**
     * 指定された並び順で書籍を並べ替える。
     *
     * 並び順が未指定または想定外の値のときは、登録日の新しい順とする。
     * created_atが同一のときの順序を確定させるため、idを第2キーに添えている。
     *
     * 評価順は withAvg('reviews', 'rating') で付与される reviews_avg_rating を
     * 参照するため、このスコープを rating で呼ぶ場合は呼び出し側で
     * withAvg を指定しておく必要がある。
     *
     * @param  Builder  $query
     * @param  string|null  $sort
     * @return Builder
     */
    public function scopeSorted(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'rating' => $query->orderByDesc('reviews_avg_rating'),
            'title' => $query->orderBy('title'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
