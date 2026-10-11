<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 通知一覧を表示する Controller。受講生 / コーチ / admin 共通で利用される。
 */
final class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $tab = $request->string('tab')->toString();

        if (! in_array($tab, ['all', 'unread'], true)) {
            $tab = 'all';
        }

        $query = $user->notifications()->latest();

        if ($tab === 'unread') {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate(20)->withQueryString();

        $unreadCount = $user
            ->unreadNotifications()
            ->count();

        return view('notifications.index', compact(
            'notifications',
            'unreadCount',
            'tab',
        ));
    }

    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();

        $notification = $user
            ->notifications()
            ->where('id', $notification)
            ->firstOrFail();

        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        if ($url !== null) {
            return redirect()->to($url);
        }

        return redirect()
            ->route('notifications.index');
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $request->user()
            ->unreadNotifications
            ->markAsRead();

        return redirect()
            ->route('notifications.index')
            ->with('success', 'すべての通知を既読にしました。');
    }
}
