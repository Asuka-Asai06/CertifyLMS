<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 未認証の場合、通知一覧 API が 401 を返すことを確認する。
     */
    public function test_unauthenticated_user_cannot_fetch_notifications(): void
    {
        $response = $this->getJson('/api/v1/notifications');

        $response->assertUnauthorized();
    }

    /**
     * ログインユーザー自身の通知だけが返されることを確認する。
     */
    public function test_only_authenticated_users_notifications_are_returned(): void
    {
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $otherUser = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $this->createNotification($user, [
            'title' => '自分の通知',
            'message' => '自分宛てのメッセージ',
            'body_preview' => '通知本文',
            'url' => '/notifications',
        ]);

        $this->createNotification($otherUser, [
            'title' => '他人の通知',
            'message' => '他人宛てのメッセージ',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/notifications');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', '自分の通知')
            ->assertJsonPath('data.0.message', '自分宛てのメッセージ')
            ->assertJsonPath('data.0.preview', '通知本文')
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('unread_count', 1);
    }

    /**
     * 未認証の場合、通知の既読 API が 401 を返すことを確認する。
     */
    public function test_unauthenticated_user_cannot_mark_notification_as_read(): void
    {
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $notification = $this->createNotification($user, [
            'title' => 'テスト通知',
        ]);

        $response = $this->postJson(
            "/api/v1/notifications/{$notification->id}/read",
        );

        $response->assertUnauthorized();

        $this->assertNull($notification->fresh()->read_at);
    }

    /**
     * 本人の通知を既読にできることを確認する。
     */
    public function test_user_can_mark_own_notification_as_read(): void
    {
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $notification = $this->createNotification($user, [
            'title' => 'テスト通知',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/notifications/{$notification->id}/read");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /**
     * 他人の通知は既読にできず、404 が返ることを確認する。
     */
    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $otherUser = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $notification = $this->createNotification($otherUser, [
            'title' => '他人の通知',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    /**
     * 未認証の場合、一括既読 API が 401 を返すことを確認する。
     */
    public function test_unauthenticated_user_cannot_mark_all_notifications_as_read(): void
    {
        $response = $this->postJson('/api/v1/notifications/read-all');

        $response->assertUnauthorized();
    }

    /**
     * 本人の未読通知だけが一括既読になることを確認する。
     */
    public function test_user_can_mark_all_own_unread_notifications_as_read(): void
    {
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $otherUser = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $unreadNotification = $this->createNotification($user, [
            'title' => '未読通知',
        ]);

        $readNotification = $this->createNotification($user, [
            'title' => '既読通知',
        ]);

        $readNotification->markAsRead();

        $otherUsersNotification = $this->createNotification($otherUser, [
            'title' => '他人の未読通知',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/notifications/read-all');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($unreadNotification->fresh()->read_at);
        $this->assertNotNull($readNotification->fresh()->read_at);
        $this->assertNull($otherUsersNotification->fresh()->read_at);
    }

    /**
     * テスト用のデータベース通知を作成する。
     *
     * @param array<string, mixed> $data 通知データ
     */
    private function createNotification(
        User $user,
        array $data,
    ): DatabaseNotification {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test_notification',
            'data' => $data,
        ]);
    }
}
