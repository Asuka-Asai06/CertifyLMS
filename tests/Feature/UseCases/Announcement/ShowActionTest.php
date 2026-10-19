<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Announcement;

use App\Models\Announcement;
use App\UseCases\Announcement\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Announcement 詳細取得 Action `ShowAction` の eager load を検証する Feature テスト。
 *
 * 詳細画面に必要な対象資格・対象ユーザー・配信者が
 * eager load されることを確認する。
 */
class ShowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_target_certification_for_detail_view(): void
    {
        // Arrange
        $announcement = Announcement::factory()->create();

        // Act
        $result = app(ShowAction::class)($announcement);

        // Assert
        $this->assertTrue(
            $result->relationLoaded('targetCertification'),
            'ShowAction は詳細表示用に targetCertification を eager load するはず',
        );
    }

    public function test_loads_target_user_for_detail_view(): void
    {
        // Arrange
        $announcement = Announcement::factory()->forUser(
            \App\Models\User::factory()->student()->inProgress()->create(),
        )->create();

        // Act
        $result = app(ShowAction::class)($announcement);

        // Assert
        $this->assertTrue(
            $result->relationLoaded('targetUser'),
            'ShowAction は詳細表示用に targetUser を eager load するはず',
        );
    }

    public function test_loads_created_by_for_detail_view(): void
    {
        // Arrange
        $announcement = Announcement::factory()->create();

        // Act
        $result = app(ShowAction::class)($announcement);

        // Assert
        $this->assertTrue(
            $result->relationLoaded('createdBy'),
            'ShowAction は詳細表示用に createdBy を eager load するはず',
        );
    }
}