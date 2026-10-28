<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\User;
use App\Services\GeminiService;
use App\UseCases\AiChat\GenerateConversationTitleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
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

    /**
     * タイトル生成に失敗しても例外が外部に伝播せず、既存のタイトルが維持されることを確認する。
     */
    public function test_title_generation_failure_does_not_throw_or_change_title(): void
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

        $conversation->messages()->create([
            'role' => AiChatMessageRole::User->value,
            'content' => '二分探索木について教えてください。',
            'status' => AiChatMessageStatus::Completed->value,
        ]);

        $geminiService = Mockery::mock(GeminiService::class);
        $geminiService->shouldReceive('generate')
            ->once()
            ->andThrow(new RuntimeException('Gemini API error'));
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'AIチャットのタイトル生成に失敗しました。',
                Mockery::on(fn (array $context): bool => $context['conversation_id'] === $conversation->id
                    && $context['exception'] === RuntimeException::class
                ),
            );

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
