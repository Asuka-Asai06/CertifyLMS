<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\MeetingQuotaTransactionType;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payment モデルのリレーションを検証する Unit テスト。
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 購入した受講生を取得できる。
     */
    public function test_user_relation_returns_student(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        $payment = Payment::factory()->create([
            'user_id' => $student->id,
        ]);

        // Act
        $user = $payment->user;

        // Assert
        $this->assertTrue($user->is($student));
    }

    /**
     * 購入元の面談パックを取得できる。
     */
    public function test_meeting_pack_relation_returns_purchased_pack(): void
    {
        // Arrange
        $pack = MeetingPack::factory()->published()->create();

        $payment = Payment::factory()->create([
            'meeting_pack_id' => $pack->id,
        ]);

        // Act
        $meetingPack = $payment->meetingPack;

        // Assert
        $this->assertNotNull($meetingPack);
        $this->assertTrue($meetingPack->is($pack));
    }

    /**
     * この購入に紐づく面談回数の取引履歴を取得できる。
     */
    public function test_quota_transactions_relation_returns_related_transactions(): void
    {
        // Arrange
        $student = User::factory()->student()->create();

        $payment = Payment::factory()->create([
            'user_id' => $student->id,
        ]);

        $transaction = MeetingQuotaTransaction::query()->create([
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Purchased,
            'amount' => 5,
            'related_payment_id' => $payment->id,
            'note' => 'Stripe による面談回数の購入',
            'occurred_at' => now(),
        ]);

        // Act
        $transactions = $payment->quotaTransactions;

        // Assert
        $this->assertCount(1, $transactions);
        $this->assertTrue($transactions->first()->is($transaction));
    }
}
