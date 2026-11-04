<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session as CheckoutSession;

/**
 * Stripe Checkout Session のイベントを処理する。
 */
final class HandleStripeWebhookAction
{
    public function __invoke(string $eventType, CheckoutSession $session): void
    {
        $paymentId = $session->metadata->payment_id ?? null;

        if (! is_string($paymentId) || $paymentId === '') {
            return;
        }

        DB::transaction(function () use ($eventType, $session, $paymentId): void {
            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return;
            }

            if (
                $payment->stripe_checkout_session_id !== null
                && $payment->stripe_checkout_session_id !== $session->id
            ) {
                return;
            }

            // 決済済みなら、再通知されても回数を付与しない。
            if ($payment->status === PaymentStatus::Succeeded) {
                return;
            }

            if ($eventType === 'checkout.session.completed') {
                if ($session->payment_status !== 'paid') {
                    return;
                }

                $paymentIntentId = $session->payment_intent;

                $payment->update([
                    'status' => PaymentStatus::Succeeded,
                    'stripe_payment_intent_id' => is_string($paymentIntentId) ? $paymentIntentId : null,
                    'paid_at' => now(),
                ]);

                MeetingQuotaTransaction::query()->create([
                    'user_id' => $payment->user_id,
                    'type' => MeetingQuotaTransactionType::Purchased,
                    'amount' => $payment->quantity,
                    'related_payment_id' => $payment->id,
                    'note' => 'Stripe による面談回数の購入',
                    'occurred_at' => now(),
                ]);

                return;
            }

            if ($eventType === 'checkout.session.expired') {
                $payment->update([
                    'status' => PaymentStatus::Refunded,
                ]);

                return;
            }

            if ($eventType === 'checkout.session.async_payment_failed') {
                $payment->update([
                    'status' => PaymentStatus::Failed,
                ]);
            }
        });
    }
}
