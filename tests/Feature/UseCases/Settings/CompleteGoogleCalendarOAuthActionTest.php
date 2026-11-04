<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Settings;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\UseCases\Settings\CompleteGoogleCalendarOAuthAction;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * CompleteGoogleCalendarOAuthAction の責務:
 *
 * - Google OAuth の state を検証
 * - 認証コードを使用してアクセストークンを取得
 * - Google Calendar の連携情報を新規保存
 * - 既存の連携情報がある場合は更新
 * - Google が refresh_token を返さない場合は既存の値を維持
 * - 認証情報が不正な場合は例外を送出
 *
 * @group external-api
 */
class CompleteGoogleCalendarOAuthActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guzzle MockHandler を注入した Action を生成する。
     */
    private function createAction(MockHandler $mockHandler): CompleteGoogleCalendarOAuthAction
    {
        $httpClient = new GuzzleClient([
            'handler' => HandlerStack::create($mockHandler),
        ]);

        $googleCalendarService = new GoogleCalendarService($httpClient);

        return new CompleteGoogleCalendarOAuthAction(
            $googleCalendarService,
        );
    }

    /**
     * Google OAuth のトークン取得成功レスポンスを生成する。
     *
     * @param array<string, mixed> $token
     */
    private function createTokenResponse(array $token): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($token, JSON_THROW_ON_ERROR),
        );
    }

    public function test_stores_google_calendar_credential(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $mockHandler = new MockHandler([
            $this->createTokenResponse([
                'access_token' => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $action = $this->createAction($mockHandler);

        // Act
        $action(
            $user,
            'valid-state',
            'valid-state',
            'test-authorization-code',
        );

        // Assert
        $credential = $user->googleCredential()->first();

        $this->assertNotNull($credential);
        $this->assertSame('test-access-token', $credential->access_token);
        $this->assertSame('test-refresh-token', $credential->refresh_token);
        $this->assertSame('primary', $credential->calendar_id);
        $this->assertNotNull($credential->expires_at);
        $this->assertNotNull($credential->connected_at);

        $this->assertDatabaseHas('google_calendar_credentials', [
            'user_id' => $user->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'calendar_id' => 'primary',
        ]);

        $this->assertSame(0, $mockHandler->count());
    }

    public function test_updates_existing_google_calendar_credential(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        GoogleCalendarCredential::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
        ]);

        $mockHandler = new MockHandler([
            $this->createTokenResponse([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $action = $this->createAction($mockHandler);

        // Act
        $action(
            $user,
            'valid-state',
            'valid-state',
            'test-authorization-code',
        );

        // Assert
        $credential = $user->googleCredential()->first();

        $this->assertNotNull($credential);
        $this->assertSame('new-access-token', $credential->access_token);
        $this->assertSame('new-refresh-token', $credential->refresh_token);

        $this->assertDatabaseCount('google_calendar_credentials', 1);

        $this->assertDatabaseHas('google_calendar_credentials', [
            'user_id' => $user->id,
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
        ]);

        $this->assertSame(0, $mockHandler->count());
    }

    public function test_preserves_existing_refresh_token_when_google_does_not_return_one(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        GoogleCalendarCredential::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'old-access-token',
            'refresh_token' => 'existing-refresh-token',
        ]);

        $mockHandler = new MockHandler([
            $this->createTokenResponse([
                'access_token' => 'new-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $action = $this->createAction($mockHandler);

        // Act
        $action(
            $user,
            'valid-state',
            'valid-state',
            'test-authorization-code',
        );

        // Assert
        $credential = $user->googleCredential()->first();

        $this->assertNotNull($credential);
        $this->assertSame('new-access-token', $credential->access_token);
        $this->assertSame(
            'existing-refresh-token',
            $credential->refresh_token,
        );

        $this->assertDatabaseCount('google_calendar_credentials', 1);
        $this->assertSame(0, $mockHandler->count());
    }

    public function test_throws_when_oauth_state_does_not_match(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $mockHandler = new MockHandler;
        $action = $this->createAction($mockHandler);

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Google OAuth の state 検証に失敗しました。',
        );

        // Act
        $action(
            $user,
            'expected-state',
            'different-state',
            'test-authorization-code',
        );
    }

    public function test_throws_when_authorization_code_is_empty(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $mockHandler = new MockHandler;
        $action = $this->createAction($mockHandler);

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Google OAuth の認証コードがありません。',
        );

        // Act
        $action(
            $user,
            'valid-state',
            'valid-state',
            '',
        );
    }

    public function test_throws_when_google_token_request_fails(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $mockHandler = new MockHandler([
            new Response(
                400,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'error' => 'invalid_grant',
                    'error_description' => 'Invalid authorization code',
                ], JSON_THROW_ON_ERROR),
            ),
        ]);

        $action = $this->createAction($mockHandler);

        // Assert
        $this->expectException(RuntimeException::class);

        // Act
        $action(
            $user,
            'valid-state',
            'valid-state',
            'invalid-authorization-code',
        );
    }
}
