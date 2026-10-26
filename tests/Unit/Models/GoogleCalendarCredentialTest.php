<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GoogleCalendarCredential モデルのリレーション・Cast を検証する Unit テスト。
 *
 * - user リレーション
 * - expires_at / connected_at の datetime cast
 *
 * Google Calendar の連携情報を保持するモデル。
 */
class GoogleCalendarCredentialTest extends TestCase
{
    use RefreshDatabase;

    /**
     * user リレーションが連携情報の所有者を返すことを確認する。
     */
    public function test_user_relation_returns_owner_user(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $credential = GoogleCalendarCredential::factory()
            ->for($user)
            ->create();

        // Act
        $owner = $credential->user;

        // Assert
        $this->assertTrue($owner->is($user));
    }

    /**
     * expires_at と connected_at が Carbon にキャストされることを確認する。
     */
    public function test_expires_at_and_connected_at_casts(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $credential = GoogleCalendarCredential::factory()
            ->for($user)
            ->create([
                'expires_at' => '2026-06-01 12:00:00',
                'connected_at' => '2026-06-01 10:00:00',
            ]);

        // Act
        $fresh = $credential->fresh();

        // Assert
        $this->assertInstanceOf(Carbon::class, $fresh->expires_at);
        $this->assertInstanceOf(Carbon::class, $fresh->connected_at);
    }

    /**
     * expires_at と connected_at が null を許容することを確認する。
     */
    public function test_expires_at_and_connected_at_can_be_null(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $credential = GoogleCalendarCredential::factory()
            ->for($user)
            ->create([
                'expires_at' => null,
                'connected_at' => null,
            ]);

        // Act
        $fresh = $credential->fresh();

        // Assert
        $this->assertNull($fresh->expires_at);
        $this->assertNull($fresh->connected_at);
    }
}
