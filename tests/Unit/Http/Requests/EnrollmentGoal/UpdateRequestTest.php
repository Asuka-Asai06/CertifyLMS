<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 個人目標更新 UpdateRequest のバリデーション検証。
 * title は必須、target_date / description は任意とし、
 * 正常系・異常系と権限を検証する。
 */
class UpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_valid_payload(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();
        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->create([
                'title' => '変更前の目標',
            ]);

        // Act
        $response = $this->actingAs($student)->patch(
            route('enrollment-goals.update', $goal),
            [
                'title' => '過去問を30問解く',
                'target_date' => '2026-11-01',
                'description' => '毎日少しずつ取り組む。',
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '過去問を30問解く',
            'target_date' => '2026-11-01',
            'description' => '毎日少しずつ取り組む。',
        ]);
    }

    public function test_validation_passes_without_optional_fields(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $targetDate = '2026-11-01';
        $description = '変更前の説明';

        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->create([
                'target_date' => $targetDate,
                'description' => $description,
            ]);

        // Act
        $response = $this->actingAs($student)->patch(
            route('enrollment-goals.update', $goal),
            [
                'title' => '過去問を30問解く',
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '過去問を30問解く',
            'target_date' => $targetDate,
            'description' => $description,
        ]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_fails(
        array $overrides,
        string $expectedErrorField,
    ): void {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();
        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->create([
                'title' => '変更前の目標',
            ]);

        $payload = array_merge([
            'title' => '過去問を30問解く',
        ], $overrides);

        // Act
        $response = $this->actingAs($student)->patch(
            route('enrollment-goals.update', $goal),
            $payload,
        );

        // Assert
        $response->assertSessionHasErrors($expectedErrorField);

        // バリデーションエラー時に更新されていないことを確認
        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '変更前の目標',
        ]);
    }

    public function test_authorize_returns_false_for_another_student(): void
    {
        // Arrange
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $enrollment = Enrollment::factory()
            ->for($owner)
            ->learning()
            ->create();
        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->create([
                'title' => '変更前の目標',
            ]);

        // Act
        $response = $this->actingAs($otherStudent)->patch(
            route('enrollment-goals.update', $goal),
            [
                'title' => '他人が変更した目標',
            ],
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '変更前の目標',
        ]);
    }

    public function test_authorize_returns_false_for_coach(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();
        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->create([
                'title' => '変更前の目標',
            ]);

        // Act
        $response = $this->actingAs($coach)->patch(
            route('enrollment-goals.update', $goal),
            [
                'title' => 'コーチが変更した目標',
            ],
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '変更前の目標',
        ]);
    }

    public function test_authorize_returns_false_for_admin(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();
        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->create([
                'title' => '変更前の目標',
            ]);

        // Act
        $response = $this->actingAs($admin)->patch(
            route('enrollment-goals.update', $goal),
            [
                'title' => '管理者が変更した目標',
            ],
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '変更前の目標',
        ]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'title 未指定' => [
                ['title' => ''],
                'title',
            ],
            'title 101文字' => [
                ['title' => str_repeat('あ', 101)],
                'title',
            ],
            'title が文字列ではない' => [
                ['title' => ['不正な値']],
                'title',
            ],
            'target_date が不正な日付' => [
                ['target_date' => 'not-a-date'],
                'target_date',
            ],
            'description が文字列ではない' => [
                ['description' => ['不正な値']],
                'description',
            ],
            'description 1001文字' => [
                ['description' => str_repeat('あ', 1001)],
                'description',
            ],
        ];
    }
}
