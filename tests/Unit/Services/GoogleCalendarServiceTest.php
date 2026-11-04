<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GoogleCalendarCredential;
use App\Services\GoogleCalendarService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * GoogleCalendarService のアクセストークン管理をテストする。
 * Guzzle MockHandler を使用し、Google API への実際の通信を行わずに検証する。
 *
 * @group external-api
 */
class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 有効期限内のアクセストークンがある場合、更新せずに予定を取得できること。
     */
    public function test_fetch_busy_periods_uses_valid_access_token_without_refreshing(): void
    {
        // Arrange
        $credential = GoogleCalendarCredential::factory()->create([
            'access_token' => 'valid-access-token',
            'refresh_token' => 'existing-refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        // Calendar API のレスポンスだけを用意する。
        // トークン更新APIが呼ばれた場合は、レスポンスが不足して例外になる。
        $mockHandler = new MockHandler([
            $this->createFreeBusyResponse(),
        ]);

        $service = $this->createService($mockHandler);

        // Act
        $periods = $service->fetchBusyPeriods(
            $credential,
            CarbonImmutable::parse('2026-10-30 10:00:00'),
            CarbonImmutable::parse('2026-10-30 18:00:00'),
        );

        // Assert
        $this->assertSame([], $periods);
        $this->assertSame('valid-access-token', $credential->fresh()->access_token);
        $this->assertSame(0, $mockHandler->count());
    }

    /**
     * アクセストークンの有効期限が切れている場合、新しいトークンに更新して予定を取得できること。
     */
    public function test_fetch_busy_periods_refreshes_expired_access_token(): void
    {
        // Arrange
        $credential = GoogleCalendarCredential::factory()
            ->expired()
            ->create([
                'access_token' => 'expired-access-token',
                'refresh_token' => 'existing-refresh-token',
            ]);

        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'new-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),

            $this->createFreeBusyResponse(),
        ]);

        $service = $this->createService($mockHandler);

        // Act
        $periods = $service->fetchBusyPeriods(
            $credential,
            CarbonImmutable::parse('2026-10-30 10:00:00'),
            CarbonImmutable::parse('2026-10-30 18:00:00'),
        );

        // Assert
        $updatedCredential = $credential->fresh();

        $this->assertSame([], $periods);
        $this->assertSame('new-access-token', $updatedCredential->access_token);
        $this->assertSame('existing-refresh-token', $updatedCredential->refresh_token);
        $this->assertTrue($updatedCredential->expires_at->isFuture());
        $this->assertSame(0, $mockHandler->count());
    }

    /**
     * アクセストークンの更新に失敗した場合、例外を送出して予定取得を行わないこと。
     */
    public function test_fetch_busy_periods_throws_exception_when_token_refresh_fails(): void
    {
        // Arrange
        $credential = GoogleCalendarCredential::factory()
            ->expired()
            ->create([
                'access_token' => 'expired-access-token',
                'refresh_token' => 'existing-refresh-token',
            ]);

        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($mockHandler);

        // Act & Assert
        try {
            $service->fetchBusyPeriods(
                $credential,
                CarbonImmutable::parse('2026-10-30 10:00:00'),
                CarbonImmutable::parse('2026-10-30 18:00:00'),
            );

            $this->fail('アクセストークンの更新失敗時に例外が発生しませんでした。');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Google Calendar のアクセストークンを更新できませんでした。',
                $exception->getMessage(),
            );
        }

        // Calendar API は呼び出されず、更新APIのレスポンスだけが消費される。
        $this->assertSame(0, $mockHandler->count());

        // 更新失敗時は既存の認証情報を変更しない。
        $updatedCredential = $credential->fresh();

        $this->assertSame('expired-access-token', $updatedCredential->access_token);
        $this->assertSame('existing-refresh-token', $updatedCredential->refresh_token);
    }

    /**
     * GoogleCalendarService に Guzzle のモック HTTP クライアントを渡す。
     */
    private function createService(MockHandler $mockHandler): GoogleCalendarService
    {
        $handlerStack = HandlerStack::create($mockHandler);

        $httpClient = new GuzzleClient([
            'handler' => $handlerStack,
        ]);

        return new GoogleCalendarService($httpClient);
    }

    /**
     * Google Calendar FreeBusy API の空の予定一覧レスポンスを生成する。
     */
    private function createFreeBusyResponse(): Response
    {
        return new Response(200, [], json_encode([
            'calendars' => [
                'primary' => [
                    'busy' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * トークン更新時に新しい refresh_token が返された場合、DBに保存すること。
     */
    public function test_fetch_busy_periods_saves_new_refresh_token_when_refreshing(): void
    {
        // Arrange
        $credential = GoogleCalendarCredential::factory()
            ->expired()
            ->create([
                'access_token' => 'expired-access-token',
                'refresh_token' => 'old-refresh-token',
            ]);

        $mockHandler = new MockHandler([
            // OAuth トークン更新API
            new Response(200, [], json_encode([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),

            // Calendar API
            $this->createFreeBusyResponse(),
        ]);

        $service = $this->createService($mockHandler);

        // Act
        $periods = $service->fetchBusyPeriods(
            $credential,
            CarbonImmutable::parse('2026-10-30 10:00:00'),
            CarbonImmutable::parse('2026-10-30 18:00:00'),
        );

        // Assert
        $updatedCredential = $credential->fresh();

        $this->assertSame([], $periods);
        $this->assertSame('new-access-token', $updatedCredential->access_token);
        $this->assertSame('new-refresh-token', $updatedCredential->refresh_token);
        $this->assertTrue($updatedCredential->expires_at->isFuture());
        $this->assertSame(0, $mockHandler->count());
    }

    /**
     * refresh_token が存在しない場合、再認証を要求する例外を送出すること。
     */
    public function test_fetch_busy_periods_throws_exception_when_refresh_token_is_missing(): void
    {
        // Arrange
        $credential = GoogleCalendarCredential::factory()
            ->expired()
            ->withoutRefreshToken()
            ->create([
                'access_token' => 'expired-access-token',
            ]);

        $mockHandler = new MockHandler([]);

        $service = $this->createService($mockHandler);

        // Act & Assert
        try {
            $service->fetchBusyPeriods(
                $credential,
                CarbonImmutable::parse('2026-10-30 10:00:00'),
                CarbonImmutable::parse('2026-10-30 18:00:00'),
            );

            $this->fail('refresh_token がない場合に例外が発生しませんでした。');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Google Calendar の再認証が必要です。',
                $exception->getMessage(),
            );
        }

        // Google APIへの通信が発生していないこと。
        $this->assertSame(0, $mockHandler->count());
    }

    /**
     * FreeBusy APIから取得した予定あり時間を正しく変換できること。
     */
    public function test_fetch_busy_periods_returns_busy_periods(): void
    {
        // Arrange
        $credential = GoogleCalendarCredential::factory()->create([
            'calendar_id' => 'primary',
        ]);

        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'calendars' => [
                    'primary' => [
                        'busy' => [
                            [
                                'start' => '2026-10-30T10:00:00+09:00',
                                'end' => '2026-10-30T11:00:00+09:00',
                            ],
                            [
                                'start' => '2026-10-30T14:00:00+09:00',
                                'end' => '2026-10-30T15:00:00+09:00',
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($mockHandler);

        // Act
        $periods = $service->fetchBusyPeriods(
            $credential,
            CarbonImmutable::parse('2026-10-30 09:00:00'),
            CarbonImmutable::parse('2026-10-30 18:00:00'),
        );

        // Assert
        $this->assertCount(2, $periods);

        $this->assertEquals(
            CarbonImmutable::parse('2026-10-30T10:00:00+09:00'),
            $periods[0]['start'],
        );

        $this->assertEquals(
            CarbonImmutable::parse('2026-10-30T11:00:00+09:00'),
            $periods[0]['end'],
        );

        $this->assertEquals(
            CarbonImmutable::parse('2026-10-30T14:00:00+09:00'),
            $periods[1]['start'],
        );

        $this->assertEquals(
            CarbonImmutable::parse('2026-10-30T15:00:00+09:00'),
            $periods[1]['end'],
        );

        $this->assertSame(0, $mockHandler->count());
    }

    /**
     * FreeBusy APIがHTTPエラーを返した場合、警告ログを出して例外を送出すること。
     */
    public function test_fetch_busy_periods_logs_warning_and_throws_exception_when_api_fails(): void
    {
        // Arrange
        $credential = GoogleCalendarCredential::factory()->create();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Google Calendar busy periods fetch failed.'
                    && isset($context['user_id'])
                    && isset($context['exception']);
            });

        $mockHandler = new MockHandler([
            new Response(403, [], json_encode([
                'error' => [
                    'code' => 403,
                    'message' => 'Permission denied.',
                    'errors' => [
                        [
                            'domain' => 'global',
                            'reason' => 'forbidden',
                            'message' => 'Permission denied.',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($mockHandler);

        // Act & Assert
        $this->expectException(\Throwable::class);

        $service->fetchBusyPeriods(
            $credential,
            CarbonImmutable::parse('2026-10-30 09:00:00'),
            CarbonImmutable::parse('2026-10-30 18:00:00'),
        );
    }

    /**
     * FreeBusy APIがカレンダー単位のエラーを返した場合、例外を送出すること。
     */
    public function test_fetch_busy_periods_throws_exception_when_calendar_has_errors(): void
    {
        // Arrange
        $credential = GoogleCalendarCredential::factory()->create([
            'calendar_id' => 'primary',
        ]);

        Log::shouldReceive('warning')
            ->once();

        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'calendars' => [
                    'primary' => [
                        'errors' => [
                            [
                                'domain' => 'global',
                                'reason' => 'notFound',
                            ],
                        ],
                        'busy' => [],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($mockHandler);

        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Google Calendar の予定を取得できませんでした。',
        );

        $service->fetchBusyPeriods(
            $credential,
            CarbonImmutable::parse('2026-10-30 09:00:00'),
            CarbonImmutable::parse('2026-10-30 18:00:00'),
        );
    }
}
