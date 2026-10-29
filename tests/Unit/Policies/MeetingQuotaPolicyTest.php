<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\MeetingQuotaPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingQuotaPolicy の判定を検証する Unit テスト。
 * viewHistory は本人のみ (auth.id === target.id) を網羅する。
 */
class MeetingQuotaPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_history_allowed_only_for_self(): void
    {
        // Arrange
        $self = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $policy = new MeetingQuotaPolicy;

        // Act & Assert
        $this->assertTrue($policy->viewHistory($self, $self), '本人は自分の履歴を閲覧可');
        $this->assertFalse($policy->viewHistory($self, $other), '他人の履歴は閲覧不可');
    }

    public function test_purchase_pack_allowed_for_student_in_progress(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $policy = new MeetingQuotaPolicy;

        // Act & Assert
        $this->assertTrue(
            $policy->purchasePack($student),
            '受講中の受講生は追加面談パックを購入できる',
        );
    }

    public function test_purchase_pack_denied_for_student_not_in_progress(): void
    {
        // Arrange
        $student = User::factory()->student()->invited()->create();
        $policy = new MeetingQuotaPolicy;

        // Act & Assert
        $this->assertFalse(
            $policy->purchasePack($student),
            '招待中の受講生は追加面談パックを購入できない',
        );
    }

    public function test_purchase_pack_denied_for_coach_in_progress(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $policy = new MeetingQuotaPolicy;

        // Act & Assert
        $this->assertFalse(
            $policy->purchasePack($coach),
            'コーチは購入できない',
        );
    }
}
