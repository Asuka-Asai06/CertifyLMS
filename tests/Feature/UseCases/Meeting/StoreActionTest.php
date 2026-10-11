<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_reservation_notifies_assigned_coach_only(): void
    {
        Notification::fake();

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create([
                'max_meetings' => 3,
            ]);

        $coach = User::factory()
            ->coach()
            ->create([
                'meeting_url' => 'https://meet.example.com/coach',
            ]);

        $admin = User::factory()
            ->admin()
            ->create();

        $certification = Certification::factory()
            ->published()
            ->create();

        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        $scheduledAt = now()
            ->addDays(7)
            ->startOfDay()
            ->setHour(10)
            ->setMinute(0)
            ->setSecond(0)
            ->setMicrosecond(0);

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay($scheduledAt->dayOfWeek)
            ->timeRange('09:00:00', '17:00:00')
            ->create();

        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->for($certification, 'certification')
            ->learning()
            ->create();

        $response = $this->actingAs($student)->post(
            route('meetings.store', $enrollment),
            [
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'topic' => 'テスト面談',
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success', '面談を予約しました。');

        Notification::assertSentTo(
            $coach,
            MeetingReservedNotification::class,
        );

        Notification::assertNotSentTo(
            $student,
            MeetingReservedNotification::class,
        );

        Notification::assertNotSentTo(
            $admin,
            MeetingReservedNotification::class,
        );
    }
}
