<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingQuota\CheckoutRequest;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\UseCases\MeetingQuota\CreateCheckoutSessionAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Stripe\Exception\ApiErrorException;

/**
 * 追加面談パックの購入フローを管理する Controller。
 */
class MeetingQuotaCheckoutController extends Controller
{
    /**
     * 購入可能な面談パック一覧を表示する。
     */
    public function index(): View
    {
        $plans = MeetingPack::query()
            ->published()
            ->ordered()
            ->get();

        return view('meeting-quota.checkout-select', [
            'plans' => $plans,
        ]);
    }

    /**
     * 面談パックの購入リクエストを受け付ける。
     */
    public function store(CheckoutRequest $request, CreateCheckoutSessionAction $action): RedirectResponse
    {
        try {
            $checkoutUrl = $action(
                $request->user(),
                $request->validated('meeting_pack_id'),
            );
        } catch (ApiErrorException $exception) {
            report($exception);

            return back()->with(
                'error',
                '決済画面を準備できませんでした。時間をおいて再度お試しください。',
            );
        }

        return redirect()->away($checkoutUrl);
    }

    /**
     * 決済完了後の画面を表示する。
     */
    public function success(Request $request): View|RedirectResponse
    {
        // URL パラメータだけで決済済みと判断しない。
        // Stripe の決済結果との照合処理を次のステップで実装する。
        $sessionId = $request->query('session_id');

        if (! is_string($sessionId) || $sessionId === '') {
            return redirect()
                ->route('meeting-quota.history')
                ->with('error', '決済情報を確認できませんでした。');
        }

        return view('meeting-quota.success', [
            'payment' => Payment::query()
                ->where('stripe_checkout_session_id', $sessionId)
                ->where('user_id', $request->user()->id)
                ->first(),
        ]);
    }
}
