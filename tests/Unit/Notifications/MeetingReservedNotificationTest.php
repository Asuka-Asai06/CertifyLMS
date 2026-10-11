<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * MeetingReservedNotification の通知内容を検証する Unit テスト。
 *
 * 面談予約が入ったときに送信されるメール通知と、
 * データベース通知に使用する配列が正しく生成されることを確認する。
 */
class MeetingReservedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_mail_has_expected_subject(): void
    {
        // Arrange
        $student = User::factory()->create([
            'name' => '山田太郎',
        ]);
        $meeting = Meeting::factory()->create([
            'student_id' => $student->id,
        ]);
        $recipient = $meeting->coach;

        $notification = new MeetingReservedNotification($meeting);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertSame(
            '【Certify LMS】山田太郎さんから新規面談予約',
            $mail->subject,
        );
    }

    public function test_to_mail_contains_student_name_and_scheduled_at(): void
    {
        // Arrange
        $student = User::factory()->create([
            'name' => '山田太郎',
        ]);

        $meeting = Meeting::factory()->create([
            'student_id' => $student->id,
            'scheduled_at' => now()->addDays(7)->setTime(19, 0),
        ]);

        $recipient = $meeting->coach;

        $notification = new MeetingReservedNotification($meeting);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertContains(
            '山田太郎さんから面談の予約が入りました。',
            $mail->introLines,
        );

        $this->assertContains(
            '予約日時：'.$meeting->scheduled_at->format('Y年m月d日 H:i'),
            $mail->introLines,
        );
    }

    public function test_to_mail_action_url_is_meeting_url(): void
    {
        // Arrange
        $meeting = Meeting::factory()->create();
        $recipient = $meeting->coach;

        $notification = new MeetingReservedNotification($meeting);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertSame(
            route('meetings.show', $meeting),
            $mail->actionUrl,
        );
    }

    public function test_to_array_contains_expected_data(): void
    {
        // Arrange
        $student = User::factory()->create([
            'name' => '山田太郎',
        ]);

        $meeting = Meeting::factory()->create([
            'student_id' => $student->id,
            'scheduled_at' => now()->addDays(7)->setTime(19, 0),
        ]);

        $recipient = $meeting->coach;

        $notification = new MeetingReservedNotification($meeting);

        // Act
        $data = $notification->toArray($recipient);

        // Assert
        $this->assertSame(
            'meeting_reserved',
            $data['notification_type'],
        );

        $this->assertSame(
            '面談の予約が入りました',
            $data['title'],
        );

        $this->assertSame(
            '山田太郎さんから面談の予約が入りました。',
            $data['message'],
        );

        $this->assertSame(
            '予約日時：'.$meeting->scheduled_at->format('Y年m月d日 H:i'),
            $data['body_preview'],
        );

        $this->assertSame(
            route('meetings.show', $meeting),
            $data['url'],
        );
    }
}
