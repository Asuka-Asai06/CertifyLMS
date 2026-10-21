<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $meeting->refresh();

        $this->assertSame(
            MeetingStatus::Canceled,
            $meeting->status,
        );

        $this->assertSame($student->id, $meeting->canceled_by_user_id);
        $this->assertNotNull($meeting->canceled_at);

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
     * すでにキャンセル済みの面談は、再度キャンセルできない。
     */
    public function test_already_canceled_meeting_cannot_be_canceled_again(): void
    {
        $data = $this->createMeeting();
        $meeting = $data['meeting'];

        $meeting->update([
            'status' => MeetingStatus::Canceled,
            'canceled_by_user_id' => $data['student']->id,
            'canceled_at' => now(),
        ]);

        $this->assertCancellationRejectedWithoutChanges($data, 403);
    }

    /**
     * 完了済みの面談はキャンセルできない。
     */
    public function test_completed_meeting_cannot_be_canceled(): void
    {
        $data = $this->createMeeting();
        $meeting = $data['meeting'];

        $meeting->update([
            'status' => MeetingStatus::Completed,
        ]);

        $this->assertCancellationRejectedWithoutChanges($data, 403);
    }

    /**
     * 開始済みの面談はキャンセルできず、面談レコードと面談回数が変わらないことを確認する。
     */
    public function test_meeting_cannot_be_canceled_after_start(): void
    {
        $data = $this->createMeeting();

        $meeting = $data['meeting'];

        $meeting->update([
            'scheduled_at' => now()->subMinute(),
        ]);

        // キャンセル実行前の状態を取得する。
        $beforeAttributes = $meeting->fresh()->getAttributes();
        $beforeTransactionCount = DB::table(
            $data['quotaTransactionTable'],
        )->count();

        $response = $this->actingAs($data['student'])->post(
            route('meetings.cancel', $meeting),
        );

        // 例外発生時のリダイレクトを確認する。
        $response->assertRedirect();

        // 面談レコードが変更されていないことを確認する。
        $this->assertSame(
            $beforeAttributes,
            $meeting->fresh()->getAttributes(),
        );

        // 面談回数の取引レコードが増減していないことを確認する。
        $this->assertSame(
            $beforeTransactionCount,
            DB::table($data['quotaTransactionTable'])->count(),
        );
    }

    /**
     * 完了済みの面談では、キャンセル処理や回数返却が実行されない。
     */
    public function test_failed_cancellation_does_not_change_meeting_or_quota_transactions(): void
    {
        $data = $this->createMeeting();

        $data['meeting']->update([
            'status' => MeetingStatus::Completed,
        ]);

        $this->assertCancellationRejectedWithoutChanges($data, 403);
    }

    /**
     * キャンセルが拒否された場合、面談レコードと面談回数トランザクションが変わらないことを確認する。
     *
     * @param array{
     *     student: User,
     *     coach: User,
     *     admin: User,
     *     meeting: Meeting,
     *     quotaTransactionTable: string
     * } $data
     */
    private function assertCancellationRejectedWithoutChanges(
        array $data,
        int $expectedStatus,
    ): void {
        $meeting = $data['meeting'];

        $beforeAttributes = $meeting->fresh()->getAttributes();

        $beforeTransactionCount = DB::table(
            $data['quotaTransactionTable'],
        )->count();

        $response = $this->actingAs($data['student'])->post(
            route('meetings.cancel', $meeting),
        );

        $response->assertStatus($expectedStatus);

        $this->assertSame(
            $beforeAttributes,
            $meeting->fresh()->getAttributes(),
        );

        $this->assertSame(
            $beforeTransactionCount,
            DB::table($data['quotaTransactionTable'])->count(),
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
     *     quotaTransactionTable: string
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
            'quotaTransactionTable' => $transaction->getTable(),
        ];
    }
}
