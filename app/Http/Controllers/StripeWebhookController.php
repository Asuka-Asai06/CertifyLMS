<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\UseCases\MeetingQuota\HandleStripeWebhookAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

final class StripeWebhookController extends Controller
{
    /**
     * Stripe Webhook を検証して処理する。
     */
    public function handle(Request $request, HandleStripeWebhookAction $action): Response
    {
        $webhookSecret = config('services.stripe.webhook_secret');
        $signature = $request->header('Stripe-Signature');

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            return response('Webhook secret is not configured.', 500);
        }

        if (! is_string($signature) || $signature === '') {
            return response('Missing Stripe signature.', 400);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $signature,
                $webhookSecret,
            );
        } catch (UnexpectedValueException|SignatureVerificationException $exception) {
            return response('Invalid Stripe webhook.', 400);
        }

        $supportedEvents = [
            'checkout.session.completed',
            'checkout.session.expired',
            'checkout.session.async_payment_failed',
        ];

        if (! in_array($event->type, $supportedEvents, true)) {
            return response('Event ignored.', 200);
        }

        $session = $event->data->object;

        if (! $session instanceof CheckoutSession) {
            return response('Unexpected event object.', 400);
        }

        $action($event->type, $session);

        return response('Webhook handled.', 200);
    }
}
