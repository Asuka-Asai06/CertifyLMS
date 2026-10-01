<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

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
            'name' => '5回面談パック',
            'description' => '面談5回分のパックです。',
            'meeting_count' => 5,
            'price' => 15000,
            'stripe_price_id' => null,
            'sort_order' => 0,
        ], $override);
    }

    public function test_admin_can_create_meeting_pack_as_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payload();

        $response = $this->actingAs($admin)->post(
            route('admin.meeting-packs.store'),
            $payload,
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('meeting_packs', [
            'name' => '5回面談パック',
            'status' => 'draft',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_required_fields_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(
                route('admin.meeting-packs.store'),
                $this->payload(['name' => '']),
            )
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->post(
                route('admin.meeting-packs.store'),
                $this->payload(['meeting_count' => '']),
            )
            ->assertSessionHasErrors('meeting_count');

        $this->actingAs($admin)
            ->post(
                route('admin.meeting-packs.store'),
                $this->payload(['price' => '']),
            )
            ->assertSessionHasErrors('price');
    }

    public function test_coach_cannot_create_meeting_pack(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->post(
            route('admin.meeting-packs.store'),
            $this->payload(),
        );

        $response->assertForbidden();
    }
}
