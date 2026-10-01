<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\MeetingPack;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 面談パック新規作成 StoreRequest のバリデーション検証。
 * 必須 name / meeting_count / price と、
 * description / stripe_price_id / sort_order の組合せを valid + invalid で網羅し、
 * authorize は admin のみ true を検証する。
 */
class StoreRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_valid_payload(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)->post(
            route('admin.meeting-packs.store'),
            [
                'name' => '5回面談パック',
                'description' => '面談5回分のパックです。',
                'meeting_count' => 5,
                'price' => 15000,
                'stripe_price_id' => 'price_example',
                'sort_order' => 10,
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertStatus(302);

        $this->assertDatabaseHas('meeting_packs', [
            'name' => '5回面談パック',
            'meeting_count' => 5,
            'price' => 15000,
        ]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_fails(array $overrides, string $expectedErrorField): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $payload = array_merge([
            'name' => '5回面談パック',
            'description' => '面談5回分のパックです。',
            'meeting_count' => 5,
            'price' => 15000,
            'stripe_price_id' => null,
            'sort_order' => 0,
        ], $overrides);

        // Act
        $response = $this->actingAs($admin)->postJson(
            route('admin.meeting-packs.store'),
            $payload,
        );

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors($expectedErrorField);
    }

    public function test_authorize_returns_false_for_non_admin(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create();

        // Act
        $response = $this->actingAs($coach)->postJson(
            route('admin.meeting-packs.store'),
            [
                'name' => '5回面談パック',
                'description' => '面談5回分のパックです。',
                'meeting_count' => 5,
                'price' => 15000,
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
            'meeting_count 未指定で 422' => [
                ['meeting_count' => ''],
                'meeting_count',
            ],
            'meeting_count 0 で 422' => [
                ['meeting_count' => 0],
                'meeting_count',
            ],
            'meeting_count 101 で 422' => [
                ['meeting_count' => 101],
                'meeting_count',
            ],
            'meeting_count 文字列で 422' => [
                ['meeting_count' => 'abc'],
                'meeting_count',
            ],
            'price 未指定で 422' => [
                ['price' => ''],
                'price',
            ],
            'price マイナス値で 422' => [
                ['price' => -1],
                'price',
            ],
            'price 1000001 で 422' => [
                ['price' => 1000001],
                'price',
            ],
            'price 文字列で 422' => [
                ['price' => 'abc'],
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
