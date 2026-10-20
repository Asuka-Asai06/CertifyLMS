<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * MeetingReminderNotification の通知内容を検証する Unit テスト。
 *
 * 前日通知と1時間前通知について、メール通知と
 * データベース通知に使用する配列が正しく生成されることを確認する。
 */
class MeetingReminderNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_eve_to_mail_has_expected_subject(): void
    {
        // Arrange
        $meeting = $this->createMeeting();
        $recipient = $meeting->student;

        $notification = new MeetingReminderNotification($meeting, 'eve');

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertInstanceOf(MailMessage::class, $mail);

        $this->assertSame(
            '【Certify LMS】明日、面談の予定があります',
            $mail->subject,
        );
    }

    public function test_one_hour_before_to_mail_has_expected_subject(): void
    {
        // Arrange
        $meeting = $this->createMeeting();
        $recipient = $meeting->student;

        $notification = new MeetingReminderNotification(
            $meeting,
            'one_hour_before',
        );

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertInstanceOf(MailMessage::class, $mail);

        $this->assertSame(
            '【Certify LMS】1時間後に面談の予定があります',
            $mail->subject,
        );
    }

    public function test_eve_to_mail_contains_greeting_topic_certification_and_scheduled_at(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create([
            'name' => '山田太郎',
        ]);

        $meeting = $this->createMeeting(
            $student,
            scheduledAt: Carbon::parse('2026-10-21 10:00:00'),
            topic: '試験対策について',
            certificationName: 'PHP認定試験',
        );

        $notification = new MeetingReminderNotification($meeting, 'eve');

        // Act
        $mail = $notification->toMail($student);

        // Assert
        $this->assertSame(
            'こんにちは、山田太郎さん',
            $mail->greeting,
        );

        $this->assertContains(
            '2026年10月21日 10:00～11:00に予定されている面談が明日となりました。',
            $mail->introLines,
        );

        $this->assertContains(
            '資格名：PHP認定試験',
            $mail->introLines,
        );

        $this->assertContains(
            '相談内容：試験対策について',
            $mail->introLines,
        );

        $this->assertContains(
            '予約日時：2026年10月21日 10:00',
            $mail->introLines,
        );

        $this->assertContains(
            'CertifyLMSをご利用いただきありがとうございます。',
            $mail->outroLines,
        );
    }

    public function test_one_hour_before_to_mail_contains_meeting_time_range(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $meeting = $this->createMeeting(
            $student,
            scheduledAt: Carbon::parse('2026-10-20 13:00:00'),
        );

        $notification = new MeetingReminderNotification(
            $meeting,
            'one_hour_before',
        );

        // Act
        $mail = $notification->toMail($student);

        // Assert
        $this->assertContains(
            '2026年10月20日 13:00～14:00に予定されている面談が1時間後となりました。',
            $mail->introLines,
        );
    }

    public function test_to_mail_action_url_is_meeting_url(): void
    {
        // Arrange
        $meeting = $this->createMeeting();
        $recipient = $meeting->student;

        $notification = new MeetingReminderNotification($meeting, 'eve');

        // Act
        $mail = $notification->toMail($recipient);

        // Assert
        $this->assertSame(
            '面談を確認する',
            $mail->actionText,
        );

        $this->assertSame(
            route('meetings.show', $meeting),
            $mail->actionUrl,
        );
    }

    public function test_eve_to_array_contains_expected_data(): void
    {
        // Arrange
        $meeting = $this->createMeeting(
            scheduledAt: Carbon::parse('2026-10-21 10:00:00'),
            topic: '試験対策について',
            certificationName: 'PHP認定試験',
        );

        $recipient = $meeting->student;

        $notification = new MeetingReminderNotification($meeting, 'eve');

        // Act
        $data = $notification->toArray($recipient);

        // Assert
        $this->assertSame(
            'meeting_reminder_eve',
            $data['notification_type'],
        );

        $this->assertSame(
            '明日、面談の予定があります',
            $data['title'],
        );

        $this->assertSame(
            '2026年10月21日 10:00～11:00に予定されている面談が明日となりました。',
            $data['message'],
        );

        $this->assertSame(
            'PHP認定試験',
            $data['certification_name'],
        );

        $this->assertSame(
            '試験対策について',
            $data['topic'],
        );

        $this->assertSame(
            '資格名：PHP認定試験 / 相談内容：試験対策について / 予約日時：2026年10月21日 10:00',
            $data['body_preview'],
        );

        $this->assertSame(
            route('meetings.show', $meeting),
            $data['url'],
        );
    }

    public function test_one_hour_before_to_array_contains_expected_data(): void
    {
        // Arrange
        $meeting = $this->createMeeting(
            scheduledAt: Carbon::parse('2026-10-20 13:00:00'),
            topic: '模擬試験の振り返り',
            certificationName: 'Laravel認定試験',
        );

        $recipient = $meeting->student;

        $notification = new MeetingReminderNotification(
            $meeting,
            'one_hour_before',
        );

        // Act
        $data = $notification->toArray($recipient);

        // Assert
        $this->assertSame(
            'meeting_reminder_one_hour_before',
            $data['notification_type'],
        );

        $this->assertSame(
            '1時間後に面談の予定があります',
            $data['title'],
        );

        $this->assertSame(
            '2026年10月20日 13:00～14:00に予定されている面談が1時間後となりました。',
            $data['message'],
        );

        $this->assertSame(
            'Laravel認定試験',
            $data['certification_name'],
        );

        $this->assertSame(
            '模擬試験の振り返り',
            $data['topic'],
        );

        $this->assertSame(
            route('meetings.show', $meeting),
            $data['url'],
        );
    }

    public function test_invalid_reminder_type_throws_exception(): void
    {
        // Arrange
        $meeting = $this->createMeeting();

        $notification = new MeetingReminderNotification(
            $meeting,
            'invalid',
        );

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);

        $notification->toArray($meeting->student);
    }

    /**
     * テスト用の面談を作成する。
     */
    private function createMeeting(
        ?User $student = null,
        ?Carbon $scheduledAt = null,
        string $topic = 'テスト相談内容',
        string $certificationName = 'テスト資格',
    ): Meeting {
        $student ??= User::factory()->student()->inProgress()->create();

        $coach = User::factory()->coach()->create();

        $certification = Certification::factory()->create([
            'name' => $certificationName,
        ]);

        $enrollment = Enrollment::factory()
            ->learning()
            ->create([
                'user_id' => $student->id,
                'certification_id' => $certification->id,
            ]);

        return Meeting::factory()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'scheduled_at' => $scheduledAt ?? now()->addDay(),
            'topic' => $topic,
        ]);
    }
}
