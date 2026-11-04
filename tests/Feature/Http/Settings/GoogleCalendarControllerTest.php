<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use App\Services\GoogleCalendarService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Google Calendar 連携コントローラーのテスト。
 *
 * 外部APIとの通信は Guzzle MockHandler でモックする。
 *
 * @group external-api
 */
class GoogleCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guzzle MockHandler を注入した GoogleCalendarService を登録する。
     */
    private function mockGoogleCalendarService(MockHandler $mockHandler): void
    {
        $httpClient = new GuzzleClient([
            'handler' => HandlerStack::create($mockHandler),
        ]);

        $googleCalendarService = new GoogleCalendarService($httpClient);

        $this->app->instance(
            GoogleCalendarService::class,
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

    /**
     * Google OAuth 認証画面へのリダイレクトと state の保存を確認する。
     */
    public function test_redirects_to_google_authorization_url_and_stores_state(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost:8000/settings/google-calendar/callback',
        ]);

        // Act
        $response = $this->actingAs($coach)
            ->get(route('settings.google-calendar.redirect'));

        // Assert
        $response->assertRedirect();

        $location = $response->headers->get('Location');

        $this->assertIsString($location);
        $this->assertStringContainsString('accounts.google.com', $location);

        $query = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('state', $query);
        $this->assertIsString($query['state']);
        $this->assertSame(
            $query['state'],
            session('google_calendar_oauth_state'),
        );
    }

    /**
     * state が一致しない場合に認証を拒否する。
     */
    public function test_callback_rejects_mismatched_state(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->withSession([
                'google_calendar_oauth_state' => 'expected-state',
            ]);

        // Act
        $response = $this->get(
            route('settings.google-calendar.callback').'?'.http_build_query([
                'state' => 'different-state',
                'code' => 'test-code',
            ])
        );

        // Assert
        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHas(
            'error',
            'Google カレンダーの認証情報を確認できませんでした。もう一度お試しください。',
        );
        $response->assertSessionMissing('google_calendar_oauth_state');
    }

    /**
     * Google OAuth 認証がキャンセルされた場合にエラーを表示する。
     */
    public function test_callback_handles_authorization_cancellation(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $state = 'valid-test-state';

        // Act
        $response = $this->actingAs($coach)
            ->withSession([
                'google_calendar_oauth_state' => $state,
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => $state,
                'error' => 'access_denied',
            ]));

        // Assert
        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHas(
            'error',
            'Google カレンダーとの連携がキャンセルされました。',
        );
        $response->assertSessionMissing('google_calendar_oauth_state');
    }

    /**
     * Google OAuth コールバック成功時に認証情報を保存する。
     */
    public function test_callback_stores_google_calendar_credential_after_successful_token_exchange(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $state = 'valid-test-state';

        $mockHandler = new MockHandler([
            $this->createTokenResponse([
                'access_token' => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $this->mockGoogleCalendarService($mockHandler);

        // Act
        $response = $this->actingAs($coach)
            ->withSession([
                'google_calendar_oauth_state' => $state,
            ])
            ->get(route('settings.google-calendar.callback', [
                'state' => $state,
                'code' => 'test-authorization-code',
            ]));

        // Assert
        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHas(
            'success',
            'Google カレンダーと連携しました。',
        );
        $response->assertSessionMissing('google_calendar_oauth_state');

        $this->assertDatabaseHas('google_calendar_credentials', [
            'user_id' => $coach->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'calendar_id' => 'primary',
        ]);

        $this->assertSame(0, $mockHandler->count());
    }

    /**
     * Google Calendar の連携解除時に認証情報を削除する。
     */
    public function test_destroy_deletes_google_calendar_credential(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        $credential = GoogleCalendarCredential::factory()->create([
            'user_id' => $coach->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
        ]);

        $mockHandler = new MockHandler([
            new Response(200),
        ]);

        $this->mockGoogleCalendarService($mockHandler);

        // Act
        $response = $this->actingAs($coach)
            ->delete(route('settings.google-calendar.destroy'));

        // Assert
        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHas(
            'success',
            'Google カレンダーの連携を解除しました。',
        );

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'id' => $credential->id,
        ]);

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'user_id' => $coach->id,
        ]);

        $this->assertSame(0, $mockHandler->count());
    }

    /**
     * Google 側のトークン失効に失敗しても LMS 側の連携情報を削除する。
     */
    public function test_destroy_shows_warning_when_google_token_revocation_fails(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        $credential = GoogleCalendarCredential::factory()->create([
            'user_id' => $coach->id,
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

        $this->mockGoogleCalendarService($mockHandler);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($coach): bool {
                return $message === 'Google Calendar token revocation failed.'
                    && $context['user_id'] === $coach->id
                    && isset($context['exception']);
            });

        // Act
        $response = $this->actingAs($coach)
            ->delete(route('settings.google-calendar.destroy'));

        // Assert
        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHas(
            'warning',
            'LMS 側の連携は解除しましたが、Google 側のアクセス権を失効できませんでした。Google アカウントの設定からアクセス権を確認してください。',
        );

        // Google 側の失効に失敗しても LMS 側の情報は削除される。
        $this->assertDatabaseMissing('google_calendar_credentials', [
            'id' => $credential->id,
        ]);

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'user_id' => $coach->id,
        ]);

        $this->assertSame(0, $mockHandler->count());
    }
}
