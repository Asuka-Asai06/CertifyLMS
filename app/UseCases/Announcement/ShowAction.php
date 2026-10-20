<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;

/**
 * admin 用のお知らせ配信履歴詳細を取得するユースケース。
 *
 * 対象資格・対象ユーザー・配信者を Eager Loading し、
 * 詳細画面に必要な配信内容とメタデータを揃える。
 */
final class ShowAction
{
    public function __invoke(Announcement $announcement): Announcement
    {
        return $announcement->load([
            'targetCertification',
            'targetUser',
            'createdBy',
        ]);
    }
}
