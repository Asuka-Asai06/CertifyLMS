<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiUnavailableException;
use App\Models\AiChatConversation;
use App\Models\User;
use App\Services\GeminiService;
use App\UseCases\AiChat\SendMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * 受講生向け AiChatMessageController の HTTP 統合テスト。
 *
 * - メッセージ送信時の JSON レスポンス
 * - Gemini API エラー時の 502 レスポンス
 * - AI 利用不可時の 503 レスポンス
 * - 他ユーザーの会話への送信制限
 * - 未認証ユーザーのアクセス制限
 */
class AiChatMessageControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * メッセージ送信成功時に JSON レスポンスが返されることを確認する。
     *
     * 外部 API は呼び出さず、SendMessageAction をモックする。
     */
    public function test_store_returns_json_when_message_is_sent_successfully(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        $result = [
            'message' => [
                'role' => 'user',
                'content' => 'Laravelについて教えてください。',
                'status' => 'completed',
            ],
        ];

        $action = Mockery::mock(SendMessageAction::class);
        $action->shouldReceive('__invoke')
            ->once()
            ->with(
                Mockery::on(fn ($actual): bool => $actual->id === $conversation->id),
                ['content' => 'Laravelについて教えてください。'],
                Mockery::type(GeminiService::class),
            )
            ->andReturn($result);

        $this->app->instance(SendMessageAction::class, $action);

        // Act
        $response = $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                ['content' => 'Laravelについて教えてください。'],
            );

        // Assert
        $response->assertOk();
        $response->assertExactJson($result);
    }

    /**
     * Gemini API エラー時に 502 レスポンスが返されることを確認する。
     */
    public function test_store_returns_502_when_gemini_api_fails(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        $action = Mockery::mock(SendMessageAction::class);
        $action->shouldReceive('__invoke')
            ->once()
            ->andThrow(new GeminiApiException(429));

        $this->app->instance(SendMessageAction::class, $action);

        // Act
        $response = $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                ['content' => '質問です。'],
            );

        // Assert
        $response->assertStatus(502);
        $response->assertExactJson([
            'message' => 'AI が応答できませんでした。再度お試しください。',
            'upstream_status' => 429,
        ]);
    }

    /**
     * AI 利用不可時に 503 レスポンスが返されることを確認する。
     */
    public function test_store_returns_503_when_ai_is_unavailable(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        $action = Mockery::mock(SendMessageAction::class);
        $action->shouldReceive('__invoke')
            ->once()
            ->andThrow(new GeminiUnavailableException);

        $this->app->instance(SendMessageAction::class, $action);

        // Act
        $response = $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                ['content' => '質問です。'],
            );

        // Assert
        $response->assertStatus(503);
        $response->assertExactJson([
            'message' => '現在 AI チャットを利用できません。',
        ]);
    }

    /**
     * 他の受講生の会話にはメッセージを送信できないことを確認する。
     */
    public function test_student_cannot_send_message_to_another_students_conversation(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $anotherStudent = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($anotherStudent, 'user')
            ->create();

        // Act & Assert
        $this->actingAs($student)
            ->postJson(
                route('ai-chat.conversations.messages.store', $conversation),
                ['content' => '不正なメッセージ'],
            )
            ->assertForbidden();
    }

    /**
     * 未認証ユーザーはメッセージを送信できないことを確認する。
     */
    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        // Act & Assert
        $this->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '質問です。'],
        )->assertUnauthorized();
    }
}
