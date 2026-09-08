<?php

namespace App\Enums;

enum ReadingPlanStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => '進行中',
            self::Completed => '完了',
            self::Expired => '期限切れ',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::InProgress => 'bg-blue-100 text-blue-700',
            self::Completed => 'bg-green-100 text-green-700',
            self::Expired => 'bg-red-100 text-red-700',
        };
    }
}
