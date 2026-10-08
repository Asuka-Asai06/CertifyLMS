<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => '新規プラン',
            'description' => 'プランの説明文',
            'duration_days' => 365,
            'default_meeting_quota' => 10,
            'sort_order' => 1,
        ], $override);
    }

    public function test_admin_can_create_plan_as_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payload();

        $response = $this->actingAs($admin)->post(
            route('admin.plans.store'),
            $payload
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('plans', [
            'name' => '新規プラン',
            'status' => 'draft',
            'duration_days' => 365,
            'default_meeting_quota' => 10,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_required_fields_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(
                route('admin.plans.store'),
                $this->payload(['name' => ''])
            )
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->post(
                route('admin.plans.store'),
                $this->payload(['duration_days' => ''])
            )
            ->assertSessionHasErrors('duration_days');

        $this->actingAs($admin)
            ->post(
                route('admin.plans.store'),
                $this->payload(['default_meeting_quota' => ''])
            )
            ->assertSessionHasErrors('default_meeting_quota');
    }

    public function test_coach_cannot_create_plan(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->post(
            route('admin.plans.store'),
            $this->payload()
        );

        $response->assertForbidden();
    }
}
