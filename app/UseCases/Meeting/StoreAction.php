<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\Services\CoachMeetingLoadService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class StoreAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly CoachMeetingLoadService $coachLoadService,
        private readonly MeetingQuotaService $quotaService,
        private readonly ConsumeQuotaAction $consumeAction,
    ) {}

    /**
     * 面談を予約し、面談回数を消費する。
     *
     * 残面談回数と空き枠を検証し、予約可能な担当コーチから
     * 過去実績が最少のコーチを選定して予約を作成する。
     *
     * @param array{scheduled_at: string, topic: string} $data
     */
    public function __invoke(Enrollment $enrollment, array $data): Meeting
    {
        $scheduledAt = Carbon::parse($data['scheduled_at']);
        $topic = $data['topic'];
        $student = $enrollment->user;

        return DB::transaction(function () use (
            $enrollment,
            $student,
            $scheduledAt,
            $topic,
        ): Meeting {
            if ($this->quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $certification = $enrollment->certification;

            $this->availabilityService->validateSlot(
                $certification,
                $scheduledAt,
            );

            $candidates = $this->availableCoaches(
                $certification,
                $scheduledAt,
            );

            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $coach = $this->coachLoadService->leastLoadedCoach($candidates);

            try {
                $meeting = Meeting::create([
                    'enrollment_id' => $enrollment->id,
                    'coach_id' => $coach->id,
                    'student_id' => $student->id,
                    'scheduled_at' => $scheduledAt,
                    'status' => MeetingStatus::Reserved->value,
                    'topic' => $topic,
                    'meeting_url_snapshot' => $coach->meeting_url,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // 同時予約による UNIQUE 制約違反を、予約可能なコーチがいない状態として扱う。
                throw new MeetingNoAvailableCoachException($e);
            }

            $transaction = ($this->consumeAction)($student, $meeting->id);

            $meeting->update([
                'meeting_quota_transaction_id' => $transaction->id,
            ]);

            $meeting = $meeting->fresh()->load('coach');

            DB::afterCommit(function () use ($meeting): void {
                $meeting->coach->notify(
                    new MeetingReservedNotification($meeting),
                );
            });

            return $meeting;
        });
    }

    /**
     * 指定された資格の担当コーチから、予約可能なコーチを取得する。
     *
     * 空き時間枠が有効で、同じ日時に reserved / completed の面談が
     * 存在しないコーチのみを返す。
     *
     * @return Collection<int, User>
     */
    private function availableCoaches(
        Certification $certification,
        Carbon $scheduledAt,
    ): Collection {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
            ->whereHas('coachAvailabilities', function ($query) use ($scheduledAt, $time): void {
                $query->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($query) use ($scheduledAt): void {
                $query->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [
                        MeetingStatus::Reserved->value,
                        MeetingStatus::Completed->value,
                    ]);
            })
            ->get();
    }
}
