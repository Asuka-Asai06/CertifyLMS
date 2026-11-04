<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Services\GoogleCalendarService;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CancelAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundAction,
        private readonly GoogleCalendarService $googleCalendarService,
    ) {}

    /**
     * 面談をキャンセルし、消費済みの面談回数を返却する。
     * Google Calendar イベントが紐づいている場合は、LMS 側のキャンセル確定後にイベントを削除する。
     *
     * @throws MeetingStatusTransitionException
     * @throws MeetingAlreadyStartedException
     */
    public function __invoke(Meeting $meeting, User $actor): void
    {
        $canceledMeeting = DB::transaction(function () use (
            $meeting,
            $actor,
        ): Meeting {
            $locked = Meeting::query()
                ->whereKey($meeting->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            ($this->refundAction)($locked->student, $locked->id);

            return $locked->fresh()->load([
                'coach.googleCredential',
                'student',
            ]);
        });

        $credential = $canceledMeeting->coach->googleCredential;
        $eventId = $canceledMeeting->google_calendar_event_id;

        if ($credential !== null && is_string($eventId) && $eventId !== '') {
            try {
                $this->googleCalendarService->deleteEvent(
                    $credential,
                    $eventId,
                );
            } catch (Throwable $exception) {
                Log::warning(
                    'Google Calendar event deletion failed during meeting cancellation.',
                    [
                        'meeting_id' => $canceledMeeting->id,
                        'coach_id' => $canceledMeeting->coach_id,
                        'exception' => $exception::class,
                    ],
                );
            }
        }

        $recipient = $actor->id === $canceledMeeting->student_id
            ? $canceledMeeting->coach
            : $canceledMeeting->student;

        if ($recipient->role === UserRole::Coach) {
            $recipient->notify(
                new MeetingCanceledNotification($canceledMeeting),
            );
        } elseif (
            $recipient->role === UserRole::Student
            && $recipient->status === UserStatus::InProgress
        ) {
            $recipient->notify(
                new MeetingCanceledNotification($canceledMeeting),
            );
        }
    }
}
