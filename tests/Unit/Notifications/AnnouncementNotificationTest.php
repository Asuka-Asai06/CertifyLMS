<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * AnnouncementNotification の通知内容とキュー実装を検証する Unit テスト。
 *
 * 管理者のお知らせに関して、メール通知・データベース通知の内容が
 * 正しく生成されること、および非同期キュー送信に対応していることを確認する。
 */
class AnnouncementNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_implements_should_queue(): void
    {
        // Arrange
        $announcement = Announcement::factory()->create();

        $notification = new AnnouncementNotification($announcement);

        // Assert
        $this->assertInstanceOf(
            ShouldQueue::class,
            $notification,
            'お知らせ通知は ShouldQueue を実装して非同期送信されるはず。',
        );
    }

    public function test_via_returns_database_and_mail_channels(): void
    {
        // Arrange
        $announcement = Announcement::factory()->create();

        $notification = new AnnouncementNotification($announcement);
        $recipient = User::factory()->create();

        // Act
        $channels = $notification->via($recipient);

        // Assert
        $this->assertSame(['database', 'mail'], $channels);
    }

    public function test_to_mail_has_expected_subject_and_markdown_view(): void
    {
        // Arrange
        $announcement = Announcement::factory()->create([
            'title' => 'メンテナンスのお知らせ',
            'body' => 'メンテナンスを実施します。',
        ]);

        $recipient = User::factory()->create([
            'name' => '山田太郎',
        ]);

        $notification = new AnnouncementNotification($announcement);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertInstanceOf(MailMessage::class, $mail);

        $this->assertSame(
            '【Certify LMS】メンテナンスのお知らせ',
            $mail->subject,
        );

        $this->assertSame('emails.announcements', $mail->markdown);

        $this->assertSame(
            '山田太郎',
            $mail->viewData['recipientName'],
        );

        $this->assertSame(
            $announcement->id,
            $mail->viewData['announcement']->id,
        );

        $this->assertSame(
            route('notifications.show', $notification->id),
            $mail->viewData['notificationUrl'],
        );
    }

    public function test_to_array_contains_expected_data(): void
    {
        // Arrange
        $announcement = Announcement::factory()->create([
            'title' => 'メンテナンスのお知らせ',
            'body' => 'メンテナンスを実施します。',
        ]);

        $recipient = User::factory()->create();

        $notification = new AnnouncementNotification($announcement);

        // Act
        $data = $notification->toArray($recipient);

        // Assert
        $this->assertSame(
            'admin_announcement',
            $data['notification_type'],
        );

        $this->assertSame(
            'メンテナンスのお知らせ',
            $data['title'],
        );

        $this->assertSame(
            '管理者からのお知らせが届いています。',
            $data['message'],
        );

        $this->assertSame(
            'メンテナンスを実施します。',
            $data['body'],
        );

        $this->assertSame(
            $announcement->id,
            $data['announcement_id'],
        );

        $this->assertSame(
            route('notifications.show', $notification->id),
            $data['url'],
        );
    }
}
