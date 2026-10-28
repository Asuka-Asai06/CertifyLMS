<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Google\Client;
use Google\Service\Calendar;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Google Calendar API との連携処理を提供する Service。
 * OAuth 認証、アクセストークンの更新、予定あり時間の取得を行う。
 */
final class GoogleCalendarService
{
    /**
     * Google OAuth の認証 URL を生成する。
     */
    public function createAuthorizationUrl(string $state): string
    {
        $client = $this->createClient();

        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setScopes([
            Calendar::CALENDAR_EVENTS,
            Calendar::CALENDAR_READONLY,
        ]);
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * OAuth 認証コードをアクセストークンに交換する。
     *
     * @return array<string, mixed>
     */
    public function fetchAccessToken(string $code): array
    {
        return $this->createClient()->fetchAccessTokenWithAuthCode($code);
    }

    /**
     * 指定期間の Google Calendar の予定あり時間を取得する。
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function fetchBusyPeriods(
        GoogleCalendarCredential $credential,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array {
        $client = $this->createClient();
        $client->setAccessToken($this->getValidAccessToken($credential, $client));

        $calendar = new Calendar($client);

        try {
            $request = new Calendar\FreeBusyRequest;
            $request->setTimeMin($start->toRfc3339String());
            $request->setTimeMax($end->toRfc3339String());
            $request->setTimeZone(config('app.timezone'));
            $request->setItems([
                ['id' => $credential->calendar_id],
            ]);

            $response = $calendar->freebusy->query($request);
            $calendarBusy = $response->getCalendars()[$credential->calendar_id] ?? null;

            if ($calendarBusy === null || ! empty($calendarBusy->getErrors())) {
                throw new RuntimeException('Google Calendar の予定を取得できませんでした。');
            }

            $periods = [];

            foreach ($calendarBusy->getBusy() ?? [] as $busy) {
                if (! $busy->getStart() || ! $busy->getEnd()) {
                    continue;
                }

                $periods[] = [
                    'start' => CarbonImmutable::parse($busy->getStart()),
                    'end' => CarbonImmutable::parse($busy->getEnd()),
                ];
            }

            return $periods;
        } catch (Throwable $exception) {
            Log::warning('Google Calendar busy periods fetch failed.', [
                'user_id' => $credential->user_id,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    /**
     * 有効なアクセストークンを返す。期限切れの場合は更新する。
     *
     * @return array<string, mixed>
     */
    private function getValidAccessToken(
        GoogleCalendarCredential $credential,
        Client $client,
    ): array {
        if (
            $credential->expires_at !== null
            && $credential->expires_at->isFuture()
            && $credential->access_token !== ''
        ) {
            return [
                'access_token' => $credential->access_token,
                'refresh_token' => $credential->refresh_token,
                'expires_at' => $credential->expires_at->timestamp,
            ];
        }

        if (! is_string($credential->refresh_token) || $credential->refresh_token === '') {
            throw new RuntimeException('Google Calendar の再認証が必要です。');
        }

        $client->setAccessToken([
            'access_token' => $credential->access_token,
            'refresh_token' => $credential->refresh_token,
        ]);

        $token = $client->fetchAccessTokenWithRefreshToken($credential->refresh_token);

        if (
            ! isset($token['access_token'])
            || ! is_string($token['access_token'])
            || $token['access_token'] === ''
            || isset($token['error'])
        ) {
            throw new RuntimeException('Google Calendar のアクセストークンを更新できませんでした。');
        }

        $attributes = [
            'access_token' => $token['access_token'],
            'expires_at' => isset($token['expires_in'])
                ? now()->addSeconds((int) $token['expires_in'])
                : null,
        ];

        // 更新レスポンスに refresh_token がない場合は既存の値を維持する。
        if (
            isset($token['refresh_token'])
            && is_string($token['refresh_token'])
            && $token['refresh_token'] !== ''
        ) {
            $attributes['refresh_token'] = $token['refresh_token'];
        }

        $credential->forceFill($attributes)->save();

        return $token;
    }

    /**
     * Google API クライアントを生成する。
     */
    private function createClient(): Client
    {
        $client = new Client;

        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setRedirectUri((string) config('services.google.redirect'));

        return $client;
    }

    /**
     * 面談予約に対応する Google Calendar イベントを作成する。
     *
     * @throws Throwable Google Calendar API の呼び出しに失敗した場合
     */
    public function createEvent(GoogleCalendarCredential $credential, Meeting $meeting): string
    {
        $client = $this->createClient();
        $client->setAccessToken(
            $this->getValidAccessToken($credential, $client),
        );

        $calendar = new Calendar($client);

        $start = $meeting->scheduled_at;
        $end = $start->copy()->addMinutes(60);

        $event = new Calendar\Event([
            'summary' => 'CertifyLMS 面談：'.$meeting->topic,
            'description' => $meeting->topic,
            'start' => [
                'dateTime' => $start->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ],
            'end' => [
                'dateTime' => $end->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ],
        ]);

        try {
            $createdEvent = $calendar->events->insert(
                $credential->calendar_id,
                $event,
            );

            $eventId = $createdEvent->getId();

            if (! is_string($eventId) || $eventId === '') {
                throw new RuntimeException(
                    'Google Calendar のイベント ID を取得できませんでした。',
                );
            }

            return $eventId;
        } catch (Throwable $exception) {
            Log::warning(
                'Google Calendar event creation failed.',
                [
                    'meeting_id' => $meeting->id,
                    'coach_id' => $meeting->coach_id,
                    'exception' => $exception::class,
                ],
            );

            throw $exception;
        }
    }

    /**
     * Google Calendar の面談イベントを削除する。
     *
     * @throws Throwable Google Calendar API の呼び出しに失敗した場合
     */
    public function deleteEvent(GoogleCalendarCredential $credential, string $eventId): void
    {
        $client = $this->createClient();
        $client->setAccessToken(
            $this->getValidAccessToken($credential, $client),
        );

        $calendar = new Calendar($client);

        try {
            $calendar->events->delete(
                $credential->calendar_id,
                $eventId,
            );
        } catch (Throwable $exception) {
            Log::warning('Google Calendar event deletion failed.', [
                'coach_id' => $credential->user_id,
                'event_id' => $eventId,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    /**
     * Google OAuth のトークンを失効させる。
     *
     * @throws Throwable Google OAuth のトークン失効処理に失敗した場合
     */
    public function revokeToken(string $token): void
    {
        if ($token === '') {
            throw new RuntimeException('Google OAuth のトークンがありません。');
        }

        $client = $this->createClient();

        if (! $client->revokeToken($token)) {
            throw new RuntimeException('Google OAuth のトークンを失効できませんでした。');
        }
    }
}
