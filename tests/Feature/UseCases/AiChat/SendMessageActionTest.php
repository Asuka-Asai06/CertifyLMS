<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\Services\GeminiService;
use App\UseCases\AiChat\SendMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * SendMessageAction のテスト。
 *
 * 日次送信上限、資格・教材コンテキストの受け渡し、
 * Gemini API の失敗時のメッセージ保存を確認する。
 */
class SendMessageActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 日次送信上限に達している場合、例外が発生し、
     * Gemini API を呼び出さず、新しい質問が保存されないことを確認する。
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

        $geminiService = Mockery::mock(GeminiService::class);
        $geminiService->shouldNotReceive('generate');

        $action = app(SendMessageAction::class);
        $messagesBefore = AiChatMessage::query()->count();

        // Assert
        $this->expectException(
            AiChatDailyLimitExceededException::class,
        );

        try {
            // Act
            $action(
                $conversation,
                ['content' => '今日の質問です'],
                $geminiService,
            );
        } finally {
            // 上限超過時は質問を新規保存しないことを確認する。
            $this->assertSame(
                $messagesBefore,
                AiChatMessage::query()->count(),
            );
        }
    }

    /**
     * Section がある場合、教材本文と資格名が
     * Gemini のシステム指示に含まれることを確認する。
     */
    public function test_section_context_is_passed_to_gemini(): void
    {
        // Arrange
        $student = $this->createStudent();
        $certification = Certification::factory()->create([
            'name' => 'テスト資格',
            'description' => '資格の説明です。',
        ]);

        $part = Part::factory()
            ->for($certification)
            ->create();

        $chapter = Chapter::factory()
            ->for($part)
            ->create();

        $section = Section::factory()
            ->for($chapter)
            ->create([
                'title' => 'テスト教材',
                'description' => '教材の説明です。',
                'body' => 'これはテスト教材の本文です。',
            ]);

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'enrollment_id' => $enrollment->id,
                'section_id' => $section->id,
            ]);

        $geminiService = Mockery::mock(GeminiService::class);

        $geminiService
            ->shouldReceive('generate')
            ->once()
            ->withArgs(function (
                array $contents,
                ?string $systemInstruction,
            ): bool {
                $this->assertNotEmpty($contents);
                $this->assertNotNull($systemInstruction);
                $this->assertStringContainsString(
                    'テスト資格',
                    $systemInstruction,
                );
                $this->assertStringContainsString(
                    'これはテスト教材の本文です。',
                    $systemInstruction,
                );

                return true;
            })
            ->andReturn($this->successfulGeminiResponse());

        // Act
        $result = (app(SendMessageAction::class))(
            $conversation,
            ['content' => 'この教材について教えてください'],
            $geminiService,
        );

        // Assert
        $this->assertTrue($result['success']);
        $this->assertSame(
            'テスト教材',
            $section->fresh()->title,
        );
    }

    /**
     * Section がなく Enrollment がある場合、
     * 受講登録に紐づく資格名が Gemini に渡されることを確認する。
     */
    public function test_enrollment_certification_context_is_passed_to_gemini_without_section(): void
    {
        // Arrange
        $student = $this->createStudent();

        $certification = Certification::factory()->create([
            'name' => '受講中の資格',
            'description' => '受講中の資格の説明です。',
        ]);

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->learning()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'enrollment_id' => $enrollment->id,
                'section_id' => null,
            ]);

        $geminiService = Mockery::mock(GeminiService::class);

        $geminiService
            ->shouldReceive('generate')
            ->once()
            ->withArgs(function (
                array $contents,
                ?string $systemInstruction,
            ): bool {
                $this->assertNotNull($systemInstruction);
                $this->assertStringContainsString(
                    '受講中の資格',
                    $systemInstruction,
                );

                return true;
            })
            ->andReturn($this->successfulGeminiResponse());

        // Act
        $result = (app(SendMessageAction::class))(
            $conversation,
            ['content' => 'この資格について教えてください'],
            $geminiService,
        );

        // Assert
        $this->assertTrue($result['success']);
    }

    /**
     * コンテキストがない場合も、Gemini に質問を送信できることを確認する。
     */
    public function test_message_can_be_sent_without_context(): void
    {
        // Arrange
        $student = $this->createStudent();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'enrollment_id' => null,
                'section_id' => null,
            ]);

        $geminiService = Mockery::mock(GeminiService::class);

        $geminiService
            ->shouldReceive('generate')
            ->once()
            ->withArgs(function (
                array $contents,
                ?string $systemInstruction,
            ): bool {
                $this->assertNotEmpty($contents);
                $this->assertNull($systemInstruction);

                return true;
            })
            ->andReturn($this->successfulGeminiResponse());

        // Act
        $result = (app(SendMessageAction::class))(
            $conversation,
            ['content' => '質問です'],
            $geminiService,
        );

        // Assert
        $this->assertTrue($result['success']);
        $this->assertSame(
            '質問です',
            $result['user_message']->content,
        );
        $this->assertSame(
            AiChatMessageStatus::Completed,
            $result['assistant_message']->status,
        );
    }

    /**
     * 別の受講生の Enrollment が指定されても、
     * その資格情報を Gemini に渡さないことを確認する。
     */
    public function test_other_students_enrollment_context_is_not_passed_to_gemini(): void
    {
        // Arrange
        $student = $this->createStudent();
        $otherStudent = $this->createStudent();

        $otherCertification = Certification::factory()->create([
            'name' => '他人の資格情報',
            'description' => '他人の資格の説明です。',
        ]);

        $otherEnrollment = Enrollment::factory()
            ->for($otherStudent)
            ->for($otherCertification)
            ->learning()
            ->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'enrollment_id' => $otherEnrollment->id,
                'section_id' => null,
            ]);

        $geminiService = Mockery::mock(GeminiService::class);

        $geminiService
            ->shouldReceive('generate')
            ->once()
            ->withArgs(function (
                array $contents,
                ?string $systemInstruction,
            ): bool {
                $this->assertNull($systemInstruction);
                $this->assertStringNotContainsString(
                    '他人の資格情報',
                    json_encode(
                        $contents,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                );

                return true;
            })
            ->andReturn($this->successfulGeminiResponse());

        // Act
        $result = (app(SendMessageAction::class))(
            $conversation,
            ['content' => '質問です'],
            $geminiService,
        );

        // Assert
        $this->assertTrue($result['success']);
    }

    /**
     * Gemini API が失敗した場合も質問が保持され、
     * AI メッセージが Error 状態で保存されることを確認する。
     */
    public function test_question_is_preserved_when_gemini_api_fails(): void
    {
        // Arrange
        $student = $this->createStudent();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        $geminiService = Mockery::mock(GeminiService::class);

        $geminiService
            ->shouldReceive('generate')
            ->once()
            ->andThrow(new GeminiApiException);

        // Assert
        try {
            // Act
            (app(SendMessageAction::class))(
                $conversation,
                ['content' => '回答に失敗する質問'],
                $geminiService,
            );

            $this->fail('GeminiApiException が発生する想定です。');
        } catch (GeminiApiException) {
            // 質問とエラー状態が保存されていることを確認する。
            $userMessage = AiChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', AiChatMessageRole::User->value)
                ->first();

            $assistantMessage = AiChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', AiChatMessageRole::Assistant->value)
                ->first();

            $this->assertNotNull($userMessage);
            $this->assertSame(
                '回答に失敗する質問',
                $userMessage->content,
            );

            $this->assertNotNull($assistantMessage);
            $this->assertSame(
                AiChatMessageStatus::Error,
                $assistantMessage->status,
            );
            $this->assertSame('', $assistantMessage->content);

            $this->assertNotNull(
                $conversation->fresh()->last_message_at,
            );
        }
    }

    /**
     * テスト用の受講生を作成する。
     */
    private function createStudent(): User
    {
        return User::factory()
            ->student()
            ->inProgress()
            ->create();
    }

    /**
     * Gemini の正常応答を模した値を返す。
     *
     * @return array{
     *     text: string,
     *     model: string,
     *     input_tokens: int|null,
     *     output_tokens: int|null
     * }
     */
    private function successfulGeminiResponse(): array
    {
        return [
            'text' => 'テスト回答です。',
            'model' => 'test-model',
            'input_tokens' => 10,
            'output_tokens' => 5,
        ];
    }
}
