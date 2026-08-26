<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ReadingPlan;

class ReadingPlanPolicy
{
    /**
     * Create a new policy instance.
     */
    public function update(User $user, ReadingPlan $readingPlan): bool
    {
        return $user->id === $readingPlan->user_id;
    }
    public function delete(User $user, ReadingPlan $readingPlan): bool
    {
        return $user->id === $readingPlan->user_id;
    }
    public function complete(User $user, ReadingPlan $readingPlan): bool
    {
        return $user->id === $readingPlan->user_id;
    }
}
