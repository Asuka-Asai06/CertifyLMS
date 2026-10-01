<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * admin 用の面談パック一覧をフィルタ付きで取得するユースケース。
 *
 * パック名のキーワードと公開状態で絞り込み、
 * 一覧画面用にページネーションして取得する。
 */
final class IndexAction
{
    public function __invoke(
        ?string $keyword,
        ?MeetingPackStatus $status,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = MeetingPack::query();

        if ($keyword !== null && $keyword !== '') {
            $query->where('name', 'like', '%'.$keyword.'%');
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return $query
            ->ordered()
            ->paginate($perPage)
            ->withQueryString();
    }
}
