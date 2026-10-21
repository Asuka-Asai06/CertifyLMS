<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\UseCases\Meeting\StoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /**
     * 正常な入力で面談が reserved として作成されることを確認する。
     */
    public function test_meeting_is_created_as_reserved(): void
    {
        $context = $this->createReservationContext();

        $meeting = app(StoreAction::class)(
            $context['enrollment'],
            [
                'scheduled_at' => $context['scheduledAt']->toDateTimeString(),
                'topic' => 'テスト面談',
            ],
        );

        $this->assertSame(MeetingStatus::Reserved, $meeting->status);

        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'enrollment_id' => $context['enrollment']->id,
            'student_id' => $context['student']->id,
            'coach_id' => $context['coach']->id,
            'status' => MeetingStatus::Reserved->value,
        ]);
    }

    /**
     * 面談回数が不足している場合、例外が発生して予約が作成されないことを確認する。
     */
    public function test_reservation_fails_when_meeting_quota_is_insufficient(): void
    {
        $context = $this->createReservationContext(maxMeetings: 0);

        try {
            app(StoreAction::class)(
                $context['enrollment'],
                [
                    'scheduled_at' => $context['scheduledAt']->toDateTimeString(),
                    'topic' => 'テスト面談',
                ],
            );

            $this->fail('面談回数が不足しているため、例外が発生する必要があります。');
        } catch (InsufficientMeetingQuotaException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertDatabaseCount('meetings', 0);
        }
    }

    /**
     * 空き枠の検証に失敗した場合、予約が作成されないことを確認する。
     */
    public function test_reservation_is_not_created_when_slot_validation_fails(): void
    {
        $context = $this->createReservationContext();

        // 空き枠の終了時刻は含まれないため、17:00 を予約時刻に指定する。
        $invalidScheduledAt = $context['scheduledAt']->copy()
            ->setTime(17, 0, 0);

        try {
            app(StoreAction::class)(
                $context['enrollment'],
                [
                    'scheduled_at' => $invalidScheduledAt->toDateTimeString(),
                    'topic' => 'テスト面談',
                ],
            );

            $this->fail('空き枠の検証に失敗する必要があります。');
        } catch (MeetingOutOfAvailabilityException) {
            $this->assertDatabaseCount('meetings', 0);
        }
    }

    /**
     * 対象時刻に予約可能なコーチがいない場合、
     * 空き枠検証で例外が発生し、予約が追加されないことを確認する。
     */
    public function test_reservation_fails_when_no_coach_is_available(): void
    {
        $context = $this->createReservationContext();

        // 既存の予約によって対象時刻の空きがなくなった状態を作る。
        Meeting::factory()->create([
            'enrollment_id' => $context['enrollment']->id,
            'student_id' => $context['student']->id,
            'coach_id' => $context['coach']->id,
            'scheduled_at' => $context['scheduledAt'],
            'status' => MeetingStatus::Reserved->value,
            'topic' => '既存の予約',
            'meeting_url_snapshot' => $context['coach']->meeting_url,
        ]);

        try {
            app(StoreAction::class)(
                $context['enrollment'],
                [
                    'scheduled_at' => $context['scheduledAt']->toDateTimeString(),
                    'topic' => 'テスト面談',
                ],
            );

            $this->fail('予約可能なコーチがいないため、例外が発生する必要があります。');
        } catch (MeetingOutOfAvailabilityException) {
            // 既存の予約以外は作成されていないことを確認する。
            $this->assertDatabaseCount('meetings', 1);
        }
    }

    /**
     * 予約成功時に、対応する面談回数の消費記録が作成されることを確認する。
     */
    public function test_meeting_quota_consumption_is_recorded_for_reservation(): void
    {
        $context = $this->createReservationContext();

        $meeting = app(StoreAction::class)(
            $context['enrollment'],
            [
                'scheduled_at' => $context['scheduledAt']->toDateTimeString(),
                'topic' => 'テスト面談',
            ],
        );

        $meeting->refresh();

        $this->assertNotNull($meeting->meeting_quota_transaction_id);
        $this->assertNotNull($meeting->quotaTransaction);
    }

    /**
     * 予約テストに必要な受講生・コーチ・資格・空き枠・受講登録を作成する。
     *
     * @return array{
     *     student: User,
     *     coach: User,
     *     enrollment: Enrollment,
     *     scheduledAt: Carbon
     * }
     */
    private function createReservationContext(int $maxMeetings = 3): array
    {
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create([
                'max_meetings' => $maxMeetings,
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
            ->setTime(10, 0, 0)
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

        return [
            'student' => $student,
            'coach' => $coach,
            'enrollment' => $enrollment,
            'scheduledAt' => $scheduledAt,
        ];
    }
}
