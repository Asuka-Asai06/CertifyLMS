<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Plan\PublishAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishes_draft_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $result = (new PublishAction)($plan, $admin);

        $this->assertSame(PlanStatus::Published, $result->status);
        $this->assertSame($admin->id, $result->updated_by_user_id);
    }

    public function test_throws_when_already_published(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $this->expectException(PlanInvalidTransitionException::class);

        (new PublishAction)($plan, $admin);
    }

    public function test_throws_when_archived(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->archived()->create();

        $this->expectException(PlanInvalidTransitionException::class);

        (new PublishAction)($plan, $admin);
    }
}
