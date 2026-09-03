<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Book;
use App\Models\Review;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookUser extends Model
{
    use HasFactory;

    /**
     * このモデルが使うテーブル名。
     *
     * クラス名から推測すると book_users ではなく book_user になるため、
     * 明示している。
     *
     * @var string
     */
    protected $table = 'book_users';

    /**
     * 一括代入を許可する属性。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'book_id',
    ];

    /**
     * この行が指すユーザー。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * この行が指す書籍。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * この行に紐づくレビュー。
     *
     * reviews.book_user_id がこの行を指しているため、1行につき複数のレビューを持つ。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function review(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}

