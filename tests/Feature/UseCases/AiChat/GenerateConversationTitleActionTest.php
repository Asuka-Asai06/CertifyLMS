<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use App\Services\GeminiService;
use App\UseCases\AiChat\GenerateConversationTitleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    /**
     * Gemini API で生成したタイトルが会話に保存されることを確認する。
     *
     * @group external-api
     */
    public function test_title_is_generated_and_saved_successfully(): void
    {
        // Arrange
        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Laravelの学習について'],
                            ],
                        ],
                    ],
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
                'title' => '新しい会話',
                'auto_title_enabled' => true,
            ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Laravelについて教えてください。',
            'status' => 'completed',
        ]);

        $geminiService = app(GeminiService::class);
        $action = new GenerateConversationTitleAction;

        // Act
        $action($conversation, $geminiService);

        // Assert
        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => 'Laravelの学習について',
            'auto_title_enabled' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => str_contains(
                $request->url(),
                'gemini-3.8-flash:generateContent',
            )
                && str_contains(
                    $request['contents'][0]['parts'][0]['text'],
                    'Laravelについて教えてください。',
                ),
        );
    }

    /**
     * Gemini API の回答が空の場合、既存タイトルを変更しないことを確認する。
     *
     * @group external-api
     */
    public function test_title_is_not_updated_when_gemini_returns_empty_text(): void
    {
        // Arrange
        config([
            'ai-chat.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-3.8-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '   '],
                            ],
                        ],
                    ],
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
                'title' => '新しい会話',
                'auto_title_enabled' => true,
            ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Laravelについて教えてください。',
            'status' => 'completed',
        ]);

        $action = new GenerateConversationTitleAction;

        // Act
        $action($conversation, app(GeminiService::class));

        // Assert
        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => '新しい会話',
        ]);
    }

    /**
     * Gemini API が失敗した場合、例外を外に投げず既存タイトルを維持することを確認する。
     *
     * @group external-api
     */
    public function test_title_is_not_updated_when_gemini_api_fails(): void
    {
        // Arrange
        config([
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
                'title' => '新しい会話',
                'auto_title_enabled' => true,
            ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Laravelについて教えてください。',
            'status' => 'completed',
        ]);

        $action = new GenerateConversationTitleAction;

        // Act
        $action($conversation, app(GeminiService::class));

        // Assert
        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => '新しい会話',
            'auto_title_enabled' => true,
        ]);
    }
}
