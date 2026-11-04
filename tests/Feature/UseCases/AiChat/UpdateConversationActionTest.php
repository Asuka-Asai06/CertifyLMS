<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use App\UseCases\AiChat\UpdateConversationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UpdateConversationAction のテスト。
 */
class UpdateConversationActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 会話のタイトルが更新され、自動タイトル更新が無効になることを確認する。
     */
    public function test_conversation_title_is_updated_and_auto_title_is_disabled(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'title' => '変更前のタイトル',
            'auto_title_enabled' => true,
        ]);

        $action = new UpdateConversationAction;
        $newTitle = '変更後のタイトル';

        // Act
        $result = $action($conversation, [
            'title' => $newTitle,
        ]);

        // Assert
        $this->assertSame($conversation->id, $result->id);
        $this->assertSame($newTitle, $result->title);
        $this->assertFalse($result->auto_title_enabled);

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => $newTitle,
            'auto_title_enabled' => false,
        ]);
    }

    /**
     * 自動タイトル更新がすでに無効でも、手動でタイトルを更新できることを確認する。
     */
    public function test_conversation_title_can_be_updated_when_auto_title_is_already_disabled(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'title' => '変更前のタイトル',
            'auto_title_enabled' => false,
        ]);

        $action = new UpdateConversationAction;
        $newTitle = '手動で設定したタイトル';

        // Act
        $result = $action($conversation, [
            'title' => $newTitle,
        ]);

        // Assert
        $this->assertSame($newTitle, $result->title);
        $this->assertFalse($result->auto_title_enabled);

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => $newTitle,
            'auto_title_enabled' => false,
        ]);
    }
}
