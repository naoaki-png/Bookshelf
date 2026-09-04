<?php

namespace Database\Factories;

use App\Enums\ReadingPlanStatus;
use App\Models\Book;
use App\Models\ReadingPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadingPlan>
 */
class ReadingPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'user_id' => User::factory(),
            'target_date' => fake()->dateTimeBetween('+1 day', '+1 month'),
            'completed_at' => null,
            'status' => ReadingPlanStatus::InProgress,
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'target_date' => fake()->dateTimeBetween('-3 day', '-1days'),
            'status' => ReadingPlanStatus::Overdue,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
            'status' => ReadingPlanStatus::Completed,
        ]);
    }

    public function dueInDays(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'target_date' => now()->addDays($days)->startOfDay(),
        ]);
    }
}
