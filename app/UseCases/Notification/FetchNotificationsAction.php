<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class FetchNotificationsAction
{
    /**
     * ログインユーザーの通知一覧と未読件数を取得する。
     *
     * @param User $user 対象ユーザー
     *
     * @return array{
     *     data: list<array{
     *         id: string,
     *         title: string,
     *         message: string,
     *         preview: string,
     *         url: string,
     *         is_read: bool,
     *         created_at: string|null
     *     }>,
     *     unread_count: int
     * }
     */
    public function __invoke(User $user): array
    {
        $notifications = $user->notifications()
            ->latest()
            ->get()
            ->map(static function (DatabaseNotification $notification): array {
                $data = $notification->data;

                return [
                    'id' => $notification->id,
                    'title' => (string) ($data['title'] ?? '通知'),
                    'message' => (string) ($data['message'] ?? ''),
                    'preview' => (string) (
                        $data['body_preview']
                        ?? $data['body']
                        ?? $data['message']
                        ?? ''
                    ),
                    'url' => (string) (
                        $data['url']
                        ?? route('notifications.index')
                    ),
                    'is_read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at?->toISOString(),
                ];
            })
            ->values()
            ->all();

        return [
            'data' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ];
    }
}
