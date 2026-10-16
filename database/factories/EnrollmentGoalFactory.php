<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentGoal>
 */
class EnrollmentGoalFactory extends Factory
{
    protected $model = EnrollmentGoal::class;

    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'title' => fake()->sentence(4),
            'target_date' => fake()->optional()->dateTimeBetween(
                'tomorrow',
                '+3 months',
            )?->format('Y-m-d'),
            'description' => fake()->optional()->paragraph(),
            'achieved_at' => null,
        ];
    }

    public function achieved(): static
    {
        return $this->state(fn () => [
            'achieved_at' => now(),
        ]);
    }

    public function unachieved(): static
    {
        return $this->state(fn () => [
            'achieved_at' => null,
        ]);
    }

    public function withoutTargetDate(): static
    {
        return $this->state(fn () => [
            'target_date' => null,
        ]);
    }
}
