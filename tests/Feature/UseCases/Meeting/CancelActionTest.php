<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Services\GoogleCalendarService;
use App\UseCases\Meeting\CancelAction;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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

        $beforeAttributes = $meeting->fresh()->getAttributes();
        $beforeTransactionCount = DB::table(
            $data['quotaTransactionTable'],
        )->count();

        $response = $this->actingAs($data['student'])->post(
            route('meetings.cancel', $meeting),
        );

        $response->assertRedirect();

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
     * 面談キャンセル通知がキューに登録されることを確認する。
     */
    public function test_meeting_cancellation_notification_is_queued(): void
    {
        Queue::fake();

        $data = $this->createMeeting();

        $this->actingAs($data['student'])->post(
            route('meetings.cancel', $data['meeting']),
        );

        Queue::assertPushed(
            SendQueuedNotifications::class,
            function (SendQueuedNotifications $job): bool {
                return $job->notification instanceof MeetingCanceledNotification;
            },
        );
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

    /**
     * 面談キャンセル時に Google Calendar の予定削除 API が呼び出されることを確認する。
     *
     * @group external-api
     */
    public function test_google_calendar_event_is_deleted_when_meeting_is_canceled(): void
    {
        // Arrange
        Notification::fake();

        $data = $this->createMeeting();

        GoogleCalendarCredential::factory()->create([
            'user_id' => $data['coach']->id,
        ]);

        $data['meeting']->update([
            'google_calendar_event_id' => 'google-calendar-event-123',
        ]);

        // Google Calendar のイベント削除は成功時に HTTP 204 を返す。
        $mockHandler = new MockHandler([
            new Response(204),
        ]);

        $this->app->instance(
            GoogleCalendarService::class,
            new GoogleCalendarService(
                new Client([
                    'handler' => HandlerStack::create($mockHandler),
                ]),
            ),
        );

        // Act
        app(CancelAction::class)(
            $data['meeting'],
            $data['student'],
        );

        // Assert
        $this->assertDatabaseHas('meetings', [
            'id' => $data['meeting']->id,
            'status' => MeetingStatus::Canceled->value,
            'google_calendar_event_id' => 'google-calendar-event-123',
        ]);

        // モックした API レスポンスが消費されていることを確認する。
        $this->assertSame(0, $mockHandler->count());
    }

    /**
     * Google Calendar の予定削除 API が失敗しても、
     * 面談キャンセルと面談回数の返却が正常に完了することを確認する。
     *
     * @group external-api
     */
    public function test_meeting_is_canceled_even_when_google_calendar_event_deletion_fails(): void
    {
        // Arrange
        Notification::fake();

        $data = $this->createMeeting();

        GoogleCalendarCredential::factory()->create([
            'user_id' => $data['coach']->id,
        ]);

        $data['meeting']->update([
            'google_calendar_event_id' => 'google-calendar-event-123',
        ]);

        // Google Calendar のイベント削除 API が HTTP 500 を返す。
        $mockHandler = new MockHandler([
            new Response(
                500,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'error' => [
                        'code' => 500,
                        'message' => 'Internal Server Error',
                        'errors' => [
                            [
                                'message' => 'Internal Server Error',
                                'domain' => 'global',
                                'reason' => 'backendError',
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ),
        ]);

        $this->app->instance(
            GoogleCalendarService::class,
            new GoogleCalendarService(
                new Client([
                    'handler' => HandlerStack::create($mockHandler),
                ]),
            ),
        );

        $beforeTransactionCount = DB::table(
            $data['quotaTransactionTable'],
        )->count();

        // Act
        app(CancelAction::class)(
            $data['meeting'],
            $data['student'],
        );

        // Assert
        $this->assertDatabaseHas('meetings', [
            'id' => $data['meeting']->id,
            'status' => MeetingStatus::Canceled->value,
            'google_calendar_event_id' => 'google-calendar-event-123',
        ]);

        // 面談回数の返却トランザクションが追加されていることを確認する。
        $this->assertSame(
            $beforeTransactionCount + 1,
            DB::table($data['quotaTransactionTable'])->count(),
        );

        // API の失敗レスポンスが消費されていることを確認する。
        $this->assertSame(0, $mockHandler->count());
    }
}
