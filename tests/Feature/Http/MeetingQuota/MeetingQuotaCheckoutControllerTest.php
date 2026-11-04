<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\MeetingPackStatus;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 追加面談パック購入 Controller の HTTP 統合テスト。
 *
 * - 購入可能な面談パック一覧の表示
 * - 購入権限によるアクセス制限
 * - CheckoutRequest のバリデーション
 * - 決済完了画面の表示とアクセス制御
 *
 * Stripe API を呼び出す store() の正常系は対象外とする。
 */
class MeetingQuotaCheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_published_meeting_packs_for_student(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $publishedPack = $this->createMeetingPack(MeetingPackStatus::Published);
        $draftPack = $this->createMeetingPack(MeetingPackStatus::Draft);
        $archivedPack = $this->createMeetingPack(MeetingPackStatus::Archived);

        // Act
        $response = $this->actingAs($student)
            ->get(route('meeting-quota.checkout.select'));

        // Assert
        $response->assertOk();
        $response->assertViewIs('meeting-quota.checkout-select');
        $response->assertViewHas('plans', function ($plans) use (
            $publishedPack,
            $draftPack,
            $archivedPack,
        ): bool {
            $ids = $plans->pluck('id');

            return $ids->contains($publishedPack->id)
                && ! $ids->contains($draftPack->id)
                && ! $ids->contains($archivedPack->id);
        });
    }

    public function test_store_returns_validation_error_when_pack_id_is_missing(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($student)
            ->postJson(route('meeting-quota.checkout.create'), []);

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['meeting_pack_id']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_store_rejects_draft_meeting_pack(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $pack = $this->createMeetingPack(MeetingPackStatus::Draft);

        // Act
        $response = $this->actingAs($student)
            ->postJson(route('meeting-quota.checkout.create'), [
                'meeting_pack_id' => $pack->id,
            ]);

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['meeting_pack_id']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_store_rejects_archived_meeting_pack(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $pack = $this->createMeetingPack(MeetingPackStatus::Archived);

        // Act
        $response = $this->actingAs($student)
            ->postJson(route('meeting-quota.checkout.create'), [
                'meeting_pack_id' => $pack->id,
            ]);

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['meeting_pack_id']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_coach_cannot_access_checkout_pages_or_create_checkout(): void
    {
        // Arrange
        $coach = User::factory()->coach()->inProgress()->create();
        $pack = $this->createMeetingPack(MeetingPackStatus::Published);

        // Act & Assert
        $this->actingAs($coach)
            ->get(route('meeting-quota.checkout.select'))
            ->assertForbidden();

        $this->actingAs($coach)
            ->postJson(route('meeting-quota.checkout.create'), [
                'meeting_pack_id' => $pack->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_student_who_is_not_in_progress_cannot_access_checkout(): void
    {
        // Arrange
        $student = User::factory()->student()->invited()->create();
        $pack = $this->createMeetingPack(MeetingPackStatus::Published);

        // Act & Assert
        $this->actingAs($student)
            ->get(route('meeting-quota.checkout.select'))
            ->assertForbidden();

        $this->actingAs($student)
            ->postJson(route('meeting-quota.checkout.create'), [
                'meeting_pack_id' => $pack->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        // Arrange
        $pack = $this->createMeetingPack(MeetingPackStatus::Published);

        // Act & Assert
        $this->get(route('meeting-quota.checkout.select'))
            ->assertRedirect(route('login'));

        $this->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $pack->id,
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_success_displays_payment_belonging_to_authenticated_student(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $payment = $this->createPayment($student, 'cs_test_student_payment');
        $this->createPayment($otherStudent, 'cs_test_other_payment');

        // Act
        $response = $this->actingAs($student)
            ->get(route('meeting-quota.success', [
                'session_id' => 'cs_test_student_payment',
            ]));

        // Assert
        $response->assertOk();
        $response->assertViewIs('meeting-quota.success');
        $response->assertViewHas('payment', function ($result) use ($payment): bool {
            return $result !== null && $result->id === $payment->id;
        });
    }

    public function test_success_redirects_to_history_when_session_id_is_missing(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($student)
            ->get(route('meeting-quota.success'));

        // Assert
        $response->assertRedirect(route('meeting-quota.history'));
        $response->assertSessionHas('error', '決済情報を確認できませんでした。');
    }

    public function test_success_does_not_show_another_students_payment(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $this->createPayment($otherStudent, 'cs_test_other_payment');

        // Act
        $response = $this->actingAs($student)
            ->get(route('meeting-quota.success', [
                'session_id' => 'cs_test_other_payment',
            ]));

        // Assert
        $response->assertOk();
        $response->assertViewIs('meeting-quota.success');
        $response->assertViewHas('payment', null);
    }

    public function test_unauthenticated_user_cannot_access_success_page(): void
    {
        // Act & Assert
        $this->get(route('meeting-quota.success', [
            'session_id' => 'cs_test_example',
        ]))->assertRedirect(route('login'));
    }

    private function createMeetingPack(
        MeetingPackStatus $status,
    ): MeetingPack {
        $admin = User::factory()->admin()->create();

        return MeetingPack::factory()
            ->state([
                'status' => $status->value,
                'created_by_user_id' => $admin->id,
                'updated_by_user_id' => $admin->id,
            ])
            ->create();
    }

    private function createPayment(
        User $student,
        string $sessionId,
    ): Payment {
        $pack = $this->createMeetingPack(MeetingPackStatus::Published);

        return Payment::query()->create([
            'user_id' => $student->id,
            'meeting_pack_id' => $pack->id,
            'meeting_pack_name' => $pack->name,
            'quantity' => $pack->meeting_count,
            'amount' => $pack->price,
            'status' => PaymentStatus::Succeeded,
            'stripe_checkout_session_id' => $sessionId,
            'stripe_payment_intent_id' => 'pi_'.$sessionId,
            'paid_at' => now(),
        ]);
    }
}
