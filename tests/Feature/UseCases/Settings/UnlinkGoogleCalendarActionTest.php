<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Settings;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\UseCases\Settings\UnlinkGoogleCalendarAction;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * UnlinkGoogleCalendarAction の責務:
 *
 * - Google 側のトークン失効を試みる
 * - refresh_token がある場合は優先して失効する
 * - refresh_token がない場合は access_token を使用する
 * - Google 側の失効に失敗しても LMS 側の連携情報を削除する
 * - 連携情報が存在しない場合は API 通信せず正常終了する
 *
 * @group external-api
 */
class UnlinkGoogleCalendarActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guzzle MockHandler を注入した Action を生成する。
     *
     * @param array<int, array<string, mixed>> $history
     */
    private function createAction(MockHandler $mockHandler, array &$history): UnlinkGoogleCalendarAction
    {
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history));

        $httpClient = new GuzzleClient([
            'handler' => $handlerStack,
        ]);

        $googleCalendarService = new GoogleCalendarService($httpClient);

        return new UnlinkGoogleCalendarAction($googleCalendarService);
    }

    public function test_unlinks_google_calendar_and_deletes_credential(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $credential = GoogleCalendarCredential::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
        ]);

        $mockHandler = new MockHandler([
            new Response(200),
        ]);

        $history = [];
        $action = $this->createAction($mockHandler, $history);

        // Act
        $result = $action($user);

        // Assert
        $this->assertTrue($result);

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'id' => $credential->id,
        ]);

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'user_id' => $user->id,
        ]);

        $this->assertCount(1, $history);

        parse_str(
            (string) $history[0]['request']->getBody(),
            $requestBody,
        );

        // refresh_token が優先して失効対象になる。
        $this->assertSame(
            'test-refresh-token',
            $requestBody['token'] ?? null,
        );

        $this->assertSame(0, $mockHandler->count());
    }

    public function test_uses_access_token_when_refresh_token_does_not_exist(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $credential = GoogleCalendarCredential::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'test-access-token',
            'refresh_token' => null,
        ]);

        $mockHandler = new MockHandler([
            new Response(200),
        ]);

        $history = [];
        $action = $this->createAction($mockHandler, $history);

        // Act
        $result = $action($user);

        // Assert
        $this->assertTrue($result);

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'id' => $credential->id,
        ]);

        $this->assertCount(1, $history);

        parse_str(
            (string) $history[0]['request']->getBody(),
            $requestBody,
        );

        // refresh_token がない場合は access_token を使用する。
        $this->assertSame(
            'test-access-token',
            $requestBody['token'] ?? null,
        );

        $this->assertSame(0, $mockHandler->count());
    }

    public function test_deletes_credential_when_google_token_revocation_fails(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $credential = GoogleCalendarCredential::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
        ]);

        $mockHandler = new MockHandler([
            new Response(
                400,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'error' => 'invalid_token',
                    'error_description' => 'Invalid token',
                ], JSON_THROW_ON_ERROR),
            ),
        ]);

        $history = [];
        $action = $this->createAction($mockHandler, $history);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($user): bool {
                return $message === 'Google Calendar token revocation failed.'
                    && $context['user_id'] === $user->id
                    && isset($context['exception']);
            });

        // Act
        $result = $action($user);

        // Assert
        $this->assertFalse($result);

        // Google 側の失効に失敗しても LMS 側の連携情報は削除する。
        $this->assertDatabaseMissing('google_calendar_credentials', [
            'id' => $credential->id,
        ]);

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'user_id' => $user->id,
        ]);

        $this->assertCount(1, $history);
        $this->assertSame(0, $mockHandler->count());
    }

    public function test_returns_true_when_google_calendar_credential_does_not_exist(): void
    {
        // Arrange
        $user = User::factory()->coach()->create();

        $mockHandler = new MockHandler;
        $history = [];

        $action = $this->createAction($mockHandler, $history);

        // Act
        $result = $action($user);

        // Assert
        $this->assertTrue($result);

        // 連携情報が存在しない場合は Google API に通信しない。
        $this->assertSame([], $history);
        $this->assertSame(0, $mockHandler->count());

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'user_id' => $user->id,
        ]);
    }
}
