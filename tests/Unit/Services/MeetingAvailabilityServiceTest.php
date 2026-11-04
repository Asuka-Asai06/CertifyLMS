<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MeetingAvailabilityService の空き枠管理をテストする。
 *
 * @group external-api
 */
class MeetingAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    /**
     * Guzzle MockHandler を使用した MeetingAvailabilityService を生成する。
     */
    private function createService(MockHandler $mockHandler): MeetingAvailabilityService
    {
        $httpClient = new GuzzleClient([
            'handler' => HandlerStack::create($mockHandler),
        ]);

        $googleCalendarService = new GoogleCalendarService($httpClient);

        return new MeetingAvailabilityService($googleCalendarService);
    }

    public function test_returns_60min_slots_for_active_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01'); // Monday (dayOfWeek=1)
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        // 09-10 / 10-11 / 11-12 の 3 枠
        $this->assertCount(3, $slots);
        $this->assertSame('2026-06-01T09:00:00+09:00', $slots->first()['slot_start']->toIso8601String());
        $this->assertSame(1, $slots->first()['available_coach_count']);
    }

    public function test_excludes_existing_reserved_meetings(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();
        // 10:00 にすでに予約あり
        Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => Carbon::parse('2026-06-01 10:00:00'),
        ]);

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $times = $slots->map(fn (array $s) => $s['slot_start']->format('H:i'))->all();
        $this->assertEquals(['09:00', '11:00'], $times);
    }

    public function test_excludes_inactive_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '11:00:00')->inactive()->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $this->assertCount(0, $slots);
    }

    public function test_unions_multiple_coaches_into_available_count(): void
    {
        $certification = Certification::factory()->published()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();
        $this->attachCoach($certification, $coachA);
        $this->attachCoach($certification, $coachB);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coachA)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();
        CoachAvailability::factory()->forCoach($coachB)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $this->assertCount(1, $slots);
        $this->assertSame(2, $slots->first()['available_coach_count']);
    }

    public function test_validate_slot_throws_when_outside_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();

        $this->expectException(MeetingOutOfAvailabilityException::class);

        // 月曜の枠は 09-10 のみ。15:00 は枠外
        app(MeetingAvailabilityService::class)->validateSlot($certification, Carbon::parse('2026-06-01 15:00:00'));
    }

    public function test_validate_slot_succeeds_when_in_availability(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();

        // 例外が起きないことを確認
        app(MeetingAvailabilityService::class)->validateSlot($certification, Carbon::parse('2026-06-01 09:00:00'));
        $this->addToAssertionCount(1);
    }

    /**
     * Google Calendar の予定と重なる面談枠が除外されることを確認する。
     */
    public function test_excludes_slots_overlapping_google_calendar_events(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '12:00:00')
            ->create();

        $busyPeriodsByCoach = [
            (string) $coach->id => [
                [
                    'start' => CarbonImmutable::parse('2026-06-01 10:00:00'),
                    'end' => CarbonImmutable::parse('2026-06-01 11:00:00'),
                ],
            ],
        ];

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification(
            $certification,
            $date,
            $busyPeriodsByCoach,
        );

        $times = $slots->map(
            fn (array $slot) => $slot['slot_start']->format('H:i'),
        )->all();

        // 10:00-11:00 は Google Calendar の予定と重なるため除外される。
        $this->assertSame(['09:00', '11:00'], $times);
    }

    /**
     * Google Calendar の予定が面談枠の途中から始まる場合も、重なる面談枠が除外されることを確認する。
     */
    public function test_excludes_slots_partially_overlapping_google_calendar_events(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '12:00:00')
            ->create();

        $busyPeriodsByCoach = [
            (string) $coach->id => [
                [
                    'start' => CarbonImmutable::parse('2026-06-01 10:30:00'),
                    'end' => CarbonImmutable::parse('2026-06-01 11:30:00'),
                ],
            ],
        ];

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification(
            $certification,
            $date,
            $busyPeriodsByCoach,
        );

        $times = $slots->map(
            fn (array $slot) => $slot['slot_start']->format('H:i'),
        )->all();

        // 10:00-11:00 と 11:00-12:00 の両方が予定と重なる。
        $this->assertSame(['09:00'], $times);
    }

    /**
     * Google Calendar の予定と重ならない面談枠は残ることを確認する。
     */
    public function test_keeps_slots_not_overlapping_google_calendar_events(): void
    {
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '12:00:00')
            ->create();

        $busyPeriodsByCoach = [
            (string) $coach->id => [
                [
                    // 面談枠の終了時刻と予定の開始時刻が一致する。
                    'start' => CarbonImmutable::parse('2026-06-01 12:00:00'),
                    'end' => CarbonImmutable::parse('2026-06-01 13:00:00'),
                ],
            ],
        ];

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification(
            $certification,
            $date,
            $busyPeriodsByCoach,
        );

        $times = $slots->map(
            fn (array $slot) => $slot['slot_start']->format('H:i'),
        )->all();

        // 12:00 からの予定は 09:00-12:00 の面談枠とは重ならない。
        $this->assertSame(['09:00', '10:00', '11:00'], $times);
    }

    /**
     * Google Calendar の予定取得に成功した場合、予定と重なる面談枠が除外されることを確認する。
     */
    public function test_excludes_slots_overlapping_google_calendar_events_fetched_from_api(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        GoogleCalendarCredential::factory()->create([
            'user_id' => $coach->id,
            'calendar_id' => 'primary',
            'expires_at' => now()->addHour(),
        ]);

        $date = Carbon::parse('2026-06-01');

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '12:00:00')
            ->create();

        $mockHandler = new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'calendars' => [
                        'primary' => [
                            'busy' => [
                                [
                                    'start' => '2026-06-01T10:00:00+09:00',
                                    'end' => '2026-06-01T11:00:00+09:00',
                                ],
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ),
        ]);

        $service = $this->createService($mockHandler);

        // Act
        $slots = $service->slotsForCertification($certification, $date);

        // Assert
        $times = $slots->map(
            fn (array $slot) => $slot['slot_start']->format('H:i'),
        )->all();

        $this->assertSame(['09:00', '11:00'], $times);
        $this->assertSame(0, $mockHandler->count());
    }

    /**
     * Google Calendar API の取得に失敗しても、面談可能枠を予約候補として返すことを確認する。
     */
    public function test_keeps_available_slots_when_google_calendar_api_fails(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        GoogleCalendarCredential::factory()->create([
            'user_id' => $coach->id,
            'calendar_id' => 'primary',
            'expires_at' => now()->addHour(),
        ]);

        $date = Carbon::parse('2026-06-01');

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay(1)
            ->timeRange('09:00:00', '12:00:00')
            ->create();

        $mockHandler = new MockHandler([
            new Response(
                500,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'error' => [
                        'message' => 'Internal Server Error',
                    ],
                ], JSON_THROW_ON_ERROR),
            ),
        ]);

        $service = $this->createService($mockHandler);

        // Act
        $slots = $service->slotsForCertification($certification, $date);

        // Assert
        $times = $slots->map(
            fn (array $slot) => $slot['slot_start']->format('H:i'),
        )->all();

        // API エラー時は予定なしとして扱い、通常どおり予約候補を返す。
        $this->assertSame(['09:00', '10:00', '11:00'], $times);
        $this->assertSame(0, $mockHandler->count());
    }
}
