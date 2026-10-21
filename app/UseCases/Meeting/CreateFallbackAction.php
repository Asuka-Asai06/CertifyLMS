<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class CreateFallbackAction
{
    /**
     * 予約画面のフォールバック表示に必要な受講登録を取得する。
     *
     * 未認証の場合は空の Collection を返す。
     *
     * @return Collection<int, Enrollment>
     */
    public function __invoke(?User $user): Collection
    {
        if ($user === null) {
            return new Collection;
        }

        return $user->enrollments()
            ->whereIn('status', [
                EnrollmentStatus::Learning->value,
                EnrollmentStatus::Passed->value,
            ])
            ->with('certification')
            ->get();
    }
}
