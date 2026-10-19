<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Announcement モデルのリレーションを検証する Unit テスト。
 * targetCertification / targetUser / createdBy の 3 リレーションを網羅する。
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_certification_relation_returns_target_certification(): void
    {
        // Arrange
        $certification = Certification::factory()->create();

        $announcement = Announcement::factory()->create([
            'target_certification_id' => $certification->id,
        ]);

        // Act
        $related = $announcement->targetCertification;

        // Assert
        $this->assertNotNull($related);
        $this->assertTrue($related->is($certification));
    }

    public function test_target_user_relation_returns_target_user(): void
    {
        // Arrange
        $user = User::factory()->create();

        $announcement = Announcement::factory()->create([
            'target_user_id' => $user->id,
        ]);

        // Act
        $related = $announcement->targetUser;

        // Assert
        $this->assertNotNull($related);
        $this->assertTrue($related->is($user));
    }

    public function test_created_by_relation_returns_creator(): void
    {
        // Arrange
        $creator = User::factory()->create();

        $announcement = Announcement::factory()->create([
            'created_by' => $creator->id,
        ]);

        // Act
        $related = $announcement->createdBy;

        // Assert
        $this->assertNotNull($related);
        $this->assertTrue($related->is($creator));
    }
}
