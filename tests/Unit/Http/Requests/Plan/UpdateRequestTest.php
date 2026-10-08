<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * プラン更新 UpdateRequest のバリデーション検証。
 * Store と同じルールを持つが、route('plan') の解決を含む authorize の
 * admin 通過 / non-admin 不通過を検証する。
 */
class UpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_valid_payload(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();

        // Act
        $response = $this->actingAs($admin)->put(
            route('admin.plans.update', $plan),
            [
                'name' => '更新後のプラン名',
                'description' => '更新後の説明',
                'duration_days' => 365,
                'default_meeting_quota' => 20,
                'sort_order' => 2,
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => '更新後のプラン名',
        ]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_fails(
        array $overrides,
        string $expectedErrorField
    ): void {
        // Arrange
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();

        $payload = array_merge([
            'name' => 'プラン名',
            'description' => '説明',
            'duration_days' => 365,
            'default_meeting_quota' => 10,
            'sort_order' => 1,
        ], $overrides);

        // Act
        $response = $this->actingAs($admin)->putJson(
            route('admin.plans.update', $plan),
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
        $plan = Plan::factory()->create();

        // Act
        $response = $this->actingAs($coach)->putJson(
            route('admin.plans.update', $plan),
            [
                'name' => '上書き',
                'description' => '説明',
                'duration_days' => 365,
                'default_meeting_quota' => 10,
                'sort_order' => 1,
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
            'duration_days 0 で 422' => [
                ['duration_days' => 0],
                'duration_days',
            ],
            'duration_days 3651 で 422' => [
                ['duration_days' => 3651],
                'duration_days',
            ],
            'duration_days 文字列で 422' => [
                ['duration_days' => 'abc'],
                'duration_days',
            ],
            'default_meeting_quota 1001 で 422' => [
                ['default_meeting_quota' => 1001],
                'default_meeting_quota',
            ],
            'default_meeting_quota 文字列で 422' => [
                ['default_meeting_quota' => 'abc'],
                'default_meeting_quota',
            ],
            'sort_order 負数で 422' => [
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
