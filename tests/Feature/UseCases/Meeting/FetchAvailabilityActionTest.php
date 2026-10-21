<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\FetchAvailabilityAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

class FetchAvailabilityActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 担当コーチに空きがある場合、時間帯ごとの空き枠とコーチ数が返ることを確認する。
     */
    public function test_available_slots_are_returned_with_coach_counts(): void
    {
        $context = $this->createAvailabilityContext();
        $date = $context['date'];
        $coach = $context['coach'];

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay($date->dayOfWeek)
            ->timeRange('09:00:00', '11:00:00')
            ->active()
            ->create();

        $slots = app(FetchAvailabilityAction::class)(
            $context['enrollment'],
            $date,
        );

        $this->assertCount(2, $slots);
        $this->assertSame(
            '09:00',
            $slots[0]['slot_start']->format('H:i'),
        );
        $this->assertSame(
            '10:00',
            $slots[1]['slot_start']->format('H:i'),
        );
        $this->assertSame(1, $slots[0]['available_coach_count']);
        $this->assertSame(1, $slots[1]['available_coach_count']);
    }

    /**
     * 担当コーチがいない資格では空の Collection が返ることを確認する。
     */
    public function test_empty_collection_is_returned_when_certification_has_no_coaches(): void
    {
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $certification = Certification::factory()
            ->published()
            ->create();

        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->for($certification, 'certification')
            ->learning()
            ->create();

        $slots = app(FetchAvailabilityAction::class)(
            $enrollment,
            Carbon::parse('next Wednesday')->startOfDay(),
        );

        $this->assertInstanceOf(Collection::class, $slots);
        $this->assertTrue($slots->isEmpty());
    }

    /**
     * 予約済みの面談がある時間帯は、空き枠として数えられないことを確認する。
     */
    public function test_reserved_meeting_is_excluded_from_available_slots(): void
    {
        $context = $this->createAvailabilityContext();
        $date = $context['date'];
        $coach = $context['coach'];

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay($date->dayOfWeek)
            ->timeRange('09:00:00', '11:00:00')
            ->active()
            ->create();

        Meeting::factory()
            ->forEnrollment($context['enrollment'])
            ->forCoach($coach)
            ->reserved()
            ->create([
                'scheduled_at' => $date->copy()->setTime(9, 0),
            ]);

        $slots = app(FetchAvailabilityAction::class)(
            $context['enrollment'],
            $date,
        );

        $this->assertCount(1, $slots);
        $this->assertSame(
            '10:00',
            $slots->first()['slot_start']->format('H:i'),
        );
    }

    /**
     * 完了済みの面談も、その時間帯の空き枠から除外されることを確認する。
     */
    public function test_completed_meeting_is_excluded_from_available_slots(): void
    {
        $context = $this->createAvailabilityContext();
        $date = $context['date'];
        $coach = $context['coach'];

        CoachAvailability::factory()
            ->forCoach($coach)
            ->onDay($date->dayOfWeek)
            ->timeRange('09:00:00', '11:00:00')
            ->active()
            ->create();

        Meeting::factory()
            ->forEnrollment($context['enrollment'])
            ->forCoach($coach)
            ->completed()
            ->create([
                'scheduled_at' => $date->copy()->setTime(9, 0),
            ]);

        $slots = app(FetchAvailabilityAction::class)(
            $context['enrollment'],
            $date,
        );

        $this->assertCount(1, $slots);
        $this->assertSame(
            '10:00',
            $slots->first()['slot_start']->format('H:i'),
        );
    }

    /**
     * 空き枠取得に必要な受講生・コーチ・資格・受講登録を作成する。
     *
     * @return array{
     *     student: User,
     *     coach: User,
     *     certification: Certification,
     *     enrollment: Enrollment,
     *     date: Carbon
     * }
     */
    private function createAvailabilityContext(): array
    {
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $coach = User::factory()
            ->coach()
            ->create([
                'meeting_url' => 'https://example.com/meeting',
            ]);

        $certification = Certification::factory()
            ->published()
            ->create();

        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $student->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->for($certification, 'certification')
            ->learning()
            ->create();

        return [
            'student' => $student,
            'coach' => $coach,
            'certification' => $certification,
            'enrollment' => $enrollment,
            'date' => Carbon::parse('next Wednesday')->startOfDay(),
        ];
    }
}
