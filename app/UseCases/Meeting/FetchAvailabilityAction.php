<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Enrollment;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FetchAvailabilityAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
    ) {}

    /**
     * 受講登録に紐づく資格について、指定日の面談空き枠を取得する。
     *
     * @param Enrollment $enrollment 対象の受講登録
     * @param Carbon $date 空き枠を取得する日付
     *
     * @return Collection<int, array{
     *     slot_start: Carbon,
     *     slot_end: Carbon,
     *     available_coach_count: int
     * }>
     */
    public function __invoke(
        Enrollment $enrollment,
        Carbon $date,
    ): Collection {
        $enrollment->loadMissing('certification');

        return $this->availabilityService->slotsForCertification(
            $enrollment->certification,
            $date,
        );
    }
}
