<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\UseCases\Notification\FetchNotificationsAction;
use App\UseCases\Notification\MarkAllAsReadAction;
use App\UseCases\Notification\MarkAsReadAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 通知ポップオーバー用の JSON API Controller。
 */
final class NotificationController extends Controller
{
    /**
     * ログインユーザーの通知一覧と未読件数を返す。
     */
    public function index(Request $request, FetchNotificationsAction $action): JsonResponse
    {
        return response()->json(
            $action($request->user()),
        );
    }

    /**
     * 指定された通知を既読にする。
     */
    public function markAsRead(
        Request $request,
        string $notification,
        MarkAsReadAction $action,
    ): JsonResponse {
        $unreadCount = $action(
            $request->user(),
            $notification,
        );

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * ログインユーザーの未読通知をすべて既読にする。
     */
    public function markAllAsRead(Request $request, MarkAllAsReadAction $action): JsonResponse
    {
        $unreadCount = $action($request->user());

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
        ]);
    }
}
