<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Announcement;
use App\Models\User;
use App\Policies\AnnouncementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_announcement_list_create_and_view_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $announcement = Announcement::factory()->create();
        $policy = new AnnouncementPolicy;

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->create($admin));
        $this->assertTrue($policy->view($admin, $announcement));
    }

    public function test_coach_and_student_cannot_manage_announcements(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $announcement = Announcement::factory()->create();
        $policy = new AnnouncementPolicy;

        foreach ([$coach, $student] as $user) {
            $this->assertFalse($policy->viewAny($user));
            $this->assertFalse($policy->create($user));
            $this->assertFalse($policy->view($user, $announcement));
        }
    }
}
