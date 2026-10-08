<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_plan_list(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->published()->count(3)->create();

        $response = $this->actingAs($admin)->get(
            route('admin.plans.index')
        );

        $response->assertOk();
        $response->assertViewIs('plan.management.index');
        $response->assertViewHas('plans');
    }

    public function test_coach_cannot_access_admin_plans_index(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->get(route('admin.plans.index'))
            ->assertForbidden();
    }

    public function test_student_cannot_access_admin_plans_index(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('admin.plans.index'))
            ->assertForbidden();
    }

    public function test_keyword_filter_matches_name_only(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->published()->create([
            'name' => 'Standard Plan',
        ]);

        Plan::factory()->published()->create([
            'name' => 'Premium Plan',
        ]);

        $response = $this->actingAs($admin)->get(
            route('admin.plans.index', ['keyword' => 'Standard'])
        );

        $response->assertOk();
        $response->assertSee('Standard Plan');
        $response->assertDontSee('Premium Plan');
    }

    public function test_status_filter_returns_only_matching_status(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->draft()->create([
            'name' => 'Draft One',
        ]);

        Plan::factory()->published()->create([
            'name' => 'Published One',
        ]);

        Plan::factory()->archived()->create([
            'name' => 'Archived One',
        ]);

        $response = $this->actingAs($admin)->get(
            route('admin.plans.index', ['status' => 'published'])
        );

        $response->assertOk();
        $response->assertSee('Published One');
        $response->assertDontSee('Draft One');
        $response->assertDontSee('Archived One');
    }

    public function test_paginates_20_per_page(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->published()->count(22)->create();

        $response = $this->actingAs($admin)->get(
            route('admin.plans.index')
        );

        $response->assertOk();

        $plans = $response->viewData('plans');

        $this->assertSame(20, $plans->perPage());
        $this->assertSame(22, $plans->total());
    }
}
