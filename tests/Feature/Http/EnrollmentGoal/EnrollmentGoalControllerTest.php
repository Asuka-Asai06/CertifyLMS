<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 個人目標 Controller の HTTP 統合テスト。
 * 目標の CRUD・達成状態の変更・認可を検証する。
 */
class EnrollmentGoalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_goal_for_owner_student(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($student)->post(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => '過去問を20問解く',
                'target_date' => now()->addDays(7)->toDateString(),
                'description' => '今週中に取り組む。',
            ],
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success', '目標を追加しました。');

        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => '過去問を20問解く',
            'target_date' => now()->addDays(7)->toDateString(),
            'description' => '今週中に取り組む。',
            'achieved_at' => null,
        ]);
    }

    public function test_edit_returns_goal_edit_view_for_owner_student(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();

        $response = $this->actingAs($student)->get(
            route('enrollment-goals.edit', $goal),
        );

        $response->assertOk();
        $response->assertViewIs('enrollment-goal.edit');
        $response->assertViewHas('goal', $goal);
    }

    public function test_update_changes_goal_for_owner_student(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();

        $response = $this->actingAs($student)->patch(
            route('enrollment-goals.update', $goal),
            [
                'title' => '模擬試験に取り組む',
                'target_date' => now()->addDays(14)->toDateString(),
                'description' => '試験範囲を確認する。',
            ],
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success', '目標を更新しました。');

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'enrollment_id' => $enrollment->id,
            'title' => '模擬試験に取り組む',
            'description' => '試験範囲を確認する。',
        ]);
    }

    public function test_destroy_physically_deletes_goal_for_owner_student(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();

        $response = $this->actingAs($student)->delete(
            route('enrollment-goals.destroy', $goal),
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success', '目標を削除しました。');

        $this->assertDatabaseMissing('enrollment_goals', [
            'id' => $goal->id,
        ]);
    }

    public function test_mark_achieved_sets_achieved_at(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->unachieved()
            ->create();

        $response = $this->actingAs($student)->post(
            route('enrollment-goals.markAchieved', $goal),
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success', '目標を達成済みにしました。');

        $this->assertNotNull($goal->fresh()->achieved_at);
    }

    public function test_unmark_achieved_clears_achieved_at(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->achieved()
            ->create();

        $response = $this->actingAs($student)->delete(
            route('enrollment-goals.unmarkAchieved', $goal),
        );

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success', '目標を未達成に戻しました。');

        $this->assertNull($goal->fresh()->achieved_at);
    }

    public function test_other_student_cannot_edit_goal(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();

        $response = $this->actingAs($otherStudent)->get(
            route('enrollment-goals.edit', $goal),
        );

        $response->assertForbidden();
    }

    public function test_other_student_cannot_update_goal(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();

        $response = $this->actingAs($otherStudent)->patch(
            route('enrollment-goals.update', $goal),
            [
                'title' => '他人の目標を変更する',
                'target_date' => null,
                'description' => null,
            ],
        );

        $response->assertForbidden();

        $this->assertDatabaseMissing('enrollment_goals', [
            'id' => $goal->id,
            'title' => '他人の目標を変更する',
        ]);
    }

    public function test_other_student_cannot_delete_goal(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();

        $response = $this->actingAs($otherStudent)->delete(
            route('enrollment-goals.destroy', $goal),
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
        ]);
    }

    public function test_other_student_cannot_mark_goal_achieved(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->unachieved()
            ->create();

        $response = $this->actingAs($otherStudent)->post(
            route('enrollment-goals.markAchieved', $goal),
        );

        $response->assertForbidden();
        $this->assertNull($goal->fresh()->achieved_at);
    }

    public function test_other_student_cannot_unmark_goal_achieved(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()
            ->for($enrollment)
            ->achieved()
            ->create();

        $response = $this->actingAs($otherStudent)->delete(
            route('enrollment-goals.unmarkAchieved', $goal),
        );

        $response->assertForbidden();
        $this->assertNotNull($goal->fresh()->achieved_at);
    }

    public function test_coach_cannot_create_goal(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($coach)->post(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => 'コーチが作成する目標',
                'target_date' => null,
                'description' => null,
            ],
        );

        $response->assertForbidden();

        $this->assertDatabaseMissing('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => 'コーチが作成する目標',
        ]);
    }

    public function test_admin_cannot_create_goal(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($admin)->post(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => '管理者が作成する目標',
                'target_date' => null,
                'description' => null,
            ],
        );

        $response->assertForbidden();

        $this->assertDatabaseMissing('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => '管理者が作成する目標',
        ]);
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->post(
            route('enrollments.goals.store', $enrollment),
            [
                'title' => 'ログイン前の目標',
            ],
        );

        $response->assertRedirect(route('login'));
    }

    public function test_deleted_enrollment_prevents_goal_operations(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();

        $enrollment->delete();

        $editResponse = $this->actingAs($student)->get(
            route('enrollment-goals.edit', $goal),
        );

        $editResponse->assertForbidden();

        $deleteResponse = $this->delete(
            route('enrollment-goals.destroy', $goal),
        );

        $deleteResponse->assertForbidden();

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'enrollment_id' => $enrollment->id,
        ]);
    }
}
