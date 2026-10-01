<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_archives_published_meeting_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($admin)->post(
            route('admin.meeting-packs.archive', $plan),
        );

        $response->assertRedirect(
            route('admin.meeting-packs.show', $plan),
        );

        $this->assertSame(
            'archived',
            $plan->fresh()->status->value,
        );
    }

    public function test_cannot_archive_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)->postJson(
            route('admin.meeting-packs.archive', $plan),
        );

        $response->assertStatus(409);
    }
}
