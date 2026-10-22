<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingReminderLog;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * notifications:send-meeting-reminders Schedule Command を検証する Feature テスト。
 *
 * 前日通知・1時間前通知の対象抽出、通知対象者、対象外の面談、
 * 重複送信防止、不正なオプションを検証する。
 */
class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_eve_reminder_is_sent_for_reserved_meetings_on_the_next_day(): void
    {
        // Arrange
        Notification::fake();

        $this->travelTo(now()->setTime(18, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();

        $meeting = $this->createMeeting(
            $student,
            $coach,
            now()->addDay()->setTime(10, 0),
        );

        // Act
        $this->artisan('notifications:send-meeting-reminders --window=eve')
            ->assertExitCode(0);

        // Assert
        Notification::assertSentTo(
            $student,
            MeetingReminderNotification::class,
        );

        Notification::assertSentTo(
            $coach,
            MeetingReminderNotification::class,
        );

        $this->assertDatabaseHas('meeting_reminder_logs', [
            'meeting_id' => $meeting->id,
            'reminder_type' => 'eve',
        ]);
    }

    /**
     * 前日リマインダー通知がキューに登録されることを確認する。
     */
    public function test_eve_reminder_notification_is_queued(): void
    {
        Queue::fake();

        $this->travelTo(now()->setTime(18, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();

        $this->createMeeting(
            $student,
            $coach,
            now()->addDay()->setTime(10, 0),
        );

        $this->artisan('notifications:send-meeting-reminders --window=eve')
            ->assertExitCode(0);

        Queue::assertPushed(
            SendQueuedNotifications::class,
            function (SendQueuedNotifications $job): bool {
                return $job->notification instanceof MeetingReminderNotification;
            },
        );
    }

    public function test_one_hour_before_reminder_is_sent_for_meetings_in_the_target_window(): void
    {
        // Arrange
        Notification::fake();

        $this->travelTo(now()->setTime(10, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();

        $meeting = $this->createMeeting(
            $student,
            $coach,
            now()->addHour()->subMinutes(2),
        );

        // Act
        $this->artisan(
            'notifications:send-meeting-reminders --window=one_hour_before',
        )->assertExitCode(0);

        // Assert
        Notification::assertSentTo(
            $student,
            MeetingReminderNotification::class,
        );

        Notification::assertSentTo(
            $coach,
            MeetingReminderNotification::class,
        );

        $this->assertDatabaseHas('meeting_reminder_logs', [
            'meeting_id' => $meeting->id,
            'reminder_type' => 'one_hour_before',
        ]);
    }

    public function test_student_who_is_not_in_progress_does_not_receive_reminder(): void
    {
        // Arrange
        Notification::fake();

        $this->travelTo(now()->setTime(18, 0));

        $student = User::factory()->student()->invited()->create();
        $coach = User::factory()->coach()->create();

        $this->createMeeting(
            $student,
            $coach,
            now()->addDay()->setTime(10, 0),
        );

        // Act
        $this->artisan('notifications:send-meeting-reminders --window=eve')
            ->assertExitCode(0);

        // Assert
        Notification::assertNotSentTo(
            $student,
            MeetingReminderNotification::class,
        );

        Notification::assertSentTo(
            $coach,
            MeetingReminderNotification::class,
        );
    }

    public function test_canceled_and_completed_meetings_do_not_receive_reminders(): void
    {
        // Arrange
        Notification::fake();

        $this->travelTo(now()->setTime(18, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();

        $canceledMeeting = $this->createMeeting(
            $student,
            $coach,
            now()->addDay()->setTime(10, 0),
            MeetingStatus::Canceled,
        );

        $completedMeeting = $this->createMeeting(
            $student,
            $coach,
            now()->addDay()->setTime(11, 0),
            MeetingStatus::Completed,
        );

        // Act
        $this->artisan('notifications:send-meeting-reminders --window=eve')
            ->assertExitCode(0);

        // Assert
        Notification::assertNothingSent();

        $this->assertDatabaseMissing('meeting_reminder_logs', [
            'meeting_id' => $canceledMeeting->id,
            'reminder_type' => 'eve',
        ]);

        $this->assertDatabaseMissing('meeting_reminder_logs', [
            'meeting_id' => $completedMeeting->id,
            'reminder_type' => 'eve',
        ]);
    }

    public function test_same_meeting_and_reminder_type_are_not_processed_twice(): void
    {
        // Arrange
        Notification::fake();

        $this->travelTo(now()->setTime(18, 0));

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();

        $meeting = $this->createMeeting(
            $student,
            $coach,
            now()->addDay()->setTime(10, 0),
        );

        // Act
        $this->artisan('notifications:send-meeting-reminders --window=eve')
            ->assertExitCode(0);

        $this->artisan('notifications:send-meeting-reminders --window=eve')
            ->assertExitCode(0);

        // Assert
        $this->assertSame(
            1,
            MeetingReminderLog::query()
                ->where('meeting_id', $meeting->id)
                ->where('reminder_type', 'eve')
                ->count(),
        );

        Notification::assertCount(2);
    }

    public function test_invalid_window_returns_failure(): void
    {
        // Arrange
        Notification::fake();

        // Act & Assert
        $this->artisan('notifications:send-meeting-reminders --window=invalid')
            ->assertExitCode(1);

        Notification::assertNothingSent();
    }

    /**
     * テスト用の面談を作成する。
     */
    private function createMeeting(
        User $student,
        User $coach,
        Carbon $scheduledAt,
        MeetingStatus $status = MeetingStatus::Reserved,
    ): Meeting {
        $certification = Certification::factory()->create();

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
            'scheduled_at' => $scheduledAt,
            'status' => $status,
            'topic' => 'テスト相談内容',
        ]);
    }
}
