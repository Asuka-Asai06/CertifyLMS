<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 受講生メモ EnrollmentNoteController の HTTP 統合テスト。
 *
 * - 管理者と担当コーチのメモ作成
 * - メモ編集画面の認可
 * - 管理者と作成者本人の担当コーチによる更新・削除
 * - 担当外コーチ、受講生、他人のメモへの不正アクセス拒否
 * - 削除済み受講登録に紐づくメモへのアクセス拒否
 * - メモの物理削除
 */
class EnrollmentNoteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_note_for_assigned_coach(): void
    {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();

        // Act
        $response = $this->actingAs($coach)->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => '受講生の学習状況についてのメモです。'],
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $coach->id,
            'body' => '受講生の学習状況についてのメモです。',
        ]);
    }

    public function test_store_allows_admin(): void
    {
        // Arrange
        [$admin, , $enrollment] = $this->createAssignedEnrollment();

        // Act
        $response = $this->actingAs($admin)->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => '管理者が登録したメモです。'],
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $admin->id,
            'body' => '管理者が登録したメモです。',
        ]);
    }

    public function test_store_forbids_unassigned_coach(): void
    {
        // Arrange
        [, , $enrollment] = $this->createAssignedEnrollment();
        $otherCoach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($otherCoach)->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => '担当外コーチによるメモです。'],
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseMissing('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $otherCoach->id,
        ]);
    }

    public function test_store_forbids_student(): void
    {
        // Arrange
        [, , $enrollment] = $this->createAssignedEnrollment();
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($student)->post(
            route('enrollments.notes.store', $enrollment),
            ['body' => '受講生によるメモです。'],
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseMissing('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_id' => $student->id,
        ]);
    }

    public function test_edit_allows_note_author(): void
    {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();
        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($coach)->get(
            route('enrollment-notes.edit', $note),
        );

        // Assert
        $response->assertOk();
        $response->assertViewIs('enrollment-note.edit');
    }

    public function test_edit_allows_admin_to_edit_other_coach_note(): void
    {
        // Arrange
        [$admin, $coach] = $this->createAssignedEnrollment();
        $enrollment = Enrollment::factory()
            ->for(User::factory()->student()->inProgress())
            ->for(Certification::factory()->published())
            ->learning()
            ->create();

        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($admin)->get(
            route('enrollment-notes.edit', $note),
        );

        // Assert
        $response->assertOk();
        $response->assertViewIs('enrollment-note.edit');
    }

    public function test_edit_forbids_unassigned_coach(): void
    {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();
        $otherCoach = User::factory()->coach()->create();
        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($otherCoach)->get(
            route('enrollment-notes.edit', $note),
        );

        // Assert
        $response->assertForbidden();
    }

    public function test_edit_forbids_student(): void
    {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();
        $student = User::factory()->student()->inProgress()->create();
        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($student)->get(
            route('enrollment-notes.edit', $note),
        );

        // Assert
        $response->assertForbidden();
    }

    public function test_update_changes_note_body_for_author(): void
    {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();
        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($coach)->patch(
            route('enrollment-notes.update', $note),
            ['body' => '更新後のメモです。'],
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'enrollment_id' => $enrollment->id,
            'author_id' => $coach->id,
            'body' => '更新後のメモです。',
        ]);
    }

    public function test_update_forbids_coach_from_updating_other_coach_note(): void
    {
        // Arrange
        [$admin, $coach, $enrollment] = $this->createAssignedEnrollment();
        $otherCoach = User::factory()->coach()->create();
        $this->assignCoach($otherCoach, $enrollment->certification, $admin);

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

    public function test_update_allows_admin_to_update_other_coach_note(): void
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
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'author_id' => $coach->id,
            'body' => '管理者が更新したメモです。',
        ]);
    }

    public function test_destroy_physically_deletes_note_for_author(): void
    {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();
        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($coach)->delete(
            route('enrollment-notes.destroy', $note),
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseMissing('enrollment_notes', [
            'id' => $note->id,
        ]);
    }

    public function test_destroy_forbids_coach_from_deleting_other_coach_note(): void
    {
        // Arrange
        [$admin, $coach, $enrollment] = $this->createAssignedEnrollment();
        $otherCoach = User::factory()->coach()->create();
        $this->assignCoach($otherCoach, $enrollment->certification, $admin);

        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($otherCoach)->delete(
            route('enrollment-notes.destroy', $note),
        );

        // Assert
        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
        ]);
    }

    public function test_destroy_allows_admin_to_delete_other_coach_note(): void
    {
        // Arrange
        [$admin, $coach, $enrollment] = $this->createAssignedEnrollment();
        $note = $this->createNote($enrollment, $coach);

        // Act
        $response = $this->actingAs($admin)->delete(
            route('enrollment-notes.destroy', $note),
        );

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseMissing('enrollment_notes', [
            'id' => $note->id,
        ]);
    }

    public function test_deleted_enrollment_note_cannot_be_edited_or_deleted(): void
    {
        // Arrange
        [, $coach, $enrollment] = $this->createAssignedEnrollment();
        $note = $this->createNote($enrollment, $coach);

        $enrollment->delete();

        // Act
        $editResponse = $this->actingAs($coach)->get(
            route('enrollment-notes.edit', $note),
        );

        $updateResponse = $this->actingAs($coach)->patch(
            route('enrollment-notes.update', $note),
            ['body' => '更新しようとしたメモです。'],
        );

        $deleteResponse = $this->actingAs($coach)->delete(
            route('enrollment-notes.destroy', $note),
        );

        // Assert
        $editResponse->assertForbidden();
        $updateResponse->assertForbidden();
        $deleteResponse->assertForbidden();

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => $note->body,
        ]);
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

        $certification = Certification::factory()
            ->published()
            ->create();

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
