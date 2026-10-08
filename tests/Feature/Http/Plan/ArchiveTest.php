<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_archives_published_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $response = $this->actingAs($admin)->post(
            route('admin.plans.archive', $plan)
        );

        $response->assertRedirect(
            route('admin.plans.show', $plan)
        );

        $this->assertSame(
            'archived',
            $plan->fresh()->status->value
        );
    }

    public function test_cannot_archive_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)->postJson(
            route('admin.plans.archive', $plan)
        );

        $response->assertStatus(409);
    }
}
