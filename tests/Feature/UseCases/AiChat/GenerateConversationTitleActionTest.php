<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use App\Services\GeminiService;
use App\UseCases\AiChat\GenerateConversationTitleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * GenerateConversationTitleAction のテスト。
 */
class GenerateConversationTitleActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 自動タイトル生成が無効な場合、Gemini を呼び出さないことを確認する。
     */
    public function test_title_is_not_generated_when_auto_title_is_disabled(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'title' => '手動設定したタイトル',
                'auto_title_enabled' => false,
            ]);

        $geminiService = Mockery::mock(GeminiService::class);
        $geminiService->shouldNotReceive('generate');

        $action = new GenerateConversationTitleAction;

        // Act
        $action($conversation, $geminiService);

        // Assert
        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => '手動設定したタイトル',
            'auto_title_enabled' => false,
        ]);
    }

    /**
     * 完了済みメッセージがない場合、Gemini を呼び出さないことを確認する。
     */
    public function test_title_is_not_generated_when_there_are_no_messages(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'title' => '新しい会話',
                'auto_title_enabled' => true,
            ]);

        $geminiService = Mockery::mock(GeminiService::class);
        $geminiService->shouldNotReceive('generate');

        $action = new GenerateConversationTitleAction;

        // Act
        $action($conversation, $geminiService);

        // Assert
        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => '新しい会話',
            'auto_title_enabled' => true,
        ]);
    }
}
