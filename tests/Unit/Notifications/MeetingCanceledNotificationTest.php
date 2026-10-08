<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * MeetingCanceledNotification の通知内容を検証する Unit テスト。
 *
 * 面談がキャンセルされたときに送信されるメール通知と、
 * データベース通知に使用する配列が正しく生成されることを確認する。
 *
 * また、学生・コーチのどちらがキャンセルした場合でも、
 * キャンセル実行者が正しく通知内容に反映されることを確認する。
 */
class MeetingCanceledNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_mail_has_expected_subject(): void
    {
        // Arrange
        $meeting = $this->createCanceledMeetingByStudent();
        $recipient = $meeting->coach;

        $notification = new MeetingCanceledNotification($meeting);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertSame(
            '面談がキャンセルされました',
            $mail->subject,
        );
    }

    public function test_to_mail_contains_student_as_canceled_by_user_when_student_canceled(): void
    {
        // Arrange
        $meeting = $this->createCanceledMeetingByStudent();
        $recipient = $meeting->coach;

        $notification = new MeetingCanceledNotification($meeting);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertContains(
            $meeting->student->name.'さんが面談をキャンセルしました。',
            $mail->introLines,
        );

        $this->assertContains(
            '予約日時：'.$meeting->scheduled_at->format('Y年m月d日 H:i'),
            $mail->introLines,
        );
    }

    public function test_to_mail_contains_coach_as_canceled_by_user_when_coach_canceled(): void
    {
        // Arrange
        $meeting = $this->createCanceledMeetingByCoach();
        $recipient = $meeting->student;

        $notification = new MeetingCanceledNotification($meeting);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertContains(
            $meeting->coach->name.'さんが面談をキャンセルしました。',
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
        $meeting = $this->createCanceledMeetingByStudent();
        $recipient = $meeting->coach;

        $notification = new MeetingCanceledNotification($meeting);

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertSame(
            route('meetings.show', $meeting),
            $mail->actionUrl,
        );
    }

    public function test_to_array_contains_expected_data_when_student_canceled(): void
    {
        // Arrange
        $meeting = $this->createCanceledMeetingByStudent();
        $recipient = $meeting->coach;

        $notification = new MeetingCanceledNotification($meeting);

        // Act
        $data = $notification->toArray($recipient);

        // Assert
        $this->assertSame(
            'meeting_canceled',
            $data['notification_type'],
        );

        $this->assertSame(
            '面談がキャンセルされました',
            $data['title'],
        );

        $this->assertSame(
            $meeting->student->name.'さんが面談をキャンセルしました。',
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

    public function test_to_array_contains_expected_data_when_coach_canceled(): void
    {
        // Arrange
        $meeting = $this->createCanceledMeetingByCoach();
        $recipient = $meeting->student;

        $notification = new MeetingCanceledNotification($meeting);

        // Act
        $data = $notification->toArray($recipient);

        // Assert
        $this->assertSame(
            'meeting_canceled',
            $data['notification_type'],
        );

        $this->assertSame(
            '面談がキャンセルされました',
            $data['title'],
        );

        $this->assertSame(
            $meeting->coach->name.'さんが面談をキャンセルしました。',
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

    private function createCanceledMeetingByStudent(): Meeting
    {
        $student = User::factory()->create([
            'name' => '学生ユーザー',
        ]);

        $coach = User::factory()->create([
            'name' => 'コーチユーザー',
        ]);

        return Meeting::factory()->create([
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'status' => MeetingStatus::Canceled->value,
            'canceled_by_user_id' => $student->id,
            'canceled_at' => now(),
            'scheduled_at' => now()->addDays(7),
        ]);
    }

    private function createCanceledMeetingByCoach(): Meeting
    {
        $student = User::factory()->create([
            'name' => '学生ユーザー',
        ]);

        $coach = User::factory()->create([
            'name' => 'コーチユーザー',
        ]);

        return Meeting::factory()->create([
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'status' => MeetingStatus::Canceled->value,
            'canceled_by_user_id' => $coach->id,
            'canceled_at' => now(),
            'scheduled_at' => now()->addDays(7),
        ]);
    }
}
