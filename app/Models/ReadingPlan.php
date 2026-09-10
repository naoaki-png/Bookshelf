<?php

namespace App\Models;

use App\Enums\ReadingPlanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingPlan extends Model
{
    use HasFactory;

    /**
     * 一括代入を許可する属性。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'book_id',
        'target_date',
    ];

    /**
     * ネイティブな型へキャストする属性。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'target_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
        'status' => ReadingPlanStatus::class,
    ];

    /**
     * この読書計画を作成したユーザー。
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * この読書計画が指す書籍。
     *
     * @return BelongsTo
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * 期日を変更する。期限切れだった計画は進行中に戻す。
     *
     * @param  string  $targetDate
     * @return void
     */
    public function reschedule(string $targetDate): void
    {
        $this->target_date = $targetDate;

        if ($this->status === ReadingPlanStatus::Expired) {
            $this->status = ReadingPlanStatus::InProgress;
        }

        $this->save();
    }
}
