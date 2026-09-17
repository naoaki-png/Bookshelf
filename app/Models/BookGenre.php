<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 書籍とジャンルを結ぶ中間テーブル(book_genre)のモデル。
 *
 * 書籍は複数のジャンルを持ち、ジャンルは複数の書籍を持つ。
 * その組み合わせを1行ずつ保持する。
 */
class BookGenre extends Model
{
    use HasFactory;

    /**
     * このモデルが使うテーブル名。
     *
     * クラス名 BookGenre から推測されるのは book_genres(複数形)だが、
     * 実体は中間テーブルの規約に従った book_genre(単数形)なので明示している。
     *
     * @var string
     */
    protected $table = 'book_genre';

    /**
     * 一括代入を許可する属性。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'book_id',
        'genre_id',
    ];

    /**
     * この行が指す書籍。
     *
     * @return BelongsTo
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * この行が指すジャンル。
     *
     * @return BelongsTo
     */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }
}
