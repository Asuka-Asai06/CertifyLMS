<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrollmentGoal モデルのリレーションを検証する Unit テスト。
 * enrollment / goals の 2 リレーションを網羅する。
 */
class EnrollmentGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_relation_returns_parent_enrollment(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->create();

        $goal = EnrollmentGoal::factory()->create([
            'enrollment_id' => $enrollment->id,
        ]);

        // Act
        $related = $goal->enrollment;

        // Assert
        $this->assertNotNull($related);
        $this->assertTrue($related->is($enrollment));
    }

    public function test_goals_relation_returns_enrollment_goals(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->create();

        $goal = EnrollmentGoal::factory()->create([
            'enrollment_id' => $enrollment->id,
        ]);

        // Act
        $related = $enrollment->goals;

        // Assert
        $this->assertCount(1, $related);
        $this->assertTrue($related->first()->is($goal));
    }
}
