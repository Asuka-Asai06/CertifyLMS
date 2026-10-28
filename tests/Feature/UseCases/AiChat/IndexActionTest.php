<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use App\UseCases\AiChat\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IndexAction のテスト。
 */
class IndexActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 会話一覧とデフォルト受講登録が返されることを確認する。
     */
    public function test_conversations_and_default_enrollment_are_returned(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        $action = new IndexAction;

        // Act
        $result = $action($student);

        // Assert
        $this->assertArrayHasKey('conversations', $result);
        $this->assertArrayHasKey('defaultEnrollment', $result);

        $this->assertTrue(
            $result['conversations']->contains('id', $conversation->id),
        );

        $this->assertNull($result['defaultEnrollment']);
    }

    /**
     * 会話が最終メッセージ日時の降順で取得されることを確認する。
     */
    public function test_conversations_are_ordered_by_last_message_at_descending(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $olderConversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'last_message_at' => now()->subDays(2),
            ]);

        $newerConversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'last_message_at' => now()->subDay(),
            ]);

        $latestConversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'last_message_at' => now(),
            ]);

        $action = new IndexAction;

        // Act
        $result = $action($student);

        // Assert
        $this->assertSame(
            [
                $latestConversation->id,
                $newerConversation->id,
                $olderConversation->id,
            ],
            $result['conversations']->pluck('id')->all(),
        );
    }

    /**
     * 会話が存在しない場合、空の会話一覧が返されることを確認する。
     */
    public function test_empty_collection_is_returned_when_student_has_no_conversations(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $action = new IndexAction;

        // Act
        $result = $action($student);

        // Assert
        $this->assertCount(0, $result['conversations']);
        $this->assertNull($result['defaultEnrollment']);
    }

    /**
     * 会話一覧が最大30件に制限されることを確認する。
     */
    public function test_conversations_are_limited_to_30_records(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        AiChatConversation::factory()
            ->count(31)
            ->for($student, 'user')
            ->create();

        $action = new IndexAction;

        // Act
        $result = $action($student);

        // Assert
        $this->assertCount(30, $result['conversations']);
    }
}
