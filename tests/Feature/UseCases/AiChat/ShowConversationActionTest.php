<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use App\UseCases\AiChat\ShowConversationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ShowConversationAction のテスト。
 */
class ShowConversationActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 会話と、その会話に紐づくメッセージを取得できることを確認する。
     */
    public function test_conversation_and_messages_are_loaded(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        $message = $conversation->messages()->create([
            'role' => 'user',
            'content' => '二分探索木について教えてください。',
            'status' => 'completed',
        ]);

        $action = new ShowConversationAction;

        // Act
        $result = $action($conversation);

        // Assert
        $this->assertSame($conversation->id, $result->id);
        $this->assertTrue($result->relationLoaded('enrollment'));
        $this->assertTrue($result->relationLoaded('section'));
        $this->assertTrue($result->relationLoaded('messages'));
        $this->assertCount(1, $result->messages);
        $this->assertSame($message->id, $result->messages->first()->id);
    }

    /**
     * メッセージがない会話でも、空のメッセージコレクションを取得できることを確認する。
     */
    public function test_empty_messages_collection_is_loaded(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        $action = new ShowConversationAction;

        // Act
        $result = $action($conversation);

        // Assert
        $this->assertTrue($result->relationLoaded('messages'));
        $this->assertCount(0, $result->messages);
    }
}
