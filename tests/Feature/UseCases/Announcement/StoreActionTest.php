<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\UseCases\Announcement\StoreAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Announcement 作成 Action `StoreAction` の Feature テスト。
 *
 * - 配信履歴の保存と配信件数・配信日時・配信管理者の記録
 * - 全受講生・資格指定・ユーザー指定に応じた配信対象の絞り込み
 * - 受講中の受講生に対する通知送信
 * - `__invoke(User, array)` のシグネチャ
 */
class StoreActionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_creates_announcement_and_notifies_in_progress_students(): void
    {
        // Arrange
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $student = User::factory()->student()->inProgress()->create();

        $graduatedStudent = User::factory()->student()->graduated()->create();

        $coach = User::factory()->coach()->inProgress()->create();

        // Act
        $announcement = app(StoreAction::class)(
            $admin,
            [
                'title' => 'メンテナンスのお知らせ',
                'body' => 'システムメンテナンスを実施します。',
                'target_type' => AnnouncementTargetType::AllStudents->value,
            ],
        );

        // Assert: 配信履歴が保存される
        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'title' => 'メンテナンスのお知らせ',
            'body' => 'システムメンテナンスを実施します。',
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => null,
            'target_user_id' => null,
            'dispatched_count' => 1,
            'created_by' => $admin->id,
        ]);

        $this->assertNotNull($announcement->fresh()->dispatched_at);

        // Assert: 受講中の受講生だけに通知される
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

    public function test_certification_announcement_notifies_only_learning_students(): void
    {
        // Arrange
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->published()->create();

        $otherCertification = Certification::factory()->published()->create();

        $targetStudent = User::factory()->student()->inProgress()->create();

        $otherCertificationStudent = User::factory()->student()->inProgress()->create();

        $passedStudent = User::factory()->student()->inProgress()->create();

        // 指定資格を受講中の受講生
        Enrollment::factory()
            ->learning()
            ->for($targetStudent)
            ->for($certification)
            ->create();

        // 別の資格だけを受講している受講生
        Enrollment::factory()
            ->learning()
            ->for($otherCertificationStudent)
            ->for($otherCertification)
            ->create();

        // 指定資格の受講登録はあるが、合格済みの受講生
        Enrollment::factory()
            ->passed()
            ->for($passedStudent)
            ->for($certification)
            ->create();

        // Act
        $announcement = app(StoreAction::class)(
            $admin,
            [
                'title' => '資格別のお知らせ',
                'body' => '指定資格の受講生向けのお知らせです。',
                'target_type' => AnnouncementTargetType::Certification->value,
                'target_certification_id' => $certification->id,
            ],
        );

        // Assert: 配信履歴に対象資格と対象人数が記録される
        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
            'target_user_id' => null,
            'dispatched_count' => 1,
            'created_by' => $admin->id,
        ]);

        // Assert: 指定資格を Learning 状態で受講中の受講生だけに通知される
        Notification::assertSentTo(
            $targetStudent,
            AnnouncementNotification::class,
        );

        Notification::assertNotSentTo(
            $otherCertificationStudent,
            AnnouncementNotification::class,
        );

        Notification::assertNotSentTo(
            $passedStudent,
            AnnouncementNotification::class,
        );
    }

    public function test_user_announcement_notifies_only_the_selected_in_progress_student(): void
    {
        // Arrange
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $targetStudent = User::factory()->student()->inProgress()->create();

        $otherStudent = User::factory()->student()->inProgress()->create();

        // Act
        $announcement = app(StoreAction::class)(
            $admin,
            [
                'title' => '個別のお知らせ',
                'body' => '個別にお知らせします。',
                'target_type' => AnnouncementTargetType::User->value,
                'target_user_id' => $targetStudent->id,
            ],
        );

        // Assert: 指定ユーザーと配信件数が保存される
        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'target_type' => AnnouncementTargetType::User->value,
            'target_certification_id' => null,
            'target_user_id' => $targetStudent->id,
            'dispatched_count' => 1,
            'created_by' => $admin->id,
        ]);

        // Assert: 指定ユーザーだけに通知される
        Notification::assertSentTo(
            $targetStudent,
            AnnouncementNotification::class,
        );

        Notification::assertNotSentTo(
            $otherStudent,
            AnnouncementNotification::class,
        );
    }

    public function test_user_who_is_not_an_in_progress_student_is_not_notified(): void
    {
        // Arrange
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $graduatedStudent = User::factory()->student()->graduated()->create();

        // Act
        $announcement = app(StoreAction::class)(
            $admin,
            [
                'title' => '個別のお知らせ',
                'body' => '個別にお知らせします。',
                'target_type' => AnnouncementTargetType::User->value,
                'target_user_id' => $graduatedStudent->id,
            ],
        );

        // Assert: 対象者がいない場合も配信履歴は作成される
        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'target_user_id' => $graduatedStudent->id,
            'dispatched_count' => 0,
        ]);

        // Assert: 受講中ではないユーザーには通知されない
        Notification::assertNotSentTo(
            $graduatedStudent,
            AnnouncementNotification::class,
        );
    }

    public function test_signature_is_user_and_array(): void
    {
        $reflection = new \ReflectionMethod(
            StoreAction::class,
            '__invoke',
        );

        $params = $reflection->getParameters();

        $this->assertCount(2, $params);

        $this->assertSame(
            User::class,
            $params[0]->getType()?->getName(),
        );

        $this->assertSame(
            'array',
            $params[1]->getType()?->getName(),
        );

        $this->assertSame(
            Announcement::class,
            $reflection->getReturnType()?->getName(),
        );
    }

    public function test_archived_certification_announcement_notifies_only_learning_students(): void
    {
        // Arrange
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->archived()->create();

        $targetStudent = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        // 公開停止中の資格を Learning 状態で受講している受講生
        Enrollment::factory()
            ->learning()
            ->for($targetStudent)
            ->for($certification)
            ->create();

        // 別の資格を Learning 状態で受講している受講生
        $otherCertification = Certification::factory()->published()->create();

        Enrollment::factory()
            ->learning()
            ->for($otherStudent)
            ->for($otherCertification)
            ->create();

        // Act
        $announcement = app(StoreAction::class)(
            $admin,
            [
                'title' => '公開停止資格のお知らせ',
                'body' => '対象資格を学習中の受講生向けのお知らせです。',
                'target_type' => AnnouncementTargetType::Certification->value,
                'target_certification_id' => $certification->id,
            ],
        );

        // Assert: 公開停止中の資格でも配信履歴が保存される
        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
            'dispatched_count' => 1,
            'created_by' => $admin->id,
        ]);

        // Assert: 指定資格を学習中の受講生だけに通知される
        Notification::assertSentTo(
            $targetStudent,
            AnnouncementNotification::class,
        );

        Notification::assertNotSentTo(
            $otherStudent,
            AnnouncementNotification::class,
        );
    }

    public function test_certification_announcement_does_not_notify_withdrawn_enrollment_student(): void
    {
        // Arrange
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $certification = Certification::factory()->published()->create();

        $learningStudent = User::factory()->student()->inProgress()->create();
        $withdrawnEnrollmentStudent = User::factory()->student()->inProgress()->create();

        Enrollment::factory()
            ->learning()
            ->for($learningStudent)
            ->for($certification)
            ->create();

        Enrollment::factory()
            ->failed()
            ->for($withdrawnEnrollmentStudent)
            ->for($certification)
            ->create();

        // Act
        $announcement = app(StoreAction::class)(
            $admin,
            [
                'title' => '資格別のお知らせ',
                'body' => '指定資格の受講生向けのお知らせです。',
                'target_type' => AnnouncementTargetType::Certification->value,
                'target_certification_id' => $certification->id,
            ],
        );

        // Assert: Learning 状態の受講生だけが対象になる
        $this->assertSame(1, $announcement->dispatched_count);

        Notification::assertSentTo(
            $learningStudent,
            AnnouncementNotification::class,
        );

        Notification::assertNotSentTo(
            $withdrawnEnrollmentStudent,
            AnnouncementNotification::class,
        );
    }
}
