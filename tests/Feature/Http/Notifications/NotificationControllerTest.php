<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NotificationController の HTTP 統合テスト。
 *
 * 通知一覧の表示・未読絞り込み・ページネーション・
 * 既読処理・全件既読・他ユーザー通知へのアクセス制御を確認する。
 */
class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_displays_own_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $ownNotification = $this->createNotification($user);
        $otherUser = User::factory()->student()->inProgress()->create();
        $otherNotification = $this->createNotification($otherUser);

        $response = $this->actingAs($user)
            ->get(route('notifications.index'));

        $response->assertOk();
        $response->assertViewIs('notifications.index');
        $response->assertViewHas('notifications', function ($notifications) use (
            $ownNotification,
            $otherNotification,
        ): bool {
            $ids = $notifications->pluck('id');

            return $ids->contains($ownNotification->id)
                && ! $ids->contains($otherNotification->id);
        });
    }

    public function test_index_displays_unread_notifications_only(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $unreadNotification = $this->createNotification($user);
        $readNotification = $this->createNotification(
            $user,
            read: true,
        );

        $response = $this->actingAs($user)
            ->get(route('notifications.index', [
                'tab' => 'unread',
            ]));

        $response->assertOk();
        $response->assertViewIs('notifications.index');
        $response->assertViewHas('tab', 'unread');
        $response->assertViewHas('notifications', function ($notifications) use (
            $unreadNotification,
            $readNotification,
        ): bool {
            $ids = $notifications->pluck('id');

            return $ids->contains($unreadNotification->id)
                && ! $ids->contains($readNotification->id);
        });
    }

    public function test_index_defaults_to_all_when_tab_is_invalid(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($user)
            ->get(route('notifications.index', [
                'tab' => 'invalid',
            ]));

        $response->assertOk();
        $response->assertViewHas('tab', 'all');
    }

    public function test_index_passes_unread_count_to_view(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $this->createNotification($user);
        $this->createNotification($user);
        $this->createNotification($user, read: true);

        $response = $this->actingAs($user)
            ->get(route('notifications.index'));

        $response->assertOk();
        $response->assertViewHas('unreadCount', 2);
    }

    public function test_index_paginates_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        for ($index = 0; $index < 21; $index++) {
            $this->createNotification($user);
        }

        $response = $this->actingAs($user)
            ->get(route('notifications.index'));

        $response->assertOk();
        $response->assertViewHas('notifications', function ($notifications): bool {
            return $notifications->perPage() === 20
                && $notifications->total() === 21
                && $notifications->currentPage() === 1;
        });
    }

    public function test_index_displays_second_page_of_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        for ($index = 0; $index < 21; $index++) {
            $this->createNotification($user);
        }

        $response = $this->actingAs($user)
            ->get(route('notifications.index', [
                'page' => 2,
            ]));

        $response->assertOk();
        $response->assertViewHas('notifications', function ($notifications): bool {
            return $notifications->currentPage() === 2
                && $notifications->total() === 21
                && $notifications->count() === 1;
        });
    }

    public function test_mark_as_read_marks_own_notification_as_read(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $notification = $this->createNotification($user);

        $response = $this->actingAs($user)
            ->post(route('notifications.markAsRead', $notification));

        $response->assertRedirect();

        $this->assertNotNull(
            $notification->fresh()->read_at,
        );
    }

    public function test_mark_as_read_redirects_to_notification_url(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $url = route('notifications.index');

        $notification = $this->createNotification(
            $user,
            data: [
                'notification_type' => 'chat_message_received',
                'title' => '新しいチャットメッセージ',
                'message' => 'メッセージが届きました。',
                'url' => $url,
            ],
        );

        $response = $this->actingAs($user)
            ->post(route('notifications.markAsRead', $notification));

        $response->assertRedirect($url);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_as_read_redirects_to_notification_index_when_url_is_missing(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $notification = $this->createNotification(
            $user,
            data: [
                'notification_type' => 'chat_message_received',
                'title' => '新しいチャットメッセージ',
                'message' => 'メッセージが届きました。',
            ],
        );

        $response = $this->actingAs($user)
            ->post(route('notifications.markAsRead', $notification));

        $response->assertRedirect(route('notifications.index'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_as_read_cannot_mark_other_users_notification_as_read(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $otherUser = User::factory()->student()->inProgress()->create();

        $notification = $this->createNotification($otherUser);

        $response = $this->actingAs($user)
            ->post(route('notifications.markAsRead', $notification));

        $response->assertNotFound();

        $this->assertNull(
            $notification->fresh()->read_at,
        );
    }

    public function test_mark_all_as_read_marks_own_unread_notifications_as_read(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $unreadNotification1 = $this->createNotification($user);
        $unreadNotification2 = $this->createNotification($user);
        $readNotification = $this->createNotification(
            $user,
            read: true,
        );

        $response = $this->actingAs($user)
            ->post(route('notifications.markAllAsRead'));

        $response->assertRedirect(route('notifications.index'));
        $response->assertSessionHas(
            'success',
            'すべての通知を既読にしました。',
        );

        $this->assertNotNull(
            $unreadNotification1->fresh()->read_at,
        );
        $this->assertNotNull(
            $unreadNotification2->fresh()->read_at,
        );
        $this->assertNotNull(
            $readNotification->fresh()->read_at,
        );
    }

    public function test_mark_all_as_read_does_not_affect_other_users_notifications(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $otherUser = User::factory()->student()->inProgress()->create();

        $ownNotification = $this->createNotification($user);
        $otherNotification = $this->createNotification($otherUser);

        $response = $this->actingAs($user)
            ->post(route('notifications.markAllAsRead'));

        $response->assertRedirect(route('notifications.index'));

        $this->assertNotNull(
            $ownNotification->fresh()->read_at,
        );
        $this->assertNull(
            $otherNotification->fresh()->read_at,
        );
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get(route('notifications.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_cannot_mark_notification_as_read(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $notification = $this->createNotification($user);

        $response = $this->post(
            route('notifications.markAsRead', $notification),
        );

        $response->assertRedirect(route('login'));

        $this->assertNull(
            $notification->fresh()->read_at,
        );
    }

    public function test_unauthenticated_user_cannot_mark_all_notifications_as_read(): void
    {
        $response = $this->post(
            route('notifications.markAllAsRead'),
        );

        $response->assertRedirect(route('login'));
    }

    /**
     * テスト用のデータベース通知を作成する。
     *
     * @param array<string, mixed>|null $data
     */
    private function createNotification(
        User $user,
        bool $read = false,
        ?array $data = null,
    ): DatabaseNotification {
        $createdAt = now();

        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test-notification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => $data ?? [
                'notification_type' => 'chat_message_received',
                'title' => 'テスト通知',
                'message' => 'テスト通知です。',
                'url' => route('notifications.index'),
            ],
            'read_at' => $read ? $createdAt : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
