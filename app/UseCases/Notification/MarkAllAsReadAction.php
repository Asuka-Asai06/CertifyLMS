<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;

class MarkAllAsReadAction
{
    /**
     * 対象ユーザーの未読通知をすべて既読にする。
     *
     * @param User $user 対象ユーザー
     *
     * @return int 既読処理後の未読件数
     */
    public function __invoke(User $user): int
    {
        $user->unreadNotifications()->update([
            'read_at' => now(),
        ]);

        return $user->unreadNotifications()->count();
    }
}
