<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use App\Policies\EnrollmentNotePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EnrollmentNotePolicy の判定を検証する Unit テスト。
 *
 * - viewAny / view: admin / 担当コーチのみ。他コーチのメモも閲覧可。
 * - create: admin / 担当コーチのみ。
 * - update / delete: admin は全メモ、coach は自分のメモのみ。
 * - student は閲覧を含むすべての操作が不可。
 * - 担当外資格および論理削除済み Enrollment の操作は不可。
 */
class EnrollmentNotePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_any_is_allowed_for_admin_and_assigned_coach_only(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $certification = Certification::factory()->published()->create();
        $this->assignCoach($coach, $certification, $admin);

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->viewAny($admin, $enrollment));
        $this->assertTrue($policy->viewAny($coach, $enrollment));
        $this->assertFalse($policy->viewAny($otherCoach, $enrollment));
        $this->assertFalse($policy->viewAny($student, $enrollment));
    }

    public function test_coach_can_view_other_coach_note_on_assigned_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $author = User::factory()->coach()->create();

        $certification = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certification, $admin);
        $this->assignCoach($author, $certification, $admin);

        $enrollment = Enrollment::factory()
            ->for($certification)
            ->learning()
            ->create();

        $note = $this->createNote($enrollment, $author);

        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->view($coach, $note));
        $this->assertTrue($policy->view($author, $note));
        $this->assertTrue($policy->view($admin, $note));
    }

    public function test_view_is_denied_for_unassigned_coach_and_student(): void
    {
        $admin = User::factory()->admin()->create();
        $author = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $certification = Certification::factory()->published()->create();
        $this->assignCoach($author, $certification, $admin);

        $enrollment = Enrollment::factory()
            ->for($certification)
            ->learning()
            ->create();

        $note = $this->createNote($enrollment, $author);

        $policy = new EnrollmentNotePolicy;

        $this->assertFalse($policy->view($otherCoach, $note));
        $this->assertFalse($policy->view($student, $note));
    }

    public function test_create_is_allowed_for_admin_and_assigned_coach_only(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $certification = Certification::factory()->published()->create();
        $this->assignCoach($coach, $certification, $admin);

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->create($admin, $enrollment));
        $this->assertTrue($policy->create($coach, $enrollment));
        $this->assertFalse($policy->create($otherCoach, $enrollment));
        $this->assertFalse($policy->create($student, $enrollment));
    }

    public function test_coach_can_update_and_delete_own_note_only(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();

        $certification = Certification::factory()->published()->create();

        $this->assignCoach($coach, $certification, $admin);
        $this->assignCoach($otherCoach, $certification, $admin);

        $enrollment = Enrollment::factory()
            ->for($certification)
            ->learning()
            ->create();

        $ownNote = $this->createNote($enrollment, $coach);
        $otherNote = $this->createNote($enrollment, $otherCoach);

        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->update($coach, $ownNote));
        $this->assertTrue($policy->delete($coach, $ownNote));

        $this->assertFalse($policy->update($coach, $otherNote));
        $this->assertFalse($policy->delete($coach, $otherNote));
    }

    public function test_admin_can_update_and_delete_any_coach_note(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();

        $certification = Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()
            ->for($certification)
            ->learning()
            ->create();

        $note = $this->createNote($enrollment, $coach);

        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->update($admin, $note));
        $this->assertTrue($policy->delete($admin, $note));
    }

    public function test_all_operations_are_denied_for_soft_deleted_enrollment(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();

        $certification = Certification::factory()->published()->create();
        $this->assignCoach($coach, $certification, $admin);

        $enrollment = Enrollment::factory()
            ->for($certification)
            ->learning()
            ->create();

        $note = $this->createNote($enrollment, $coach);

        $enrollment->delete();
        $note->unsetRelation('enrollment');

        $policy = new EnrollmentNotePolicy;

        $this->assertFalse($policy->viewAny($admin, $enrollment));
        $this->assertFalse($policy->create($admin, $enrollment));

        $this->assertFalse($policy->view($admin, $note));
        $this->assertFalse($policy->update($admin, $note));
        $this->assertFalse($policy->delete($admin, $note));

        $this->assertFalse($policy->view($coach, $note));
        $this->assertFalse($policy->update($coach, $note));
        $this->assertFalse($policy->delete($coach, $note));
    }

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

    private function createNote(Enrollment $enrollment, User $author): EnrollmentNote
    {
        return EnrollmentNote::factory()
            ->for($enrollment, 'enrollment')
            ->for($author, 'author')
            ->create([
                'body' => 'テスト用の受講生メモです。',
            ]);
    }
}
