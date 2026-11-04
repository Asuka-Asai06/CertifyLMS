<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiUnavailableException;
use App\Models\AiChatConversation;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
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
            '新規相談',
            $result['conversation']->title,
        );
        $this->assertNull($result['failure_message']);

        $this->assertTrue($result['conversation']->auto_title_enabled);

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $result['conversation']->id,
            'user_id' => $student->id,
            'title' => '新規相談',
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
            'source' => 'full-screen',
            'message' => '  二分探索木について教えてください  ',
        ]);

        // Assert
        $this->assertSame(
            '二分探索木について教えてください',
            $result['conversation']->title,
        );
        $this->assertFalse($result['conversation']->auto_title_enabled);

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $result['conversation']->id,
            'user_id' => $student->id,
            'title' => '二分探索木について教えてください',
            'auto_title_enabled' => false,
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

    /**
     * フル画面以外から作成した場合は、初期タイトルを使用し、
     * 自動タイトル生成を有効にすることを確認する。
     */
    public function test_conversation_created_from_another_source_uses_default_title(): void
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
            ->andReturn([]);

        $geminiService = Mockery::mock(GeminiService::class);

        $action = new CreateConversationAction(
            $sendMessageAction,
            $geminiService,
        );

        // Act
        $result = $action($student, [
            'message' => '二分探索木について教えてください',
        ]);

        // Assert
        $this->assertSame('新規相談', $result['conversation']->title);
        $this->assertTrue($result['conversation']->auto_title_enabled);

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $result['conversation']->id,
            'title' => '新規相談',
            'auto_title_enabled' => true,
        ]);
    }

    /**
     * フル画面の初回メッセージが30文字を超える場合、
     * タイトルには先頭30文字だけを使用することを確認する。
     */
    public function test_full_screen_initial_message_title_is_limited_to_30_characters(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $message = str_repeat('あ', 35);

        $sendMessageAction = Mockery::mock(SendMessageAction::class);
        $sendMessageAction
            ->shouldReceive('__invoke')
            ->once()
            ->andReturn([]);

        $geminiService = Mockery::mock(GeminiService::class);

        $action = new CreateConversationAction(
            $sendMessageAction,
            $geminiService,
        );

        // Act
        $result = $action($student, [
            'source' => 'full-screen',
            'message' => $message,
        ]);

        // Assert
        $this->assertSame(str_repeat('あ', 30), $result['conversation']->title);
        $this->assertSame(30, mb_strlen($result['conversation']->title));
        $this->assertFalse($result['conversation']->auto_title_enabled);
    }

    /**
     * 会話作成時に学習コンテキストのIDが保存されることを確認する。
     */
    public function test_conversation_is_created_with_learning_context_ids(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $certification = Certification::factory()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        $part = Part::factory()
            ->for($certification)
            ->create();

        $chapter = Chapter::factory()
            ->for($part)
            ->create();

        $section = Section::factory()
            ->for($chapter)
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
            'enrollment_id' => $enrollment->id,
            'chapter_id' => $chapter->id,
            'section_id' => $section->id,
            'message' => '',
        ]);

        // Assert
        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $result['conversation']->id,
            'user_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'chapter_id' => $chapter->id,
            'section_id' => $section->id,
        ]);

        $this->assertNull($result['failure_message']);
    }

    /**
     * 初回質問が空白だけの場合、会話のみ作成されることを確認する。
     */
    public function test_conversation_is_created_without_initial_message_when_message_contains_only_whitespace(): void
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
            'message' => '   ',
        ]);

        // Assert
        $this->assertSame('新規相談', $result['conversation']->title);
        $this->assertTrue($result['conversation']->auto_title_enabled);
        $this->assertNull($result['failure_message']);
    }
}
