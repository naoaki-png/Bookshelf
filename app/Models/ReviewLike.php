<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewLike extends Model
{
    use HasFactory;

    /**
     * このモデルが使うテーブル名。
     *
     * クラス名から推測すると review_likes ではなく review_like になるため、
     * 明示している。
     *
     * @var string
     */
    protected $table = 'review_likes';

    /**
     * 一括代入を許可する属性。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'review_id',
    ];

    /**
     * このレビューいいねが指すユーザー。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * このレビューいいねが指すレビュー。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
