<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Settings;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Google Calendar 連携コントローラーのテスト。
 *
 * 外部APIとの通信を必要としない処理を検証する。
 * OAuth の成功・トークン交換などの外部APIに関わるテストは T-A-04 で扱う。
 */
class GoogleCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

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
     * Google Calendar の連携解除時に認証情報を削除する。
     */
    public function test_destroy_deletes_google_calendar_credential(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        $credential = GoogleCalendarCredential::factory()->create([
            'user_id' => $coach->id,
        ]);

        // Act
        $response = $this->actingAs($coach)
            ->delete(route('settings.google-calendar.destroy'));

        // Assert
        $response->assertRedirect(route('settings.availability.index'));

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'id' => $credential->id,
        ]);

        $this->assertDatabaseMissing('google_calendar_credentials', [
            'user_id' => $coach->id,
        ]);
    }
}
