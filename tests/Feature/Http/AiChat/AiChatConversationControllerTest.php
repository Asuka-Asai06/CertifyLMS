<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講生向け AiChatConversationController の HTTP 統合テスト。
 *
 * - 会話一覧・詳細画面の表示
 * - 会話詳細の JSON レスポンス
 * - 会話の削除
 * - 会話の所有者に対するアクセス制限
 * - 未認証ユーザーのアクセス制限
 */
class AiChatConversationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 会話一覧画面に会話一覧とデフォルト受講登録が渡されることを確認する。
     */
    public function test_index_returns_conversation_list_for_student(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        // Act
        $response = $this->actingAs($student)
            ->get(route('ai-chat.index'));

        // Assert
        $response->assertOk();
        $response->assertViewIs('ai-chat.empty-state');

        $response->assertViewHas('conversations', function ($conversations) use ($conversation) {
            return $conversations->contains('id', $conversation->id);
        });

        $response->assertViewHas('defaultEnrollment', null);
    }

    /**
     * 会話詳細画面が表示されることを確認する。
     */
    public function test_show_returns_conversation_detail_for_owner(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        // Act
        $response = $this->actingAs($student)
            ->get(route('ai-chat.conversations.show', $conversation));

        // Assert
        $response->assertOk();
        $response->assertViewIs('ai-chat.show');
        $response->assertViewHas('conversation', function ($result) use ($conversation) {
            return $result->id === $conversation->id;
        });
    }

    /**
     * 会話詳細の JSON レスポンスにメッセージの内容が含まれることを確認する。
     */
    public function test_show_returns_messages_as_json_for_owner(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        $conversation->messages()->create([
            'role' => AiChatMessageRole::User->value,
            'content' => '二分探索木について教えてください。',
            'status' => AiChatMessageStatus::Completed->value,
        ]);

        $conversation->messages()->create([
            'role' => AiChatMessageRole::Assistant->value,
            'content' => '二分探索木は、各ノードが最大2つの子を持つ木構造です。',
            'status' => AiChatMessageStatus::Completed->value,
        ]);

        // Act
        $response = $this->actingAs($student)
            ->getJson(route('ai-chat.conversations.show', $conversation));

        // Assert
        $response->assertOk();

        $response->assertExactJson([
            'messages' => [
                [
                    'role' => AiChatMessageRole::User->value,
                    'content' => '二分探索木について教えてください。',
                    'status' => AiChatMessageStatus::Completed->value,
                ],
                [
                    'role' => AiChatMessageRole::Assistant->value,
                    'content' => '二分探索木は、各ノードが最大2つの子を持つ木構造です。',
                    'status' => AiChatMessageStatus::Completed->value,
                ],
            ],
        ]);
    }

    /**
     * 他の受講生の会話を表示できないことを確認する。
     */
    public function test_student_cannot_view_another_students_conversation(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $anotherStudent = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($anotherStudent, 'user')
            ->create();

        // Act & Assert
        $this->actingAs($student)
            ->get(route('ai-chat.conversations.show', $conversation))
            ->assertForbidden();
    }

    /**
     * 会話を削除すると一覧画面へリダイレクトされることを確認する。
     */
    public function test_destroy_deletes_conversation_for_owner(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        // Act
        $response = $this->actingAs($student)
            ->delete(route('ai-chat.conversations.destroy', $conversation));

        // Assert
        $response->assertRedirect(route('ai-chat.index'));
        $response->assertSessionHas('success', '会話を削除しました。');

        $this->assertDatabaseMissing('ai_chat_conversations', [
            'id' => $conversation->id,
        ]);
    }

    /**
     * 他の受講生の会話を削除できないことを確認する。
     */
    public function test_student_cannot_delete_another_students_conversation(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $anotherStudent = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($anotherStudent, 'user')
            ->create();

        // Act & Assert
        $this->actingAs($student)
            ->delete(route('ai-chat.conversations.destroy', $conversation))
            ->assertForbidden();

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
        ]);
    }

    /**
     * 未認証ユーザーが会話一覧へアクセスするとログイン画面へリダイレクトされることを確認する。
     */
    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        // Act & Assert
        $this->get(route('ai-chat.index'))
            ->assertRedirect(route('login'));
    }

    /**
     * 未認証ユーザーが会話詳細へアクセスするとログイン画面へリダイレクトされることを確認する。
     */
    public function test_unauthenticated_user_cannot_view_conversation(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        // Act & Assert
        $this->get(route('ai-chat.conversations.show', $conversation))
            ->assertRedirect(route('login'));
    }

    /**
     * 会話を作成すると詳細画面へリダイレクトされることを確認する。
     */
    public function test_store_creates_conversation_and_redirects_to_show(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($student)
            ->post(route('ai-chat.conversations.store'), [
                'message' => null,
            ]);

        // Assert
        $response->assertRedirect();

        $conversation = AiChatConversation::query()
            ->where('user_id', $student->id)
            ->firstOrFail();

        $response->assertRedirect(
            route('ai-chat.conversations.show', [
                'conversation' => $conversation,
            ]),
        );

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'user_id' => $student->id,
        ]);
    }

    /**
     * JSON リクエストで会話を作成すると ID が返されることを確認する。
     */
    public function test_store_returns_created_conversation_id_as_json(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), [
                'message' => null,
            ]);

        // Assert
        $response->assertCreated();
        $response->assertJsonStructure([
            'conversation' => [
                'id',
            ],
        ]);

        $conversationId = $response->json('conversation.id');

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversationId,
            'user_id' => $student->id,
        ]);
    }

    /**
     * 会話タイトルを更新すると詳細画面へリダイレクトされることを確認する。
     */
    public function test_update_changes_conversation_title(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'title' => '変更前のタイトル',
            ]);

        // Act
        $response = $this->actingAs($student)
            ->patch(route('ai-chat.conversations.update', $conversation), [
                'title' => '変更後のタイトル',
            ]);

        // Assert
        $response->assertRedirect(
            route('ai-chat.conversations.show', [
                'conversation' => $conversation,
            ]),
        );

        $response->assertSessionHas('success', '会話タイトルを変更しました。');

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => '変更後のタイトル',
        ]);
    }

    /**
     * 他の受講生の会話タイトルを変更できないことを確認する。
     */
    public function test_student_cannot_update_another_students_conversation(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $anotherStudent = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($anotherStudent, 'user')
            ->create([
                'title' => '元のタイトル',
            ]);

        // Act & Assert
        $this->actingAs($student)
            ->patch(route('ai-chat.conversations.update', $conversation), [
                'title' => '不正な変更',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => '元のタイトル',
        ]);
    }

    /**
     * 未認証ユーザーが会話を作成できないことを確認する。
     */
    public function test_unauthenticated_user_cannot_create_conversation(): void
    {
        // Act & Assert
        $this->post(route('ai-chat.conversations.store'), [
            'message' => null,
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('ai_chat_conversations', 0);
    }

    /**
     * 未認証ユーザーが会話タイトルを変更できないことを確認する。
     */
    public function test_unauthenticated_user_cannot_update_conversation(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create([
                'title' => '変更前のタイトル',
            ]);

        // Act & Assert
        $this->patch(route('ai-chat.conversations.update', $conversation), [
            'title' => '変更後のタイトル',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
            'title' => '変更前のタイトル',
        ]);
    }

    /**
     * 未認証ユーザーが会話を削除できないことを確認する。
     */
    public function test_unauthenticated_user_cannot_delete_conversation(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();

        $conversation = AiChatConversation::factory()
            ->for($student, 'user')
            ->create();

        // Act & Assert
        $this->delete(route('ai-chat.conversations.destroy', $conversation))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversation->id,
        ]);
    }
}
