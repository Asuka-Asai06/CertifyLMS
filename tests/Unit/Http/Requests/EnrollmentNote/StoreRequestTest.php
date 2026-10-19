<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\EnrollmentNote;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 受講生メモ作成 StoreRequest のバリデーション検証。
 *
 * - body は必須、文字列、最大2000文字
 * - 管理者と担当コーチは作成可能
 * - 担当外コーチと受講生は作成不可
 */
class StoreRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_valid_payload(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();

        $certification = Certification::factory()->published()->create();
        $this->assignCoach($coach, $certification, $admin);

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        // Act
        $response = $this->actingAs($coach)->post(
            route('enrollments.notes.store', $enrollment),
            [
                'body' => '受講生の学習状況についてのメモです。',
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $coach->id,
            'body' => '受講生の学習状況についてのメモです。',
        ]);
    }

    public function test_validation_passes_with_2000_character_body(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();

        $certification = Certification::factory()->published()->create();
        $this->assignCoach($coach, $certification, $admin);

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        $body = str_repeat('あ', 2000);

        // Act
        $response = $this->actingAs($coach)->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => $body],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $coach->id,
            'body' => $body,
        ]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_fails(
        array $overrides,
        string $expectedErrorField,
    ): void {
        // Arrange
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();

        $certification = Certification::factory()->published()->create();
        $this->assignCoach($coach, $certification, $admin);

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        $payload = array_merge([
            'body' => '受講生の学習状況についてのメモです。',
        ], $overrides);

        $noteCountBefore = EnrollmentNote::count();

        // Act
        $response = $this->actingAs($coach)->post(
            route('enrollments.notes.store', $enrollment),
            $payload,
        );

        // Assert
        $response->assertSessionHasErrors($expectedErrorField);

        $this->assertSame(
            $noteCountBefore,
            EnrollmentNote::count(),
        );
    }

    public function test_authorize_returns_false_for_unassigned_coach(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();

        $certification = Certification::factory()->published()->create();
        $this->assignCoach($coach, $certification, $admin);

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        // Act
        $response = $this->actingAs($otherCoach)->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => '受講生の学習状況についてのメモです。'],
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseMissing('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $otherCoach->id,
        ]);
    }

    public function test_authorize_returns_false_for_student(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        // Act
        $response = $this->actingAs($student)->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => '受講生の学習状況についてのメモです。'],
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseMissing('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $student->id,
        ]);
    }

    public function test_admin_can_create_note(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        // Act
        $response = $this->actingAs($admin)->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => '管理者が登録したメモです。'],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $admin->id,
            'body' => '管理者が登録したメモです。',
        ]);
    }

    /**
     * バリデーションエラーとなる入力値。
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'body 未指定' => [
                ['body' => ''],
                'body',
            ],
            'body 2001文字' => [
                ['body' => str_repeat('あ', 2001)],
                'body',
            ],
            'body が文字列ではない' => [
                ['body' => ['不正な値']],
                'body',
            ],
        ];
    }

    /**
     * 資格にコーチを割り当てる。
     */
    private function assignCoach(
        User $coach,
        Certification $certification,
        User $admin,
    ): void {
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }
}
