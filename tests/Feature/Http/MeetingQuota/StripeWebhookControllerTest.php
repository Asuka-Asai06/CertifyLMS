<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * StripeWebhookController のテスト。
 *
 * Stripe Webhook の署名検証と、通知の重複受信時の動作を確認する。
 *
 * @group external-api
 */
class StripeWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    /**
     * 正規の署名が付いたWebhookを正常に処理する。
     */
    public function test_handles_webhook_with_valid_signature(): void
    {
        // Arrange
        $payment = $this->createPayment();

        $payload = $this->createPayload($payment);

        $signature = $this->generateStripeSignature($payload);

        // Act
        $response = $this->postWebhook($payload, $signature);

        // Assert
        $response->assertOk()
            ->assertSeeText('Webhook handled.');

        $this->assertSame(
            PaymentStatus::Succeeded,
            $payment->fresh()->status,
        );

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 5,
        ]);
    }

    /**
     * 不正な署名が付いたWebhookを拒否する。
     */
    public function test_rejects_webhook_with_invalid_signature(): void
    {
        // Arrange
        $payment = $this->createPayment();

        $payload = $this->createPayload($payment);

        // Act
        $response = $this->postWebhook(
            $payload,
            'invalid_signature',
        );

        // Assert
        $response->assertBadRequest()
            ->assertSeeText('Invalid Stripe webhook.');

        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
        ]);
    }

    /**
     * 署名が付いていないWebhookを拒否する。
     */
    public function test_rejects_webhook_without_signature(): void
    {
        // Arrange
        $payment = $this->createPayment();

        $payload = $this->createPayload($payment);

        // Act
        $response = $this->postWebhook($payload);

        // Assert
        $response->assertBadRequest()
            ->assertSeeText('Missing Stripe signature.');

        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
        ]);
    }

    /**
     * 同じWebhookが二重に届いても面談回数を重複付与しない。
     */
    public function test_does_not_grant_quota_twice_when_webhook_is_duplicated(): void
    {
        // Arrange
        $payment = $this->createPayment();

        $payload = $this->createPayload($payment);

        $signature = $this->generateStripeSignature($payload);

        // Act
        $firstResponse = $this->postWebhook($payload, $signature);
        $secondResponse = $this->postWebhook($payload, $signature);

        // Assert
        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertSame(
            PaymentStatus::Succeeded,
            $payment->fresh()->status,
        );

        $this->assertSame(
            1,
            MeetingQuotaTransaction::query()
                ->where('related_payment_id', $payment->id)
                ->where('type', MeetingQuotaTransactionType::Purchased)
                ->count(),
        );
    }

    /**
     * Webhook Secret が未設定の場合はエラーを返す。
     */
    public function test_returns_server_error_when_webhook_secret_is_not_configured(): void
    {
        // Arrange
        config([
            'services.stripe.webhook_secret' => null,
        ]);

        // Act
        $response = $this->postWebhook('{}');

        // Assert
        $response->assertStatus(500)
            ->assertSeeText('Webhook secret is not configured.');
    }

    /**
     * 未対応イベントは正常に受信して無視する。
     */
    public function test_ignores_unsupported_event_type(): void
    {
        // Arrange
        $payment = $this->createPayment();

        $payload = json_encode([
            'id' => 'evt_test_unsupported',
            'object' => 'event',
            'type' => 'customer.created',
            'data' => [
                'object' => [
                    'id' => 'cus_test_123',
                    'object' => 'customer',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->generateStripeSignature($payload);

        // Act
        $response = $this->postWebhook($payload, $signature);

        // Assert
        $response->assertOk()
            ->assertSeeText('Event ignored.');

        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
        ]);
    }

    /**
     * Checkout Session 以外のオブジェクトを含むイベントは拒否する。
     */
    public function test_rejects_event_with_unexpected_object_type(): void
    {
        // Arrange
        $payment = $this->createPayment();

        $payload = json_encode([
            'id' => 'evt_test_unexpected',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cus_test_123',
                    'object' => 'customer',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->generateStripeSignature($payload);

        // Act
        $response = $this->postWebhook($payload, $signature);

        // Assert
        $response->assertBadRequest()
            ->assertSeeText('Unexpected event object.');

        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
        ]);
    }

    /**
     * 不正な JSON を含むWebhookは拒否する。
     */
    public function test_rejects_webhook_with_invalid_json_payload(): void
    {
        // Arrange
        $payload = '{"id": "evt_test_invalid",';

        $signature = $this->generateStripeSignature($payload);

        // Act
        $response = $this->postWebhook($payload, $signature);

        // Assert
        $response->assertBadRequest()
            ->assertSeeText('Invalid Stripe webhook.');
    }

    /**
     * テスト用の Payment を作成する。
     */
    private function createPayment(): Payment
    {
        $user = User::factory()->student()->create();

        return Payment::query()->create([
            'user_id' => $user->id,
            'meeting_pack_id' => null,
            'meeting_pack_name' => 'テスト面談パック',
            'quantity' => 5,
            'amount' => 5000,
            'status' => PaymentStatus::Pending,
            'stripe_checkout_session_id' => 'cs_test_'.uniqid(),
            'stripe_payment_intent_id' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * テスト用の Stripe Event JSON を生成する。
     */
    private function createPayload(Payment $payment): string
    {
        return json_encode([
            'id' => 'evt_test_123',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $payment->stripe_checkout_session_id,
                    'object' => 'checkout.session',
                    'metadata' => [
                        'payment_id' => $payment->id,
                    ],
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_test_123',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * テスト用の Stripe 署名を生成する。
     */
    private function generateStripeSignature(string $payload): string
    {
        $timestamp = time();

        $signedPayload = $timestamp.'.'.$payload;

        $signature = hash_hmac(
            'sha256',
            $signedPayload,
            self::WEBHOOK_SECRET,
        );

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Stripe Webhook を送信する。
     */
    private function postWebhook(string $payload, ?string $signature = null): TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
        ];

        if ($signature !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = $signature;
        }

        return $this->call(
            'POST',
            route('webhooks.stripe'),
            [],
            [],
            [],
            $server,
            $payload,
        );
    }
}
