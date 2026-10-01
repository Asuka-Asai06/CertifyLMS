<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;

/**
 * admin 用の面談パック詳細を取得するユースケース。
 *
 * 作成者・更新者を Eager Loading し、
 * 詳細画面に必要な基本情報とメタデータを揃える。
 */
final class ShowAction
{
    public function __invoke(MeetingPack $plan): MeetingPack
    {
        return $plan->load([
            'createdBy',
            'updatedBy',
        ]);
    }
}
