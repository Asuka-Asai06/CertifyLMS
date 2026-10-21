<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use Illuminate\Support\Facades\DB;

class UpsertMemoAction
{
    /**
     * 面談メモを作成または更新する。
     *
     * Reserved または Completed の面談のみ保存できる。
     *
     * @param Meeting $meeting 対象の面談
     * @param string $body メモ本文
     */
    public function __invoke(Meeting $meeting, string $body): void
    {
        DB::transaction(function () use ($meeting, $body): void {
            if (! in_array(
                $meeting->status,
                [MeetingStatus::Reserved, MeetingStatus::Completed],
                true,
            )) {
                throw MeetingStatusTransitionException::forMemo();
            }

            MeetingMemo::updateOrCreate(
                ['meeting_id' => $meeting->id],
                ['body' => $body],
            );
        });
    }
}
