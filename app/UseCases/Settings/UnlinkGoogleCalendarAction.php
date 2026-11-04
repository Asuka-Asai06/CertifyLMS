<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Calendar の連携を解除する Action。
 * Google 側のトークン失効を試み、LMS 側の連携情報を削除する。
 */
final class UnlinkGoogleCalendarAction
{
    public function __construct(
        private readonly GoogleCalendarService $googleCalendarService,
    ) {}

    /**
     * Google Calendar の連携を解除する。
     *
     * @return bool Google 側のトークン失効に成功した場合は true
     */
    public function __invoke(User $user): bool
    {
        $credential = $user->googleCredential()->first();

        if ($credential === null) {
            return true;
        }

        $token = $credential->refresh_token ?: $credential->access_token;
        $revoked = false;

        try {
            $this->googleCalendarService->revokeToken($token);
            $revoked = true;
        } catch (Throwable $exception) {
            Log::warning('Google Calendar token revocation failed.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);
        }

        $credential->delete();

        return $revoked;
    }
}
