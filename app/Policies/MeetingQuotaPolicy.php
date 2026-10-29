<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * 受講生本人の面談回数履歴閲覧に関する認可。
 */
class MeetingQuotaPolicy
{
    /**
     * 面談回数履歴の閲覧。本人のみ可。
     */
    public function viewHistory(User $auth, User $target): bool
    {
        return $auth->id === $target->id;
    }

    /**
     * 追加面談パックの購入。受講中の受講生のみ可。
     */
    public function purchasePack(User $user): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress;
    }
}
