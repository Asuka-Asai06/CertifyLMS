<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

final class StripeWebhookControllerTest extends TestCase
{
    /**
     * Webhook secret が未設定の場合は 500 を返す。
     */
    public function test_returns_500_when_webhook_secret_is_not_configured(): void
    {
        // Arrange
        config(['services.stripe.webhook_secret' => '']);

        // Act
        $response = $this->post('/webhooks/stripe', [], [
            'Stripe-Signature' => 't=123,v1=invalid',
        ]);

        // Assert
        $response
            ->assertStatus(500)
            ->assertSeeText('Webhook secret is not configured.');
    }

    /**
     * Stripe の署名ヘッダーがない場合は 400 を返す。
     */
    public function test_returns_400_when_stripe_signature_is_missing(): void
    {
        // Arrange
        config(['services.stripe.webhook_secret' => 'whsec_test_secret']);

        // Act
        $response = $this->post('/webhooks/stripe');

        // Assert
        $response
            ->assertStatus(400)
            ->assertSeeText('Missing Stripe signature.');
    }

    /**
     * 不正なペイロードの場合は 400 を返す。
     */
    public function test_returns_400_when_payload_is_invalid(): void
    {
        // Arrange
        config(['services.stripe.webhook_secret' => 'whsec_test_secret']);

        // Act
        $response = $this->call(
            'POST',
            '/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't=123,v1=invalid',
            ],
            'invalid-json',
        );

        // Assert
        $response
            ->assertStatus(400)
            ->assertSeeText('Invalid Stripe webhook.');
    }

    /**
     * 署名が正しくない場合は 400 を返す。
     */
    public function test_returns_400_when_stripe_signature_is_invalid(): void
    {
        // Arrange
        config(['services.stripe.webhook_secret' => 'whsec_test_secret']);

        $payload = json_encode([
            'id' => 'evt_test_123',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_123',
                    'object' => 'checkout.session',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Act
        $response = $this->call(
            'POST',
            '/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=invalid',
            ],
            $payload,
        );

        // Assert
        $response
            ->assertStatus(400)
            ->assertSeeText('Invalid Stripe webhook.');
    }
}
