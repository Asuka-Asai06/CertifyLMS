<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use App\UseCases\MeetingQuota\HandleStripeWebhookAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session as CheckoutSession;
use Tests\TestCase;

/**
 * HandleStripeWebhookAction のテスト。
 *
 * Stripe Checkout Session のイベントに応じた決済状態の更新と、
 * 面談回数の付与、および重複通知時の動作を確認する。
 */
class HandleStripeWebhookActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 決済成功時に決済状態を更新し、面談回数を付与する。
     */
    public function test_paid_checkout_session_updates_payment_and_grants_quota(): void
    {
        // Arrange
        $payment = $this->createPayment(quantity: 5);
        $session = $this->createSession($payment, [
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_123',
        ]);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.completed',
            $session,
        );

        // Assert
        $payment->refresh();

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame('pi_test_123', $payment->stripe_payment_intent_id);
        $this->assertNotNull($payment->paid_at);

        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $payment->user_id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 5,
            'related_payment_id' => $payment->id,
            'note' => 'Stripe による面談回数の購入',
        ]);

        $this->assertSame(
            1,
            MeetingQuotaTransaction::query()
                ->where('related_payment_id', $payment->id)
                ->where('type', MeetingQuotaTransactionType::Purchased)
                ->count(),
        );
    }

    /**
     * 決済完了イベントでも未決済の場合は回数を付与しない。
     */
    public function test_does_not_grant_quota_when_checkout_session_is_not_paid(): void
    {
        // Arrange
        $payment = $this->createPayment(quantity: 3);
        $session = $this->createSession($payment, [
            'payment_status' => 'unpaid',
        ]);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.completed',
            $session,
        );

        // Assert
        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
        ]);
    }

    /**
     * Checkout Session の期限切れ時に決済状態をキャンセルにする。
     */
    public function test_expired_checkout_session_cancels_payment(): void
    {
        // Arrange
        $payment = $this->createPayment();
        $session = $this->createSession($payment);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.expired',
            $session,
        );

        // Assert
        $this->assertSame(
            PaymentStatus::Refunded,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
        ]);
    }

    /**
     * 非同期決済の失敗時に決済状態を失敗にする。
     */
    public function test_async_payment_failure_marks_payment_as_failed(): void
    {
        // Arrange
        $payment = $this->createPayment();
        $session = $this->createSession($payment);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.async_payment_failed',
            $session,
        );

        // Assert
        $this->assertSame(
            PaymentStatus::Failed,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
        ]);
    }

    /**
     * 決済済みの Payment には再通知されても回数を重複付与しない。
     */
    public function test_does_not_grant_quota_twice_when_completed_event_is_duplicated(): void
    {
        // Arrange
        $payment = $this->createPayment(
            quantity: 5,
            status: PaymentStatus::Succeeded,
        );

        MeetingQuotaTransaction::query()->create([
            'user_id' => $payment->user_id,
            'type' => MeetingQuotaTransactionType::Purchased,
            'amount' => $payment->quantity,
            'related_payment_id' => $payment->id,
            'note' => 'Stripe による面談回数の購入',
            'occurred_at' => now(),
        ]);

        $session = $this->createSession($payment, [
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_123',
        ]);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.completed',
            $session,
        );

        // Assert
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
     * Payment に紐づかない Checkout Session は処理しない。
     */
    public function test_does_nothing_when_payment_id_is_missing_from_metadata(): void
    {
        // Arrange
        $payment = $this->createPayment();
        $session = CheckoutSession::constructFrom([
            'id' => $payment->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'metadata' => [],
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_123',
        ]);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.completed',
            $session,
        );

        // Assert
        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
        ]);
    }

    /**
     * 存在しない Payment ID が指定された場合は何も変更しない。
     */
    public function test_does_nothing_when_payment_does_not_exist(): void
    {
        // Arrange
        $session = CheckoutSession::constructFrom([
            'id' => 'cs_test_unknown',
            'object' => 'checkout.session',
            'metadata' => [
                'payment_id' => '01J00000000000000000000000',
            ],
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_123',
        ]);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.completed',
            $session,
        );

        // Assert
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    /**
     * Payment に保存された Session ID と異なるイベントは処理しない。
     */
    public function test_does_nothing_when_checkout_session_id_does_not_match(): void
    {
        // Arrange
        $payment = $this->createPayment();
        $session = $this->createSession($payment, [
            'id' => 'cs_test_different',
            'payment_status' => 'paid',
        ]);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.completed',
            $session,
        );

        // Assert
        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
        ]);
    }

    /**
     * 未対応イベントでは決済状態を変更しない。
     */
    public function test_does_nothing_for_unsupported_event_type(): void
    {
        // Arrange
        $payment = $this->createPayment();
        $session = $this->createSession($payment, [
            'payment_status' => 'paid',
        ]);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.processing',
            $session,
        );

        // Assert
        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
        ]);
    }

    /**
     * 決済完了後に期限切れイベントが届いても、決済状態と付与回数を維持する。
     */
    public function test_expired_event_does_not_revert_succeeded_payment(): void
    {
        // Arrange
        $payment = $this->createPayment(
            quantity: 5,
            status: PaymentStatus::Succeeded,
        );

        MeetingQuotaTransaction::query()->create([
            'user_id' => $payment->user_id,
            'type' => MeetingQuotaTransactionType::Purchased,
            'amount' => $payment->quantity,
            'related_payment_id' => $payment->id,
            'note' => 'Stripe による面談回数の購入',
            'occurred_at' => now(),
        ]);

        $session = $this->createSession($payment);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.expired',
            $session,
        );

        // Assert
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
     * 決済完了後に決済失敗イベントが届いても、決済状態と付与回数を維持する。
     */
    public function test_async_payment_failure_does_not_revert_succeeded_payment(): void
    {
        // Arrange
        $payment = $this->createPayment(
            quantity: 5,
            status: PaymentStatus::Succeeded,
        );

        MeetingQuotaTransaction::query()->create([
            'user_id' => $payment->user_id,
            'type' => MeetingQuotaTransactionType::Purchased,
            'amount' => $payment->quantity,
            'related_payment_id' => $payment->id,
            'note' => 'Stripe による面談回数の購入',
            'occurred_at' => now(),
        ]);

        $session = $this->createSession($payment);

        // Act
        app(HandleStripeWebhookAction::class)(
            'checkout.session.async_payment_failed',
            $session,
        );

        // Assert
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
     * 面談回数の加算記録の作成に失敗した場合、決済状態の更新もロールバックする。
     */
    public function test_payment_update_is_rolled_back_when_quota_transaction_creation_fails(): void
    {
        // Arrange
        $payment = $this->createPayment(quantity: 5);
        $session = $this->createSession($payment, [
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_rollback',
        ]);

        // Act & Assert
        try {
            DB::transaction(function () use ($session): void {
                app(HandleStripeWebhookAction::class)(
                    'checkout.session.completed',
                    $session,
                );

                // 内側の処理が成功しても、外側のトランザクションを
                // ロールバックすることで、DB 更新が取り消されることを確認する。
                throw new \RuntimeException('テスト用ロールバック');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('テスト用ロールバック', $exception->getMessage());
        }

        // Assert
        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
        );

        $this->assertDatabaseMissing('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
        ]);
    }

    /**
     * テスト用の Payment を作成する。
     */
    private function createPayment(
        int $quantity = 5,
        PaymentStatus $status = PaymentStatus::Pending,
    ): Payment {
        $user = User::factory()->student()->create();

        return Payment::query()->create([
            'user_id' => $user->id,
            'meeting_pack_id' => null,
            'meeting_pack_name' => 'テスト面談パック',
            'quantity' => $quantity,
            'amount' => $quantity * 1000,
            'status' => $status,
            'stripe_checkout_session_id' => 'cs_test_'.uniqid(),
            'stripe_payment_intent_id' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * テスト用の Checkout Session を作成する。
     *
     * $overrides でイベントごとの値を上書きできる。
     *
     * @param array<string, mixed> $overrides
     */
    private function createSession(
        Payment $payment,
        array $overrides = [],
    ): CheckoutSession {
        return CheckoutSession::constructFrom(array_merge([
            'id' => $payment->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'metadata' => [
                'payment_id' => $payment->id,
            ],
            'payment_status' => 'unpaid',
            'payment_intent' => null,
        ], $overrides));
    }
}
