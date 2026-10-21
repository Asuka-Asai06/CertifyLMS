<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\UseCases\Meeting\IndexAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_upcoming_filter_returns_future_reserved_meetings(): void
    {
        $data = $this->createStudentWithEnrollment();
        $student = $data['student'];

        $upcoming = $this->createMeeting($data, [
            'scheduled_at' => now()->addDays(2),
        ]);

        $this->createMeeting($data, [
            'scheduled_at' => now()->subDays(2),
        ]);

        $this->createMeeting($data, [
            'status' => 'canceled',
            'scheduled_at' => now()->addDays(3),
        ]);

        $result = $this->action()($student, 'upcoming');

        $this->assertSame('upcoming', $result['filter']);
        $this->assertSame(
            [$upcoming->id],
            $result['meetings']->getCollection()->modelKeys(),
        );
    }

    public function test_past_filter_returns_canceled_and_completed_meetings(): void
    {
        $data = $this->createStudentWithEnrollment();

        $canceled = $this->createMeeting($data, [
            'status' => 'canceled',
            'scheduled_at' => now()->addDays(2),
        ]);

        $completed = $this->createMeeting($data, [
            'status' => 'completed',
            'scheduled_at' => now()->subDays(2),
        ]);

        $this->createMeeting($data, [
            'status' => 'reserved',
            'scheduled_at' => now()->subDays(3),
        ]);

        $result = $this->action()($data['student'], 'past');

        $this->assertSame('past', $result['filter']);
        $this->assertEqualsCanonicalizing(
            [$canceled->id, $completed->id],
            $result['meetings']->getCollection()->modelKeys(),
        );
    }

    public function test_all_filter_returns_all_meetings_of_student(): void
    {
        $data = $this->createStudentWithEnrollment();

        $first = $this->createMeeting($data, [
            'scheduled_at' => now()->addDays(2),
        ]);

        $second = $this->createMeeting($data, [
            'status' => 'canceled',
            'scheduled_at' => now()->subDays(2),
        ]);

        $result = $this->action()($data['student'], 'all');

        $this->assertSame('all', $result['filter']);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $result['meetings']->getCollection()->modelKeys(),
        );
    }

    public function test_default_filter_is_upcoming(): void
    {
        $data = $this->createStudentWithEnrollment();

        $upcoming = $this->createMeeting($data, [
            'scheduled_at' => now()->addDays(2),
        ]);

        $result = $this->action()($data['student']);

        $this->assertSame('upcoming', $result['filter']);
        $this->assertSame(
            [$upcoming->id],
            $result['meetings']->getCollection()->modelKeys(),
        );
    }

    public function test_meetings_are_limited_to_the_given_student(): void
    {
        $data = $this->createStudentWithEnrollment();
        $otherData = $this->createStudentWithEnrollment();

        $ownMeeting = $this->createMeeting($data, [
            'scheduled_at' => now()->addDays(2),
        ]);

        $otherMeeting = $this->createMeeting($otherData, [
            'scheduled_at' => now()->addDays(3),
        ]);

        $result = $this->action()($data['student'], 'all');

        $ids = $result['meetings']->getCollection()->modelKeys();

        $this->assertContains($ownMeeting->id, $ids);
        $this->assertNotContains($otherMeeting->id, $ids);
    }

    public function test_meetings_are_paginated_by_twenty(): void
    {
        $data = $this->createStudentWithEnrollment();

        for ($i = 0; $i < 21; $i++) {
            $this->createMeeting($data, [
                'scheduled_at' => now()->addDays($i + 1),
            ]);
        }

        $result = $this->action()($data['student'], 'all');

        $this->assertInstanceOf(
            LengthAwarePaginator::class,
            $result['meetings'],
        );
        $this->assertSame(20, $result['meetings']->perPage());
        $this->assertSame(21, $result['meetings']->total());
        $this->assertSame(20, $result['meetings']->count());
    }

    public function test_action_returns_remaining_meeting_quota(): void
    {
        $data = $this->createStudentWithEnrollment([
            'max_meetings' => 3,
        ]);

        MeetingQuotaTransaction::factory()
            ->for($data['student'], 'user')
            ->consumed()
            ->create();

        MeetingQuotaTransaction::factory()
            ->for($data['student'], 'user')
            ->refunded()
            ->create();

        MeetingQuotaTransaction::factory()
            ->for($data['student'], 'user')
            ->purchased(5)
            ->create();

        MeetingQuotaTransaction::factory()
            ->for($data['student'], 'user')
            ->grantedInitial(10)
            ->create();

        $result = $this->action()($data['student'], 'all');

        $this->assertSame(8, $result['meetingsRemaining']);
    }

    /**
     * @param array<string, mixed> $userAttributes
     *
     * @return array{
     *     student: User,
     *     coach: User,
     *     enrollment: Enrollment
     * }
     */
    private function createStudentWithEnrollment(
        array $userAttributes = [],
    ): array {
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create($userAttributes);

        $coach = User::factory()
            ->coach()
            ->create();

        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->learning()
            ->create();

        return [
            'student' => $student,
            'coach' => $coach,
            'enrollment' => $enrollment,
        ];
    }

    /**
     * @param array{
     *     student: User,
     *     coach: User,
     *     enrollment: Enrollment
     * } $data
     * @param array<string, mixed> $attributes
     */
    private function createMeeting(array $data, array $attributes = []): Meeting
    {
        return Meeting::factory()
            ->forEnrollment($data['enrollment'])
            ->forCoach($data['coach'])
            ->create($attributes);
    }

    private function action(): IndexAction
    {
        return app(IndexAction::class);
    }
}
