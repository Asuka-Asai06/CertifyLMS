<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MeetingPack;
use App\Models\User;

/**
 * 面談パック管理の認可ルール。admin のみ管理操作を許可する。
 *
 * 面談パックは管理者向けマスタのため、coach / student は
 * 一覧・詳細を含む管理画面へのアクセスを許可しない。
 */
class MeetingPackPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function view(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function update(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function delete(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function publish(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function archive(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function unarchive(User $auth, MeetingPack $meetingPack): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
