<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingPackStatus;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * 購入記録を作成し、Stripe Checkout Session の URL を返すAction
 */
final class CreateCheckoutSessionAction
{
    public function __invoke(User $user, string $meetingPackId): string
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Stripe の Secret Key が設定されていません。');
        }

        $pack = MeetingPack::query()
            ->whereKey($meetingPackId)
            ->where('status', MeetingPackStatus::Published->value)
            ->firstOrFail();

        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'meeting_pack_id' => $pack->id,
            'meeting_pack_name' => $pack->name,
            'quantity' => $pack->meeting_count,
            'amount' => $pack->price,
            'status' => PaymentStatus::Pending,
        ]);

        $stripe = new StripeClient($secret);

        try {
            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'client_reference_id' => $payment->id,
                'metadata' => [
                    'payment_id' => $payment->id,
                ],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'jpy',
                        'product_data' => [
                            'name' => $pack->name,
                        ],
                        'unit_amount' => $pack->price,
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => route('meeting-quota.success')
                    .'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('meeting-quota.checkout.select'),
            ]);
        } catch (ApiErrorException $exception) {
            $payment->update([
                'status' => PaymentStatus::Failed,
            ]);

            throw $exception;
        }

        if (! is_string($session->url) || $session->url === '') {
            $payment->update([
                'status' => PaymentStatus::Failed,
            ]);

            throw new RuntimeException('Stripe Checkout URL を取得できませんでした。');
        }

        $payment->update([
            'stripe_checkout_session_id' => $session->id,
        ]);

        return $session->url;
    }
}
