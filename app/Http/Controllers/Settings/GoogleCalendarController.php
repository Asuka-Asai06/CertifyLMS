<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\UseCases\Settings\CompleteGoogleCalendarOAuthAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * コーチの Google カレンダー連携を管理するコントローラー。
 */
class GoogleCalendarController extends Controller
{
    /**
     * Google OAuth 認証画面へリダイレクトする。
     */
    public function redirect(Request $request, GoogleCalendarService $googleCalendarService): RedirectResponse
    {
        $state = bin2hex(random_bytes(32));

        $request->session()->put('google_calendar_oauth_state', $state);

        $authorizationUrl = $googleCalendarService->createAuthorizationUrl($state);

        return redirect()->away($authorizationUrl);
    }

    /**
     * Google OAuth のコールバックを処理する。
     */
    public function callback(Request $request, CompleteGoogleCalendarOAuthAction $action): RedirectResponse
    {
        $settingsUrl = route('settings.availability.index');

        $expectedState = $request->session()->pull('google_calendar_oauth_state');
        $returnedState = $request->query('state');

        if ($request->filled('error')) {
            if (
                ! is_string($expectedState)
                || ! is_string($returnedState)
                || ! hash_equals($expectedState, $returnedState)
            ) {
                return redirect()->to($settingsUrl)
                    ->with('error', 'Google カレンダーの認証を確認できませんでした。もう一度お試しください。');
            }

            return redirect()->to($settingsUrl)
                ->with('error', 'Google カレンダーとの連携がキャンセルされました。');
        }

        $code = $request->query('code');

        if (
            ! is_string($expectedState)
            || ! is_string($returnedState)
            || ! hash_equals($expectedState, $returnedState)
            || ! is_string($code)
            || $code === ''
        ) {
            return redirect()->to($settingsUrl)
                ->with('error', 'Google カレンダーの認証情報を確認できませんでした。もう一度お試しください。');
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $action($user, $expectedState, $returnedState, $code);
        } catch (Throwable $exception) {
            Log::warning('Google Calendar OAuth callback failed.', [
                'exception' => $exception::class,
            ]);

            return redirect()->to($settingsUrl)
                ->with('error', 'Google カレンダーとの連携に失敗しました。もう一度お試しください。');
        }

        return redirect()->to($settingsUrl)
            ->with('success', 'Google カレンダーと連携しました。');
    }

    /**
     * Google カレンダーの連携情報を解除する。
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->googleCredential()->delete();

        return redirect()
            ->route('settings.availability.index')
            ->with('success', 'Google カレンダーの連携を解除しました。');
    }
}
