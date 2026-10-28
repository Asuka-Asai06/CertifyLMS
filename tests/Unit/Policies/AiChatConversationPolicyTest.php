<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\User;
use App\Policies\AiChatConversationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AiChatConversationPolicy の認可判定を検証する Unit テスト。
 *
 * viewAny / create: 学習中の student のみ許可。
 * view / update / delete / sendMessage: 学習中の student かつ会話の所有者のみ許可。
 * enrollment が紐づく場合、本人の学習中の enrollment であることを確認する。
 * admin / coach はすべての操作を拒否する。
 */
class AiChatConversationPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * viewAny / create は学習中の student のみ許可されることを確認する。
     */
    public function test_view_any_and_create_allow_only_active_students(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $graduatedStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $graduatedStudent->forceFill([
            'status' => UserStatus::Graduated,
        ])->save();

        $policy = new AiChatConversationPolicy;

        $this->assertTrue($policy->viewAny($student));
        $this->assertTrue($policy->create($student));

        foreach ([$graduatedStudent, $coach, $admin] as $user) {
            $this->assertFalse($policy->viewAny($user));
            $this->assertFalse($policy->create($user));
        }
    }

    /**
     * 会話の所有者である学習中の student は、会話にアクセスできることを確認する。
     */
    public function test_owner_can_view_update_delete_and_send_message(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student)
            ->create([
                'enrollment_id' => null,
            ]);

        $policy = new AiChatConversationPolicy;

        $this->assertTrue($policy->view($student, $conversation));
        $this->assertTrue($policy->update($student, $conversation));
        $this->assertTrue($policy->delete($student, $conversation));
        $this->assertTrue($policy->sendMessage($student, $conversation));
    }

    /**
     * 他人の会話にはアクセスできないことを確認する。
     */
    public function test_student_cannot_access_another_students_conversation(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($owner)
            ->create([
                'enrollment_id' => null,
            ]);

        $policy = new AiChatConversationPolicy;

        $this->assertFalse($policy->view($other, $conversation));
        $this->assertFalse($policy->update($other, $conversation));
        $this->assertFalse($policy->delete($other, $conversation));
        $this->assertFalse($policy->sendMessage($other, $conversation));
    }

    /**
     * admin / coach は会話にアクセスできないことを確認する。
     */
    public function test_admin_and_coach_cannot_access_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $conversation = AiChatConversation::factory()
            ->for($student)
            ->create([
                'enrollment_id' => null,
            ]);

        $policy = new AiChatConversationPolicy;

        foreach ([$coach, $admin] as $staff) {
            $this->assertFalse($policy->view($staff, $conversation));
            $this->assertFalse($policy->update($staff, $conversation));
            $this->assertFalse($policy->delete($staff, $conversation));
            $this->assertFalse($policy->sendMessage($staff, $conversation));
        }
    }

    /**
     * 学習中以外の student は会話にアクセスできないことを確認する。
     */
    public function test_non_active_student_cannot_access_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student)
            ->create([
                'enrollment_id' => null,
            ]);

        $student->forceFill([
            'status' => UserStatus::Graduated,
        ])->save();

        $policy = new AiChatConversationPolicy;

        $this->assertFalse($policy->view($student, $conversation));
        $this->assertFalse($policy->update($student, $conversation));
        $this->assertFalse($policy->delete($student, $conversation));
        $this->assertFalse($policy->sendMessage($student, $conversation));
    }

    /**
     * 本人の学習中の enrollment に紐づく会話はアクセスできることを確認する。
     */
    public function test_owner_can_access_conversation_for_own_learning_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $conversation = AiChatConversation::factory()
            ->forEnrollment($enrollment)
            ->create();

        $policy = new AiChatConversationPolicy;

        $this->assertTrue($policy->view($student, $conversation));
        $this->assertTrue($policy->update($student, $conversation));
        $this->assertTrue($policy->delete($student, $conversation));
        $this->assertTrue($policy->sendMessage($student, $conversation));
    }

    /**
     * 他人の enrollment に紐づく会話はアクセスできないことを確認する。
     */
    public function test_student_cannot_access_conversation_for_another_students_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();

        $otherEnrollment = Enrollment::factory()
            ->for($other)
            ->learning()
            ->create();

        // 会話の所有者と enrollment の所有者が異なる状態を作る。
        $conversation = AiChatConversation::factory()
            ->for($student)
            ->create([
                'enrollment_id' => $otherEnrollment->id,
            ]);

        $policy = new AiChatConversationPolicy;

        $this->assertFalse($policy->view($student, $conversation));
        $this->assertFalse($policy->update($student, $conversation));
        $this->assertFalse($policy->delete($student, $conversation));
        $this->assertFalse($policy->sendMessage($student, $conversation));
    }

    /**
     * 学習中以外の enrollment に紐づく会話はアクセスできないことを確認する。
     */
    public function test_student_cannot_access_conversation_for_non_learning_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->failed()
            ->create();

        $conversation = AiChatConversation::factory()
            ->forEnrollment($enrollment)
            ->create();

        $policy = new AiChatConversationPolicy;

        $this->assertFalse($policy->view($student, $conversation));
        $this->assertFalse($policy->update($student, $conversation));
        $this->assertFalse($policy->delete($student, $conversation));
        $this->assertFalse($policy->sendMessage($student, $conversation));
    }

    /**
     * 削除済み enrollment に紐づく会話はアクセスできないことを確認する。
     */
    public function test_student_cannot_access_conversation_for_deleted_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->learning()
            ->create();

        $conversation = AiChatConversation::factory()
            ->forEnrollment($enrollment)
            ->create();

        $enrollment->delete();

        $policy = new AiChatConversationPolicy;

        $this->assertFalse($policy->view($student, $conversation));
        $this->assertFalse($policy->update($student, $conversation));
        $this->assertFalse($policy->delete($student, $conversation));
        $this->assertFalse($policy->sendMessage($student, $conversation));
    }
}
