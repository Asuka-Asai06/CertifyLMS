<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\Policies\EnrollmentGoalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EnrollmentGoalPolicy の判定を検証する Unit テスト。
 *
 * view: admin 全可 / student 本人 / coach 担当資格のみ。
 * create: 受講中の student 本人のみ。
 * update / delete / markAchieved / unmarkAchieved: 受講中の student 本人のみ。
 */
class EnrollmentGoalPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_role_branching(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->create();

        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue($policy->view($admin, $goal));
        $this->assertTrue($policy->view($student, $goal));
        $this->assertFalse(
            $policy->view($other, $goal),
            '他人の enrollment に紐づく目標は閲覧不可',
        );
    }

    public function test_coach_can_view_goal_only_for_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();

        $assignedCert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $assignedCert->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $assignedEnrollment = Enrollment::factory()
            ->for($student)
            ->for($assignedCert)
            ->learning()
            ->create();

        $otherEnrollment = Enrollment::factory()
            ->for($student)
            ->for($otherCert)
            ->learning()
            ->create();

        $assignedGoal = EnrollmentGoal::factory()
            ->for($assignedEnrollment)
            ->create();

        $otherGoal = EnrollmentGoal::factory()
            ->for($otherEnrollment)
            ->create();

        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue($policy->view($coach, $assignedGoal));
        $this->assertFalse(
            $policy->view($coach, $otherGoal),
            '担当外資格の目標は閲覧不可',
        );
    }

    public function test_student_can_create_goal_only_for_own_enrollment(): void
    {
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();

        $ownEnrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $otherEnrollment = Enrollment::factory()
            ->for($other)
            ->learning()
            ->create();

        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue(
            $policy->create($student, $ownEnrollment),
            '本人の enrollment には作成可',
        );

        $this->assertFalse(
            $policy->create($student, $otherEnrollment),
            '他人の enrollment には作成不可',
        );
    }

    public function test_non_active_student_cannot_create_goal(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $student->forceFill([
            'status' => UserStatus::Graduated,
        ])->save();

        $policy = new EnrollmentGoalPolicy;

        $this->assertFalse(
            $policy->create($student, $enrollment),
            '受講中以外の student は作成不可',
        );
    }

    public function test_only_owner_can_update_goal(): void
    {
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->create();

        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue($policy->update($student, $goal));
        $this->assertFalse(
            $policy->update($other, $goal),
            '他人の目標は更新不可',
        );
    }

    public function test_only_owner_can_delete_goal(): void
    {
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->create();

        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue($policy->delete($student, $goal));
        $this->assertFalse(
            $policy->delete($other, $goal),
            '他人の目標は削除不可',
        );
    }

    public function test_only_owner_can_mark_goal_as_achieved(): void
    {
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->unachieved()
            ->create();

        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue($policy->markAchieved($student, $goal));
        $this->assertFalse(
            $policy->markAchieved($other, $goal),
            '他人の目標は達成済みに変更不可',
        );
    }

    public function test_only_owner_can_unmark_goal_as_achieved(): void
    {
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->achieved()
            ->create();

        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue($policy->unmarkAchieved($student, $goal));
        $this->assertFalse(
            $policy->unmarkAchieved($other, $goal),
            '他人の目標は未達成に変更不可',
        );
    }

    public function test_coach_and_admin_cannot_modify_goal(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->create();

        $policy = new EnrollmentGoalPolicy;

        foreach ([$coach, $admin] as $staff) {
            $this->assertFalse($policy->update($staff, $goal));
            $this->assertFalse($policy->delete($staff, $goal));
            $this->assertFalse($policy->markAchieved($staff, $goal));
            $this->assertFalse($policy->unmarkAchieved($staff, $goal));
        }
    }
}
