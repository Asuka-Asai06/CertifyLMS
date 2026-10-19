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
 * 受講生メモ更新 UpdateRequest のバリデーション検証。
 *
 * - body は必須、文字列、最大2000文字
 * - 管理者と作成者本人の担当コーチは更新可能
 * - 担当外コーチ、他人のメモを更新するコーチ、受講生は更新不可
 */
class UpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_valid_payload(): void
    {
        // Arrange
        [$admin, $coach, $enrollment] = $this->createAssignedEnrollment();
        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($coach)->patch(
            route('enrollment-notes.update', $note),
            ['body' => '更新後の学習状況についてのメモです。'],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'enrollment_id' => $enrollment->id,
            'author_id' => $coach->id,
            'body' => '更新後の学習状況についてのメモです。',
        ]);
    }

    public function test_validation_passes_with_2000_character_body(): void
    {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();
        $note = $this->createNote($enrollment, $coach);
        $body = str_repeat('あ', 2000);

        // Act
        $response = $this->actingAs($coach)->patch(
            route('enrollment-notes.update', $note),
            ['body' => $body],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => $body,
        ]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_fails(
        array $overrides,
        string $expectedErrorField,
    ): void {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();
        $note = $this->createNote($enrollment, $coach);

        $originalBody = $note->body;

        $payload = array_merge([
            'body' => '更新後の学習状況についてのメモです。',
        ], $overrides);

        // Act
        $response = $this->actingAs($coach)->patch(
            route('enrollment-notes.update', $note),
            $payload,
        );

        // Assert
        $response->assertSessionHasErrors($expectedErrorField);

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => $originalBody,
        ]);
    }

    public function test_authorize_returns_false_for_unassigned_coach(): void
    {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();
        $otherCoach = User::factory()->coach()->create();
        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($otherCoach)->patch(
            route('enrollment-notes.update', $note),
            ['body' => '不正な更新です。'],
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => $note->body,
        ]);
    }

    public function test_authorize_returns_false_for_other_coach_note(): void
    {
        // Arrange
        [$admin, $coach, $enrollment] = $this->createAssignedEnrollment();
        $otherCoach = User::factory()->coach()->create();

        // 同じ資格の担当コーチとして割り当てる
        $this->assignCoach($otherCoach, $enrollment->certification, $admin);

        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($otherCoach)->patch(
            route('enrollment-notes.update', $note),
            ['body' => '他人のメモを更新しようとしています。'],
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => $note->body,
        ]);
    }

    public function test_authorize_returns_false_for_student(): void
    {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();
        $student = User::factory()->student()->inProgress()->create();
        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($student)->patch(
            route('enrollment-notes.update', $note),
            ['body' => '受講生による不正な更新です。'],
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => $note->body,
        ]);
    }

    public function test_admin_can_update_other_coach_note(): void
    {
        // Arrange
        [$admin, $coach, $enrollment] = $this->createAssignedEnrollment();
        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($admin)->patch(
            route('enrollment-notes.update', $note),
            ['body' => '管理者が更新したメモです。'],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'author_id' => $coach->id,
            'body' => '管理者が更新したメモです。',
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
     * 担当コーチと受講登録を作成する。
     *
     * @return array{0: User, 1: User, 2: Enrollment}
     */
    private function createAssignedEnrollment(): array
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();

        $certification = Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        $this->assignCoach($coach, $certification, $admin);

        return [$admin, $coach, $enrollment];
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

    /**
     * 受講生メモを作成する。
     */
    private function createNote(
        Enrollment $enrollment,
        User $author,
    ): EnrollmentNote {
        return EnrollmentNote::factory()
            ->for($enrollment, 'enrollment')
            ->for($author, 'author')
            ->create([
                'body' => '更新前の学習状況についてのメモです。',
            ]);
    }
}
