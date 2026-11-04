<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\GeminiService;
use App\UseCases\AiChat\SendMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SendMessageAction のテスト。
 *
 * メッセージ送信、Gemini API との連携、
 * 日次送信上限および API エラー発生時の挙動を確認する。
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

    /**
     * Gemini API から回答を取得し、
     * 質問と回答が正常に保存されることを確認する。
     *
     * @group external-api
     */
    public function test_sending_message_saves_user_message_and_assistant_answer(): void
    {
        // Arrange
        config([
            'ai-chat.daily_limit' => 20,
            'ai-chat.history_limit' => 20,
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'これはテスト回答です。'],
                            ],
                        ],
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 8,
                ],
            ], 200),
        ]);

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'auto_title_enabled' => false,
            ]);

        $action = app(SendMessageAction::class);
        $geminiService = app(GeminiService::class);

        // Act
        $result = $action(
            $conversation,
            ['content' => 'テスト質問です。'],
            $geminiService,
        );

        // Assert
        $this->assertTrue($result['success']);

        $this->assertSame(
            'テスト質問です。',
            $result['user_message']->content,
        );

        $this->assertSame(
            'これはテスト回答です。',
            $result['assistant_message']->content,
        );

        $this->assertDatabaseHas('ai_chat_messages', [
            'id' => $result['user_message']->id,
            'role' => 'user',
            'content' => 'テスト質問です。',
            'status' => AiChatMessageStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'id' => $result['assistant_message']->id,
            'role' => 'assistant',
            'content' => 'これはテスト回答です。',
            'status' => AiChatMessageStatus::Completed->value,
            'model_name' => 'gemini-3.8-flash',
            'input_tokens' => 10,
            'output_tokens' => 8,
        ]);

        $this->assertNotNull(
            $result['conversation']->last_message_at,
        );

        Http::assertSent(
            fn (Request $request): bool => str_contains(
                $request->url(),
                'gemini-3.8-flash:generateContent',
            )
                && $request['contents'][0]['parts'][0]['text']
                    === 'テスト質問です。',
        );
    }

    /**
     * Gemini API が HTTP エラーを返した場合、
     * 質問を保持し、AI メッセージをエラー状態にすることを確認する。
     *
     * @group external-api
     */
    public function test_sending_message_preserves_user_message_when_gemini_api_fails(): void
    {
        // Arrange
        config([
            'ai-chat.daily_limit' => 20,
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'message' => 'Internal server error',
                ],
            ], 500),
        ]);

        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'auto_title_enabled' => false,
            ]);

        $action = app(SendMessageAction::class);
        $geminiService = app(GeminiService::class);

        // Act / Assert
        try {
            $action(
                $conversation,
                ['content' => '失敗する質問です。'],
                $geminiService,
            );

            $this->fail(
                'GeminiApiException が発生することを期待しました。',
            );
        } catch (GeminiApiException $exception) {
            $this->assertSame(500, $exception->upstreamStatus);
        }

        // Assert
        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => '失敗する質問です。',
            'status' => AiChatMessageStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'status' => AiChatMessageStatus::Error->value,
            'error_detail' => 'AI の回答取得に失敗しました。',
        ]);

        $this->assertNotNull(
            $conversation->fresh()->last_message_at,
        );
    }
}
