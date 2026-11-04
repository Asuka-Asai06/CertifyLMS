<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\GeminiService;
use App\UseCases\AiChat\SendMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SendMessageAction のテスト。
 *
 * 日次送信上限に達した場合の処理を確認する。
 */
class SendMessageActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 日次送信上限に達している場合、例外が発生し、
     * 新しい質問が保存されないことを確認する。
     */
    public function test_sending_message_fails_when_daily_limit_is_reached(): void
    {
        // Arrange
        $dailyLimit = 20;

        config([
            'ai-chat.daily_limit' => $dailyLimit,
        ]);

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        AiChatMessage::factory()
            ->count($dailyLimit)
            ->userMessage()
            ->create([
                'conversation_id' => $conversation->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $messagesBefore = AiChatMessage::query()->count();

        // Gemini API は日次上限の確認後に呼び出されるため、
        // このテストでは API のモックは不要。
        $geminiService = app(GeminiService::class);

        $action = app(SendMessageAction::class);

        // Assert
        try {
            // Act
            $action(
                $conversation,
                ['content' => '今日の質問です'],
                $geminiService,
            );

            $this->fail(
                'AiChatDailyLimitExceededException が発生する想定です。',
            );
        } catch (AiChatDailyLimitExceededException) {
            // 上限超過時は質問が新規保存されないことを確認する。
            $this->assertSame(
                $messagesBefore,
                AiChatMessage::query()->count(),
            );
        }
    }
}
