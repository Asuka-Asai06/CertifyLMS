<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;

class MarkAsReadAction
{
    /**
     * 指定された通知を既読にする。
     *
     * 対象ユーザー自身の通知のみ操作できる。
     *
     * @param User $user 対象ユーザー
     * @param string $notificationId 通知 ID
     *
     * @return int 既読処理後の未読件数
     */
    public function __invoke(User $user, string $notificationId): int
    {
        $notification = $user->notifications()
            ->where('id', $notificationId)
            ->firstOrFail();

        $notification->markAsRead();

        return $user->unreadNotifications()->count();
    }
}
