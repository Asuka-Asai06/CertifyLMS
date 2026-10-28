<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Google Calendar OAuth 認証を完了する Action。
 * state を検証し、取得したトークンをユーザーの連携情報として保存する。
 */
final class CompleteGoogleCalendarOAuthAction
{
    public function __construct(
        private readonly GoogleCalendarService $googleCalendarService,
    ) {}

    /**
     * Google OAuth 認証を完了し、連携情報を保存する。
     *
     * @throws RuntimeException state の検証またはトークン取得に失敗した場合
     */
    public function __invoke(
        User $user,
        string $expectedState,
        string $returnedState,
        string $code,
    ): void {
        if (
            $expectedState === ''
            || $returnedState === ''
            || ! hash_equals($expectedState, $returnedState)
        ) {
            throw new RuntimeException('Google OAuth の state 検証に失敗しました。');
        }

        if ($code === '') {
            throw new RuntimeException('Google OAuth の認証コードがありません。');
        }

        $token = $this->googleCalendarService->fetchAccessToken($code);

        if (
            ! isset($token['access_token'])
            || ! is_string($token['access_token'])
            || $token['access_token'] === ''
            || isset($token['error'])
        ) {
            throw new RuntimeException('Google OAuth のトークン取得に失敗しました。');
        }

        $attributes = [
            'access_token' => $token['access_token'],
            'expires_at' => isset($token['expires_in'])
                ? now()->addSeconds((int) $token['expires_in'])
                : null,
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ];

        // Google が返さない場合は既存の refresh_token を維持する。
        if (
            isset($token['refresh_token'])
            && is_string($token['refresh_token'])
            && $token['refresh_token'] !== ''
        ) {
            $attributes['refresh_token'] = $token['refresh_token'];
        }

        DB::transaction(function () use ($user, $attributes): void {
            $user->googleCredential()->updateOrCreate([], $attributes);
        });
    }
}
