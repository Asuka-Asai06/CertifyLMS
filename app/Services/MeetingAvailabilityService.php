<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Meeting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * 担当コーチ集合の面談可能時間枠を 60 分単位で展開し、空きスロットを集計する Service。
 *
 * 受講生の予約画面が「該当資格の担当コーチ全員の有効枠 Union」を 1 日単位で取得し、
 * 既存予約済時刻 を除外して各スロットの「予約可能なコーチ数」を返す。受講生にコーチ個別は提示せず、
 * 予約確定時にコーチを自動割当する。
 */
final class MeetingAvailabilityService
{
    public function __construct(
        private readonly GoogleCalendarService $googleCalendarService,
    ) {}

    /**
     * 指定日の担当コーチごとに Google Calendar の予定あり時間を取得する。
     * Google Calendar 未連携のコーチは対象外とする。
     * 取得に失敗したコーチは、従来どおり予約候補から除外しない。
     *
     * @return array<string, list<array{start: CarbonImmutable, end: CarbonImmutable}>>
     */
    public function fetchBusyPeriodsByCoach(
        Certification $certification,
        Carbon $date,
    ): array {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        $coaches = $certification->coaches()
            ->with('googleCredential')
            ->get();

        $busyPeriodsByCoach = [];

        foreach ($coaches as $coach) {
            $credential = $coach->googleCredential;

            if ($credential === null) {
                continue;
            }

            try {
                $busyPeriodsByCoach[(string) $coach->id] =
                    $this->googleCalendarService->fetchBusyPeriods(
                        $credential,
                        $dayStart,
                        $dayEnd,
                    );
            } catch (Throwable) {
                // API エラー時は予定なしとして扱い、予約処理を継続する。
                $busyPeriodsByCoach[(string) $coach->id] = [];
            }
        }

        return $busyPeriodsByCoach;
    }

    /**
     * 指定日の予約可能時間枠を集計する。
     * 予定情報が渡された場合は再取得せず、その情報を使用する。
     *
     * @param array<string, list<array{start: CarbonImmutable, end: CarbonImmutable}>>|null $busyPeriodsByCoach
     *
     * @return Collection<int, array{
     *     slot_start: Carbon,
     *     slot_end: Carbon,
     *     available_coach_count: int
     * }>
     */
    public function slotsForCertification(
        Certification $certification,
        Carbon $date,
        ?array $busyPeriodsByCoach = null,
    ): Collection {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();
        $dayOfWeek = $date->dayOfWeek;

        $busyPeriodsByCoach ??= $this->fetchBusyPeriodsByCoach(
            $certification,
            $date,
        );

        $coaches = $certification->coaches()->get();

        if ($coaches->isEmpty()) {
            return collect();
        }

        $coachIds = $coaches->pluck('id')->all();

        $availabilities = CoachAvailability::query()
            ->whereIn('coach_id', $coachIds)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get();

        $existingMeetings = Meeting::query()
            ->whereIn('coach_id', $coachIds)
            ->whereBetween('scheduled_at', [$dayStart, $dayEnd])
            ->whereIn('status', [
                MeetingStatus::Reserved->value,
                MeetingStatus::Completed->value,
            ])
            ->get(['coach_id', 'scheduled_at']);

        $bookedByCoach = $existingMeetings
            ->groupBy('coach_id')
            ->map(
                fn ($rows) => $rows->map(
                    fn (Meeting $meeting) => $meeting->scheduled_at->format('H:i'),
                )->all(),
            );

        $slotCounts = [];

        foreach ($availabilities as $availability) {
            $slot = Carbon::parse(
                $date->format('Y-m-d').' '.$availability->start_time,
            );
            $end = Carbon::parse(
                $date->format('Y-m-d').' '.$availability->end_time,
            );

            while ($slot->copy()->addHour() <= $end) {
                $slotStart = $slot->copy();
                $slotEnd = $slotStart->copy()->addHour();
                $slotKey = $slotStart->format('H:i');
                $coachId = (string) $availability->coach_id;

                $booked = $bookedByCoach[$availability->coach_id] ?? [];

                if (in_array($slotKey, $booked, true)) {
                    $slot->addHour();

                    continue;
                }

                $busyPeriods = $busyPeriodsByCoach[$coachId] ?? [];

                $overlapsGoogleEvent = collect($busyPeriods)->contains(
                    fn (array $busy): bool => $busy['start']->lt($slotEnd)
                        && $busy['end']->gt($slotStart),
                );

                if (! $overlapsGoogleEvent) {
                    $slotCounts[$slotKey] = ($slotCounts[$slotKey] ?? 0) + 1;
                }

                $slot->addHour();
            }
        }

        ksort($slotCounts);

        return collect($slotCounts)
            ->map(function (int $count, string $time) use ($date): array {
                $start = Carbon::parse($date->format('Y-m-d').' '.$time);

                return [
                    'slot_start' => $start,
                    'slot_end' => $start->copy()->addHour(),
                    'available_coach_count' => $count,
                ];
            })
            ->values();
    }

    /**
     * 指定時刻に予約可能なコーチがいることを検証する。
     *
     * 予定情報が渡された場合は再取得せず、その情報を使用する。
     *
     * @param array<string, list<array{start: CarbonImmutable, end: CarbonImmutable}>>|null $busyPeriodsByCoach
     */
    public function validateSlot(
        Certification $certification,
        Carbon $scheduledAt,
        ?array $busyPeriodsByCoach = null,
    ): void {
        $slots = $this->slotsForCertification(
            $certification,
            $scheduledAt->copy()->startOfDay(),
            $busyPeriodsByCoach,
        );

        $matched = $slots->contains(
            fn (array $slot): bool => $slot['slot_start']->equalTo($scheduledAt)
                && $slot['available_coach_count'] > 0,
        );

        if (! $matched) {
            throw new MeetingOutOfAvailabilityException;
        }
    }
}
