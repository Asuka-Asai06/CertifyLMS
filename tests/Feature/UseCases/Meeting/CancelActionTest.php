<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class CancelActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cancel_notifies_assigned_coach_only(): void
    {
        Notification::fake();

        $data = $this->createMeeting();

        $student = $data['student'];
        $coach = $data['coach'];
        $admin = $data['admin'];
        $meeting = $data['meeting'];

        $response = $this->actingAs($student)->post(
            route('meetings.cancel', $meeting),
        );

        $response->assertRedirect(route('meetings.show', $meeting));
        $response->assertSessionHas(
            'success',
            '面談をキャンセルしました。面談回数を返却しました。',
        );

        Notification::assertSentTo(
            $coach,
            MeetingCanceledNotification::class,
        );

        Notification::assertNotSentTo(
            $student,
            MeetingCanceledNotification::class,
        );

        Notification::assertNotSentTo(
            $admin,
            MeetingCanceledNotification::class,
        );
    }

    public function test_coach_cancel_notifies_in_progress_student(): void
    {
        Notification::fake();

        $data = $this->createMeeting();

        $student = $data['student'];
        $coach = $data['coach'];
        $admin = $data['admin'];
        $meeting = $data['meeting'];

        $response = $this->actingAs($coach)->post(
            route('meetings.cancel', $meeting),
        );

        $response->assertRedirect(route('meetings.show', $meeting));
        $response->assertSessionHas(
            'success',
            '面談をキャンセルしました。面談回数を返却しました。',
        );

        Notification::assertSentTo(
            $student,
            MeetingCanceledNotification::class,
        );

        Notification::assertNotSentTo(
            $coach,
            MeetingCanceledNotification::class,
        );

        Notification::assertNotSentTo(
            $admin,
            MeetingCanceledNotification::class,
        );
    }

    public function test_coach_cancel_does_not_notify_graduated_student(): void
    {
        Notification::fake();

        $data = $this->createMeeting(false);

        $student = $data['student'];
        $coach = $data['coach'];
        $meeting = $data['meeting'];

        $response = $this->actingAs($coach)->post(
            route('meetings.cancel', $meeting),
        );

        $response->assertRedirect(route('meetings.show', $meeting));

        Notification::assertNotSentTo(
            $student,
            MeetingCanceledNotification::class,
        );

        Notification::assertNotSentTo(
            $coach,
            MeetingCanceledNotification::class,
        );
    }

    /**
     * テスト用の面談と当事者を作成する。
     *
     * @return array{
     *     student: User,
     *     coach: User,
     *     admin: User,
     *     meeting: Meeting
     * }
     */
    private function createMeeting(
        bool $studentInProgress = true,
    ): array {
        $studentFactory = User::factory()->student();

        $studentFactory = $studentInProgress
            ? $studentFactory->inProgress()
            : $studentFactory->graduated();

        $student = $studentFactory->create([
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

        $enrollment = Enrollment::factory()
            ->for($student, 'user')
            ->for($certification, 'certification')
            ->learning()
            ->create();

        $meeting = Meeting::factory()
            ->forEnrollment($enrollment)
            ->forCoach($coach)
            ->forStudent($student)
            ->reserved()
            ->inFuture()
            ->create([
                'scheduled_at' => now()->addDays(7)->setTime(10, 0),
            ]);

        // キャンセル時に返却できるよう、面談回数を消費しておく
        $consumeAction = app(ConsumeQuotaAction::class);
        $transaction = $consumeAction($student, $meeting->id);

        $meeting->update([
            'meeting_quota_transaction_id' => $transaction->id,
        ]);

        return [
            'student' => $student,
            'coach' => $coach,
            'admin' => $admin,
            'meeting' => $meeting,
        ];
    }
}
