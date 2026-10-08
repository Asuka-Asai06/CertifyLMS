<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Plan;

use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Plan\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_draft_plan(): void
    {
        $plan = Plan::factory()->draft()->create();

        (new DestroyAction)($plan);

        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    public function test_throws_when_published(): void
    {
        $plan = Plan::factory()->published()->create();

        $this->expectException(PlanNotDeletableException::class);

        (new DestroyAction)($plan);
    }

    public function test_throws_when_archived(): void
    {
        $plan = Plan::factory()->archived()->create();

        $this->expectException(PlanNotDeletableException::class);

        (new DestroyAction)($plan);
    }

    public function test_throws_when_users_are_linked(): void
    {
        $plan = Plan::factory()->draft()->create();
        User::factory()->create(['plan_id' => $plan->id]);

        $this->expectException(PlanNotDeletableException::class);

        (new DestroyAction)($plan);
    }
}
