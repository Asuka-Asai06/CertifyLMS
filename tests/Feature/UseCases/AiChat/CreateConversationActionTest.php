<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiUnavailableException;
use App\Models\AiChatConversation;
use App\Models\User;
use App\Services\GeminiService;
use App\UseCases\AiChat\CreateConversationAction;
use App\UseCases\AiChat\SendMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * CreateConversationAction のテスト。
 */
class CreateConversationActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 初回質問が空の場合、会話のみ作成され、
     * SendMessageAction が呼び出されないことを確認する。
     */
    public function test_conversation_is_created_without_initial_message(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $sendMessageAction = Mockery::mock(SendMessageAction::class);
        $sendMessageAction->shouldNotReceive('__invoke');

        $geminiService = Mockery::mock(GeminiService::class);

        $action = new CreateConversationAction(
            $sendMessageAction,
            $geminiService,
        );

        // Act
        $result = $action($student, [
            'message' => '',
        ]);

        // Assert
        $this->assertInstanceOf(
            AiChatConversation::class,
            $result['conversation'],
        );
        $this->assertSame(
            '新しい会話',
            $result['conversation']->title,
        );
        $this->assertNull($result['failure_message']);

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $result['conversation']->id,
            'user_id' => $student->id,
            'title' => '新しい会話',
        ]);
    }

    /**
     * 初回質問がある場合、SendMessageAction が呼び出され、
     * 作成した会話と失敗メッセージなしの結果を返すことを確認する。
     */
    public function test_conversation_is_created_and_initial_message_is_sent(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $sendMessageAction = Mockery::mock(SendMessageAction::class);

        $sendMessageAction
            ->shouldReceive('__invoke')
            ->once()
            ->with(
                Mockery::type(AiChatConversation::class),
                ['content' => '二分探索木について教えてください'],
                Mockery::type(GeminiService::class),
            )
            ->andReturn([]);

        $geminiService = Mockery::mock(GeminiService::class);

        $action = new CreateConversationAction(
            $sendMessageAction,
            $geminiService,
        );

        // Act
        $result = $action($student, [
            'title' => '二分探索木の質問',
            'message' => '  二分探索木について教えてください  ',
        ]);

        // Assert
        $this->assertInstanceOf(
            AiChatConversation::class,
            $result['conversation'],
        );
        $this->assertSame(
            '二分探索木の質問',
            $result['conversation']->title,
        );
        $this->assertNull($result['failure_message']);

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $result['conversation']->id,
            'user_id' => $student->id,
            'title' => '二分探索木の質問',
        ]);
    }

    /**
     * 日次送信上限に達している場合、会話は作成され、
     * 失敗メッセージを含む結果が返されることを確認する。
     */
    public function test_conversation_is_created_when_daily_limit_is_exceeded(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $sendMessageAction = Mockery::mock(SendMessageAction::class);

        $sendMessageAction
            ->shouldReceive('__invoke')
            ->once()
            ->andThrow(new AiChatDailyLimitExceededException);

        $geminiService = Mockery::mock(GeminiService::class);

        $action = new CreateConversationAction(
            $sendMessageAction,
            $geminiService,
        );

        // Act
        $result = $action($student, [
            'message' => '今日の質問です',
        ]);

        // Assert
        $this->assertInstanceOf(
            AiChatConversation::class,
            $result['conversation'],
        );
        $this->assertSame(
            '本日の質問送信上限に達しているため、質問を送信できませんでした。',
            $result['failure_message'],
        );

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $result['conversation']->id,
            'user_id' => $student->id,
        ]);
    }

    /**
     * Gemini が利用できない場合、会話は作成され、
     * 失敗メッセージを含む結果が返されることを確認する。
     */
    public function test_conversation_is_created_when_gemini_is_unavailable(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $sendMessageAction = Mockery::mock(SendMessageAction::class);

        $sendMessageAction
            ->shouldReceive('__invoke')
            ->once()
            ->andThrow(new GeminiUnavailableException);

        $geminiService = Mockery::mock(GeminiService::class);

        $action = new CreateConversationAction(
            $sendMessageAction,
            $geminiService,
        );

        // Act
        $result = $action($student, [
            'message' => 'AI に質問します',
        ]);

        // Assert
        $this->assertInstanceOf(
            AiChatConversation::class,
            $result['conversation'],
        );
        $this->assertSame(
            '会話は作成されましたが、現在 AI チャットを利用できません。質問は送信されていません。',
            $result['failure_message'],
        );

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $result['conversation']->id,
            'user_id' => $student->id,
        ]);
    }

    /**
     * Gemini API エラーが発生した場合、会話は作成され、
     * 失敗メッセージを含む結果が返されることを確認する。
     */
    public function test_conversation_is_created_when_gemini_api_fails(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $sendMessageAction = Mockery::mock(SendMessageAction::class);

        $sendMessageAction
            ->shouldReceive('__invoke')
            ->once()
            ->andThrow(new GeminiApiException);

        $geminiService = Mockery::mock(GeminiService::class);

        $action = new CreateConversationAction(
            $sendMessageAction,
            $geminiService,
        );

        // Act
        $result = $action($student, [
            'message' => 'AI に質問します',
        ]);

        // Assert
        $this->assertInstanceOf(
            AiChatConversation::class,
            $result['conversation'],
        );
        $this->assertSame(
            '質問は保存されましたが、AI が応答できませんでした。会話画面から再度お試しください。',
            $result['failure_message'],
        );

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $result['conversation']->id,
            'user_id' => $student->id,
        ]);
    }
}
