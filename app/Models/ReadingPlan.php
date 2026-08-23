<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\ReadingPlanStatus;
use App\Models\User;
use App\Models\Book;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'book_id',
        'target_date',
    ];

    protected $casts = [
        'target_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
        'status' => ReadingPlanStatus::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

}

