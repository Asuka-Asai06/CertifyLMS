<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\IndexAsCoachAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexAsCoachActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * コーチの担当する面談を取得し、デフォルトでは今後の面談を昇順で返す。
     */
    public function test_invoke_defaults_to_upcoming_and_orders_by_scheduled_at_ascending(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->createEnrollment();

        $laterMeeting = $this->createMeeting($enrollment, $coach, [
            'scheduled_at' => now()->addDays(3),
        ]);

        $earlierMeeting = $this->createMeeting($enrollment, $coach, [
            'scheduled_at' => now()->addDay(),
        ]);

        $pastMeeting = $this->createMeeting($enrollment, $coach, [
            'scheduled_at' => now()->subDay(),
        ]);

        $action = app(IndexAsCoachAction::class);

        $result = $action($coach, []);

        $this->assertSame('upcoming', $result['filter']);
        $this->assertNull($result['studentFilter']);
        $this->assertNull($result['enrollmentFilter']);
        $this->assertCount(2, $result['meetings']);

        $this->assertSame(
            [$earlierMeeting->id, $laterMeeting->id],
            $result['meetings']->getCollection()->pluck('id')->all(),
        );

        $this->assertNotContains(
            $pastMeeting->id,
            $result['meetings']->getCollection()->pluck('id')->all(),
        );
    }

    /**
     * past フィルターでは過去の面談を開催日時の降順で返す。
     */
    public function test_invoke_filters_past_and_orders_by_scheduled_at_descending(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->createEnrollment();

        $olderMeeting = $this->createMeeting($enrollment, $coach, [
            'scheduled_at' => now()->subDays(3),
            'status' => 'completed',
        ]);

        $newerMeeting = $this->createMeeting($enrollment, $coach, [
            'scheduled_at' => now()->subDay(),
            'status' => 'canceled',
        ]);

        $upcomingMeeting = $this->createMeeting($enrollment, $coach, [
            'scheduled_at' => now()->addDay(),
        ]);

        $action = app(IndexAsCoachAction::class);

        $result = $action($coach, ['filter' => 'past']);

        $this->assertSame('past', $result['filter']);
        $this->assertSame(
            [$newerMeeting->id, $olderMeeting->id],
            $result['meetings']->getCollection()->pluck('id')->all(),
        );

        $this->assertNotContains(
            $upcomingMeeting->id,
            $result['meetings']->getCollection()->pluck('id')->all(),
        );
    }

    /**
     * all フィルターでは開催日時の降順で面談を返す。
     */
    public function test_invoke_filters_all_and_orders_by_scheduled_at_descending(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->createEnrollment();

        $pastMeeting = $this->createMeeting($enrollment, $coach, [
            'scheduled_at' => now()->subDay(),
            'status' => 'completed',
        ]);

        $upcomingMeeting = $this->createMeeting($enrollment, $coach, [
            'scheduled_at' => now()->addDay(),
        ]);

        $action = app(IndexAsCoachAction::class);

        $result = $action($coach, ['filter' => 'all']);

        $this->assertSame('all', $result['filter']);
        $this->assertSame(
            [$upcomingMeeting->id, $pastMeeting->id],
            $result['meetings']->getCollection()->pluck('id')->all(),
        );
    }

    /**
     * ログイン中のコーチが担当する面談だけを取得する。
     */
    public function test_invoke_returns_only_meetings_for_the_given_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $enrollment = $this->createEnrollment();

        $ownMeeting = $this->createMeeting($enrollment, $coach);
        $otherMeeting = $this->createMeeting($enrollment, $otherCoach);

        $action = app(IndexAsCoachAction::class);

        $result = $action($coach, ['filter' => 'all']);

        $this->assertSame(
            [$ownMeeting->id],
            $result['meetings']->getCollection()->pluck('id')->all(),
        );

        $this->assertNotContains(
            $otherMeeting->id,
            $result['meetings']->getCollection()->pluck('id')->all(),
        );
    }

    /**
     * student フィルターでは指定した受講生の面談だけを取得する。
     */
    public function test_invoke_filters_by_student(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->createEnrollment();
        $otherEnrollment = $this->createEnrollment();

        $student = User::query()->findOrFail($enrollment->user_id);
        $otherStudent = User::query()->findOrFail($otherEnrollment->user_id);

        $studentMeeting = $this->createMeeting($enrollment, $coach);
        $otherStudentMeeting = $this->createMeeting($otherEnrollment, $coach);

        $action = app(IndexAsCoachAction::class);

        $result = $action($coach, [
            'filter' => 'all',
            'student' => $student->id,
        ]);

        $this->assertSame($student->id, $result['studentFilter']);
        $this->assertSame(
            [$studentMeeting->id],
            $result['meetings']->getCollection()->pluck('id')->all(),
        );

        $this->assertNotContains(
            $otherStudentMeeting->id,
            $result['meetings']->getCollection()->pluck('id')->all(),
        );

        $this->assertNotSame($student->id, $otherStudent->id);
    }

    /**
     * enrollment フィルターでは指定した受講登録の面談だけを取得する。
     */
    public function test_invoke_filters_by_enrollment(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->createEnrollment();
        $otherEnrollment = $this->createEnrollment();

        $targetMeeting = $this->createMeeting($enrollment, $coach);
        $otherMeeting = $this->createMeeting($otherEnrollment, $coach);

        $action = app(IndexAsCoachAction::class);

        $result = $action($coach, [
            'filter' => 'all',
            'enrollment' => $enrollment->id,
        ]);

        $this->assertSame($enrollment->id, $result['enrollmentFilter']);
        $this->assertSame(
            [$targetMeeting->id],
            $result['meetings']->getCollection()->pluck('id')->all(),
        );

        $this->assertNotContains(
            $otherMeeting->id,
            $result['meetings']->getCollection()->pluck('id')->all(),
        );
    }

    /**
     * student と enrollment の両方を指定した場合は、両条件を適用する。
     */
    public function test_invoke_applies_student_and_enrollment_filters_together(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->createEnrollment();
        $otherEnrollment = $this->createEnrollment();

        $student = User::query()->findOrFail($enrollment->user_id);

        $targetMeeting = $this->createMeeting($enrollment, $coach);
        $otherMeeting = $this->createMeeting($otherEnrollment, $coach);

        $action = app(IndexAsCoachAction::class);

        $result = $action($coach, [
            'filter' => 'all',
            'student' => $student->id,
            'enrollment' => $enrollment->id,
        ]);

        $this->assertSame(
            [$targetMeeting->id],
            $result['meetings']->getCollection()->pluck('id')->all(),
        );

        $this->assertNotContains(
            $otherMeeting->id,
            $result['meetings']->getCollection()->pluck('id')->all(),
        );
    }

    /**
     * 面談一覧は1ページあたり20件でページネーションされる。
     */
    public function test_invoke_paginates_meetings_by_twenty(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->createEnrollment();

        for ($i = 0; $i < 21; $i++) {
            $this->createMeeting($enrollment, $coach, [
                'scheduled_at' => now()->addDays($i + 1),
            ]);
        }

        $action = app(IndexAsCoachAction::class);

        $result = $action($coach, ['filter' => 'all']);

        $this->assertSame(20, $result['meetings']->perPage());
        $this->assertSame(21, $result['meetings']->total());
        $this->assertCount(20, $result['meetings']->items());
    }

    /**
     * 受講登録を作成する。
     */
    private function createEnrollment(): Enrollment
    {
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        return Enrollment::factory()
            ->for($student, 'user')
            ->learning()
            ->create();
    }

    /**
     * 指定した受講登録・コーチに紐づく面談を作成する。
     *
     * @param array<string, mixed> $attributes
     */
    private function createMeeting(
        Enrollment $enrollment,
        User $coach,
        array $attributes = [],
    ): Meeting {
        return Meeting::factory()
            ->forEnrollment($enrollment)
            ->forCoach($coach)
            ->create($attributes);
    }
}
