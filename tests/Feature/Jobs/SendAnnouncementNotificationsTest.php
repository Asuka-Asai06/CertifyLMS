<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\AnnouncementTargetType;
use App\Jobs\SendAnnouncementNotifications;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * お知らせ配信ジョブの Feature テスト。
 *
 * - 全受講生・資格指定・個人指定の配信対象を検証する
 * - 対象者がいない場合も正常終了することを検証する
 */
class SendAnnouncementNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 受講中の学生だけに全体向けのお知らせを通知する。
     */
    public function test_notifies_only_in_progress_students_for_all_students(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $graduatedStudent = User::factory()->student()->graduated()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $announcement = $this->createAnnouncement(
            AnnouncementTargetType::AllStudents,
        );

        $this->runJob($announcement);

        Notification::assertSentTo(
            $student,
            AnnouncementNotification::class,
        );

        Notification::assertNotSentTo(
            $graduatedStudent,
            AnnouncementNotification::class,
        );

        Notification::assertNotSentTo(
            $coach,
            AnnouncementNotification::class,
        );
    }

    /**
     * 指定資格を Learning 状態で受講している学生だけに通知する。
     */
    public function test_notifies_only_learning_students_for_certification(): void
    {
        Notification::fake();

        $certification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();

        $targetStudent = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $passedStudent = User::factory()->student()->inProgress()->create();

        Enrollment::factory()
            ->learning()
            ->for($targetStudent)
            ->for($certification)
            ->create();

        Enrollment::factory()
            ->learning()
            ->for($otherStudent)
            ->for($otherCertification)
            ->create();

        Enrollment::factory()
            ->passed()
            ->for($passedStudent)
            ->for($certification)
            ->create();

        $announcement = $this->createAnnouncement(
            AnnouncementTargetType::Certification,
            certificationId: $certification->id,
        );

        $this->runJob($announcement);

        Notification::assertSentTo(
            $targetStudent,
            AnnouncementNotification::class,
        );

        Notification::assertNotSentTo(
            $otherStudent,
            AnnouncementNotification::class,
        );

        Notification::assertNotSentTo(
            $passedStudent,
            AnnouncementNotification::class,
        );
    }

    /**
     * 個人指定では、指定された受講中の学生だけに通知する。
     */
    public function test_notifies_only_selected_in_progress_student(): void
    {
        Notification::fake();

        $targetStudent = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $announcement = $this->createAnnouncement(
            AnnouncementTargetType::User,
            userId: $targetStudent->id,
        );

        $this->runJob($announcement);

        Notification::assertSentTo(
            $targetStudent,
            AnnouncementNotification::class,
        );

        Notification::assertNotSentTo(
            $otherStudent,
            AnnouncementNotification::class,
        );
    }

    /**
     * 対象者がいない場合も、ジョブが正常終了する。
     */
    public function test_completes_without_error_when_there_are_no_recipients(): void
    {
        Notification::fake();

        $announcement = $this->createAnnouncement(
            AnnouncementTargetType::AllStudents,
        );

        $this->runJob($announcement);

        Notification::assertNothingSent();
    }

    /**
     * テスト用のお知らせを作成する。
     */
    private function createAnnouncement(
        AnnouncementTargetType $targetType,
        ?string $certificationId = null,
        ?string $userId = null,
    ): Announcement {
        return Announcement::factory()->create([
            'target_type' => $targetType,
            'target_certification_id' => $certificationId,
            'target_user_id' => $userId,
            'dispatched_count' => 0,
            'dispatched_at' => now(),
            'created_by' => User::factory()->admin()->create()->id,
        ]);
    }

    /**
     * 配信ジョブを同期的に実行する。
     */
    private function runJob(Announcement $announcement): void
    {
        app(SendAnnouncementNotifications::class, [
            'announcementId' => $announcement->id,
        ])->handle();
    }
}
