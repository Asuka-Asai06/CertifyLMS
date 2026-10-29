<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingPackStatus;
use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;

final class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $fixedStudent = User::query()
            ->where('email', 'student@certify-lms.test')
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->first();

        $demoStudent = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->whereNotIn('email', [
                'student@certify-lms.test',
                'student-noquota@certify-lms.test',
            ])
            ->orderBy('email')
            ->first();

        if ($fixedStudent === null || $demoStudent === null) {
            $this->command?->warn(
                'PaymentSeeder: 固定受講生またはデモ受講生が存在しません。先に UserSeeder を実行してください。',
            );

            return;
        }

        $packs = MeetingPack::query()
            ->where('status', MeetingPackStatus::Published->value)
            ->get()
            ->keyBy('meeting_count');

        foreach ([$fixedStudent, $demoStudent] as $student) {
            $this->seedPayment(
                $student,
                $packs->get(1),
                'succeeded',
                PaymentStatus::Succeeded,
                1,
            );

            $this->seedPayment(
                $student,
                $packs->get(5),
                'pending',
                PaymentStatus::Pending,
                5,
            );

            $this->seedPayment(
                $student,
                $packs->get(1),
                'failed',
                PaymentStatus::Failed,
                1,
            );

            $this->seedPayment(
                $student,
                $packs->get(10),
                'refunded',
                PaymentStatus::Refunded,
                10,
            );
        }
    }

    private function seedPayment(
        User $student,
        ?MeetingPack $pack,
        string $key,
        PaymentStatus $status,
        int $daysAgo,
    ): void {
        if ($pack === null) {
            $this->command?->warn(
                "PaymentSeeder: {$key} 用の公開面談パックが見つかりません。",
            );

            return;
        }

        // 実際のStripe決済とは区別できる、Seeder専用のIDを使う。
        $sessionId = "cs_test_seed_{$student->id}_{$key}";

        $payment = Payment::query()->firstOrCreate(
            ['stripe_checkout_session_id' => $sessionId],
            [
                'user_id' => $student->id,
                'meeting_pack_id' => $pack->id,
                'meeting_pack_name' => $pack->name,
                'quantity' => $pack->meeting_count,
                'amount' => $pack->price,
                'status' => $status,
                'stripe_payment_intent_id' => $status === PaymentStatus::Succeeded
                    ? "pi_seed_{$student->id}_{$key}"
                    : null,
                'paid_at' => $status === PaymentStatus::Succeeded
                    ? now()->subDays($daysAgo)
                    : null,
            ],
        );

        // 完了済み購入だけを残数計算の対象にする。
        if ($payment->status !== PaymentStatus::Succeeded) {
            return;
        }

        // Seederの再実行で購入回数を二重加算しない。
        $alreadyRecorded = $payment->quotaTransactions()
            ->where('type', MeetingQuotaTransactionType::Purchased->value)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        MeetingQuotaTransaction::query()->create([
            'user_id' => $payment->user_id,
            'type' => MeetingQuotaTransactionType::Purchased,
            'amount' => $payment->quantity,
            'related_payment_id' => $payment->id,
            'note' => 'Seeder: 追加面談パック購入',
            'occurred_at' => $payment->paid_at ?? now(),
        ]);
    }
}
