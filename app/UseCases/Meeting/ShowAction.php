<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;

final class ShowAction
{
    /**
     * 面談詳細の表示に必要なリレーションを読み込む。
     */
    public function __invoke(Meeting $meeting): Meeting
    {
        return $meeting->loadMissing([
            'enrollment.certification',
            'coach',
            'student',
            'canceledBy',
            'meetingMemo',
        ]);
    }
}
