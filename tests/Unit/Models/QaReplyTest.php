<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaReply モデルのリレーションを検証する Unit テスト。
 * 2 リレーション (thread / user) を網羅する。
 */
class QaReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_thread_relation_returns_parent_thread(): void
    {
        // Arrange
        $thread = QaThread::factory()->create();

        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
        ]);

        // Act
        $related = $reply->thread;

        // Assert
        $this->assertTrue($related->is($thread));
    }

    public function test_user_relation_returns_reply_user(): void
    {
        // Arrange
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $reply = QaReply::factory()->create([
            'user_id' => $user->id,
        ]);

        // Act
        $related = $reply->user;

        // Assert
        $this->assertTrue($related->is($user));
    }

    public function test_user_relation_returns_soft_deleted_user(): void
    {
        // Arrange
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $reply = QaReply::factory()->create([
            'user_id' => $user->id,
        ]);

        $user->delete();

        // Act
        $related = $reply->user;

        // Assert
        $this->assertNotNull($related);
        $this->assertTrue($related->is($user));
        $this->assertTrue($related->trashed());
    }
}
