<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_plan(): void
    {
        $admin = User::factory()->admin()->create();

        $plan = Plan::factory()->draft()->create([
            'name' => 'Old Name',
            'duration_days' => 30,
            'default_meeting_quota' => 5,
        ]);

        $payload = [
            'name' => 'New Name',
            'description' => '更新後の説明',
            'duration_days' => 365,
            'default_meeting_quota' => 10,
            'sort_order' => 2,
        ];

        $response = $this->actingAs($admin)->put(
            route('admin.plans.update', $plan),
            $payload
        );

        $response->assertRedirect(
            route('admin.plans.show', $plan)
        );

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => 'New Name',
            'description' => '更新後の説明',
            'duration_days' => 365,
            'default_meeting_quota' => 10,
            'sort_order' => 2,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_status_is_unchanged_even_if_payload_includes_status(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $payload = [
            'name' => $plan->name,
            'description' => $plan->description,
            'duration_days' => $plan->duration_days,
            'default_meeting_quota' => $plan->default_meeting_quota,
            'sort_order' => $plan->sort_order,
            'status' => 'draft',
        ];

        $this->actingAs($admin)->put(
            route('admin.plans.update', $plan),
            $payload
        );

        $this->assertSame(
            'published',
            $plan->fresh()->status->value
        );
    }

    public function test_coach_cannot_update(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($coach)->put(
            route('admin.plans.update', $plan),
            [
                'name' => 'Hack',
                'duration_days' => 365,
                'default_meeting_quota' => 10,
            ]
        );

        $response->assertForbidden();
    }
}
