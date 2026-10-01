<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 面談パック更新 UpdateRequest のバリデーション検証。
 * Store と同じルールを持つが route('plan') の解決を含む
 * authorize の admin 通過 / non-admin 不通過を検証する。
 */
class UpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_valid_payload(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        // Act
        $response = $this->actingAs($admin)->put(
            route('admin.meeting-packs.update', $plan),
            [
                'name' => '更新後の面談パック',
                'description' => '更新後の説明',
                'meeting_count' => 10,
                'price' => 30000,
                'stripe_price_id' => 'price_updated',
                'sort_order' => 10,
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $plan->id,
            'name' => '更新後の面談パック',
        ]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_fails(
        array $overrides,
        string $expectedErrorField,
    ): void {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        $payload = array_merge([
            'name' => '面談パック',
            'description' => '説明',
            'meeting_count' => 5,
            'price' => 15000,
            'stripe_price_id' => null,
            'sort_order' => 0,
        ], $overrides);

        // Act
        $response = $this->actingAs($admin)->putJson(
            route('admin.meeting-packs.update', $plan),
            $payload,
        );

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors($expectedErrorField);
    }

    public function test_authorize_returns_false_for_coach(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();
        $plan = MeetingPack::factory()->published()->create();

        // Act
        $response = $this->actingAs($coach)->putJson(
            route('admin.meeting-packs.update', $plan),
            [
                'name' => '上書き',
                'description' => '不正な更新',
                'meeting_count' => 10,
                'price' => 30000,
                'stripe_price_id' => null,
                'sort_order' => 0,
            ],
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
            'name 未指定で 422' => [
                ['name' => ''],
                'name',
            ],
            'name 101 文字で 422' => [
                ['name' => str_repeat('a', 101)],
                'name',
            ],
            'description 2001 文字で 422' => [
                ['description' => str_repeat('b', 2001)],
                'description',
            ],
            'meeting_count 0 で 422' => [
                ['meeting_count' => 0],
                'meeting_count',
            ],
            'meeting_count 101 で 422' => [
                ['meeting_count' => 101],
                'meeting_count',
            ],
            'price マイナス値で 422' => [
                ['price' => -1],
                'price',
            ],
            'price 1000001 で 422' => [
                ['price' => 1000001],
                'price',
            ],
            'stripe_price_id 256 文字で 422' => [
                ['stripe_price_id' => str_repeat('p', 256)],
                'stripe_price_id',
            ],
            'sort_order マイナス値で 422' => [
                ['sort_order' => -1],
                'sort_order',
            ],
            'sort_order 文字列で 422' => [
                ['sort_order' => 'abc'],
                'sort_order',
            ],
        ];
    }
}
