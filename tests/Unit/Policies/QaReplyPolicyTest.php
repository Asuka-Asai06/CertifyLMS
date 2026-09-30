<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaReplyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaReplyPolicy の ability × Role × 資格状態 × 投稿者のマトリクス検証。
 *
 * view (admin=全件 / coach=担当中かつpublished / student=published のみ) /
 * create (student=published / coach=担当中かつpublished / admin=false) /
 * update (student・coachかつ自分の返信のみ) /
 * delete (admin=全件 / student・coach=自分の返信のみ)
 * の 4 ability を網羅する。
 *
 * すべての ability で UserStatus::InProgress を要求し、
 * Graduated などの受講中ではないユーザーは不許可とする。
 */
class QaReplyPolicyTest extends TestCase
{
    use RefreshDatabase;

    private QaReplyPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new QaReplyPolicy;
    }

    /**
     * コーチの資格担当を作成する。
     *
     * certification_coach_assignments では
     * assigned_by_user_id と assigned_at が必須のため、
     * 管理者を作成して担当登録まで行う。
     */
    private function assignCoach(
        Certification $certification,
        User $coach,
    ): void {
        $admin = User::factory()->admin()->inProgress()->create();

        $certification->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_admin_can_view_any_reply(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();
        $reply = QaReply::factory()->create([
            'qa_thread_id' => QaThread::factory()->create([
                'certification_id' => $certification->id,
            ])->id,
        ]);

        $result = $this->policy->view($admin, $reply);

        $this->assertTrue($result);
    }

    public function test_coach_can_view_reply_of_assigned_published_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        $result = $this->policy->view($coach, $reply);

        $this->assertTrue($result);
    }

    public function test_coach_cannot_view_reply_of_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        $result = $this->policy->view($coach, $reply);

        $this->assertFalse($result);
    }

    public function test_coach_cannot_view_reply_of_unpublished_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();
        $this->assignCoach($certification, $coach);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        $result = $this->policy->view($coach, $reply);

        $this->assertFalse($result);
    }

    public function test_student_can_view_reply_of_published_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        $result = $this->policy->view($student, $reply);

        $this->assertTrue($result);
    }

    public function test_student_cannot_view_reply_of_unpublished_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        $result = $this->policy->view($student, $reply);

        $this->assertFalse($result);
    }

    public function test_admin_cannot_create_reply(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $thread = QaThread::factory()->create([
            'certification_id' => Certification::factory()->published()->create()->id,
        ]);

        $result = $this->policy->create($admin, $thread);

        $this->assertFalse($result);
    }

    public function test_student_can_create_reply_to_published_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create([
            'certification_id' => Certification::factory()->published()->create()->id,
        ]);

        $result = $this->policy->create($student, $thread);

        $this->assertTrue($result);
    }

    public function test_student_cannot_create_reply_to_unpublished_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create([
            'certification_id' => Certification::factory()->draft()->create()->id,
        ]);

        $result = $this->policy->create($student, $thread);

        $this->assertFalse($result);
    }

    public function test_coach_can_create_reply_to_assigned_published_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $result = $this->policy->create($coach, $thread);

        $this->assertTrue($result);
    }

    public function test_coach_cannot_create_reply_to_unassigned_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $result = $this->policy->create($coach, $thread);

        $this->assertFalse($result);
    }

    public function test_coach_cannot_create_reply_to_unpublished_certification(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();
        $this->assignCoach($certification, $coach);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        $result = $this->policy->create($coach, $thread);

        $this->assertFalse($result);
    }

    public function test_student_can_update_own_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $reply = QaReply::factory()->create([
            'user_id' => $student->id,
        ]);

        $result = $this->policy->update($student, $reply);

        $this->assertTrue($result);
    }

    public function test_student_cannot_update_other_users_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $reply = QaReply::factory()->create([
            'user_id' => $otherStudent->id,
        ]);

        $result = $this->policy->update($student, $reply);

        $this->assertFalse($result);
    }

    public function test_coach_can_update_own_reply(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $reply = QaReply::factory()->create([
            'user_id' => $coach->id,
        ]);

        $result = $this->policy->update($coach, $reply);

        $this->assertTrue($result);
    }

    public function test_admin_cannot_update_reply(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $reply = QaReply::factory()->create();

        $result = $this->policy->update($admin, $reply);

        $this->assertFalse($result);
    }

    public function test_student_can_delete_own_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $reply = QaReply::factory()->create([
            'user_id' => $student->id,
        ]);

        $result = $this->policy->delete($student, $reply);

        $this->assertTrue($result);
    }

    public function test_student_cannot_delete_other_users_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $reply = QaReply::factory()->create([
            'user_id' => $otherStudent->id,
        ]);

        $result = $this->policy->delete($student, $reply);

        $this->assertFalse($result);
    }

    public function test_coach_can_delete_own_reply(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $reply = QaReply::factory()->create([
            'user_id' => $coach->id,
        ]);

        $result = $this->policy->delete($coach, $reply);

        $this->assertTrue($result);
    }

    public function test_coach_cannot_delete_other_users_reply(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $otherCoach = User::factory()->coach()->inProgress()->create();

        $reply = QaReply::factory()->create([
            'user_id' => $otherCoach->id,
        ]);

        $result = $this->policy->delete($coach, $reply);

        $this->assertFalse($result);
    }

    public function test_admin_can_delete_any_reply(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $reply = QaReply::factory()->create();

        $result = $this->policy->delete($admin, $reply);

        $this->assertTrue($result);
    }

    public function test_graduated_user_cannot_view_reply(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        $result = $this->policy->view($student, $reply);

        $this->assertFalse($result);
    }

    public function test_graduated_user_cannot_create_reply(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $thread = QaThread::factory()->create([
            'certification_id' => Certification::factory()->published()->create()->id,
        ]);

        $result = $this->policy->create($student, $thread);

        $this->assertFalse($result);
    }

    public function test_graduated_user_cannot_update_reply(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $reply = QaReply::factory()->create([
            'user_id' => $student->id,
        ]);

        $result = $this->policy->update($student, $reply);

        $this->assertFalse($result);
    }

    public function test_graduated_user_cannot_delete_reply(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $reply = QaReply::factory()->create([
            'user_id' => $student->id,
        ]);

        $result = $this->policy->delete($student, $reply);

        $this->assertFalse($result);
    }
}
