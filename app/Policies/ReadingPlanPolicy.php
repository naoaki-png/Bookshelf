<?php

namespace App\Policies;

use App\Enums\ReadingPlanStatus;
use App\Models\ReadingPlan;
use App\Models\User;

class ReadingPlanPolicy
{
    /**
     * 自分が作った読書計画しか編集できない。
     *
     * 読書計画が完了済みでない場合にのみ編集を許可する。
     *
     * @param  User  $user
     * @param  ReadingPlan  $readingPlan
     * @return bool
     */
    public function update(User $user, ReadingPlan $readingPlan): bool
    {
        return $user->id === $readingPlan->user_id && $readingPlan->status !== ReadingPlanStatus::Completed;
    }

    /**
     * 自分が作った読書計画しか削除できない。
     *
     * @param  User  $user
     * @param  ReadingPlan  $readingPlan
     * @return bool
     */
    public function delete(User $user, ReadingPlan $readingPlan): bool
    {
        return $user->id === $readingPlan->user_id;
    }

    /**
     * 自分が作った読書計画しか完了にできない。
     *
     * @param  User  $user
     * @param  ReadingPlan  $readingPlan
     * @return bool
     */
    public function complete(User $user, ReadingPlan $readingPlan): bool
    {
        return $user->id === $readingPlan->user_id;
    }
}
