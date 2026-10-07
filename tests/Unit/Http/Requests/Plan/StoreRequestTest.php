<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Plan;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * プラン新規作成 StoreRequest のバリデーション検証。
 * 必須 name / duration_days / default_meeting_quota、
 * description / sort_order の組み合わせを valid + invalid で網羅し、
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
            route('admin.plans.store'),
            [
                'name' => 'スタンダードプラン',
                'description' => '標準的な受講プランです。',
                'duration_days' => 365,
                'default_meeting_quota' => 10,
                'sort_order' => 1,
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertStatus(302);
        $this->assertDatabaseHas('plans', [
            'name' => 'スタンダードプラン',
            'duration_days' => 365,
            'default_meeting_quota' => 10,
            'sort_order' => 1,
        ]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_fails(
        array $overrides,
        string $expectedErrorField
    ): void {
        // Arrange
        $admin = User::factory()->admin()->create();
        $payload = array_merge([
            'name' => 'スタンダードプラン',
            'duration_days' => 365,
            'default_meeting_quota' => 10,
        ], $overrides);

        // Act
        $response = $this->actingAs($admin)->postJson(
            route('admin.plans.store'),
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
            route('admin.plans.store'),
            [
                'name' => 'スタンダードプラン',
                'duration_days' => 365,
                'default_meeting_quota' => 10,
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
            'duration_days 未指定で 422' => [
                ['duration_days' => ''],
                'duration_days',
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
            'default_meeting_quota 未指定で 422' => [
                ['default_meeting_quota' => ''],
                'default_meeting_quota',
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
