<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 管理者向けのお知らせ配信履歴を取得するユースケース。
 * 配信日時の降順で並べ、対象資格・対象ユーザー・配信者をあらかじめ取得して
 * 一覧表示時の追加クエリを抑え、20 件ずつページネーションする。
 */
final class IndexAction
{
    public function __invoke(int $perPage = 20): LengthAwarePaginator
    {
        return Announcement::query()
            ->with(['targetCertification', 'targetUser', 'createdBy'])
            ->orderByDesc('dispatched_at')
            ->paginate($perPage);
    }
}
