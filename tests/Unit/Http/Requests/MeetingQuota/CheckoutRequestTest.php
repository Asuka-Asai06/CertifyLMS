<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\MeetingQuota;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 追加面談パック購入 CheckoutRequest の検証。
 *
 * meeting_pack_id は公開中の面談パックのみ許可し、
 * 購入権限は受講中の受講生のみ許可する。
 */
class CheckoutRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_published_meeting_pack(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $pack = $this->createMeetingPack(MeetingPackStatus::Published);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('meeting-quota.checkout.create'),
            ['meeting_pack_id' => $pack->id],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect();
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_fails(
        array $overrides,
        string $expectedErrorField,
    ): void {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $pack = $this->createMeetingPack(MeetingPackStatus::Published);

        $payload = array_merge([
            'meeting_pack_id' => $pack->id,
        ], $overrides);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('meeting-quota.checkout.create'),
            $payload,
        );

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors($expectedErrorField);
    }

    public function test_authorize_returns_false_for_coach(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $pack = $this->createMeetingPack(MeetingPackStatus::Published);

        // Act
        $response = $this->actingAs($coach)->postJson(
            route('meeting-quota.checkout.create'),
            ['meeting_pack_id' => $pack->id],
        );

        // Assert
        $response->assertForbidden();
    }

    public function test_authorize_returns_false_for_student_not_in_progress(): void
    {
        // Arrange
        $student = User::factory()->student()->invited()->create();
        $pack = $this->createMeetingPack(MeetingPackStatus::Published);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('meeting-quota.checkout.create'),
            ['meeting_pack_id' => $pack->id],
        );

        // Assert
        $response->assertForbidden();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'meeting_pack_id 未指定' => [
                ['meeting_pack_id' => ''],
                'meeting_pack_id',
            ],
            '存在しない ID' => [
                ['meeting_pack_id' => '01J00000000000000000000000'],
                'meeting_pack_id',
            ],
        ];
    }

    public function test_draft_meeting_pack_cannot_be_purchased(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $pack = $this->createMeetingPack(MeetingPackStatus::Draft);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('meeting-quota.checkout.create'),
            ['meeting_pack_id' => $pack->id],
        );

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('meeting_pack_id');
    }

    public function test_archived_meeting_pack_cannot_be_purchased(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $pack = $this->createMeetingPack(MeetingPackStatus::Archived);

        // Act
        $response = $this->actingAs($student)->postJson(
            route('meeting-quota.checkout.create'),
            ['meeting_pack_id' => $pack->id],
        );

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('meeting_pack_id');
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
}
