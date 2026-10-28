<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AiChatMessage モデルのリレーション・Cast を検証する Unit テスト。
 *
 * conversation リレーションと各属性の型変換を検証する。
 */
class AiChatMessageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * conversation リレーションが紐づく会話を返すことを確認する。
     */
    public function test_conversation_relation_returns_related_conversation(): void
    {
        $conversation = AiChatConversation::factory()->create();

        $message = AiChatMessage::factory()
            ->for($conversation, 'conversation')
            ->create();

        $relatedConversation = $message->conversation;

        $this->assertTrue($relatedConversation->is($conversation));
    }

    /**
     * role が AiChatMessageRole Enum に Cast されることを確認する。
     */
    public function test_role_cast_returns_enum(): void
    {
        $role = AiChatMessageRole::cases()[0];

        $message = AiChatMessage::factory()->create([
            'role' => $role,
        ]);

        $fresh = $message->fresh();

        $this->assertInstanceOf(AiChatMessageRole::class, $fresh->role);
        $this->assertSame($role, $fresh->role);
    }

    /**
     * status が AiChatMessageStatus Enum に Cast されることを確認する。
     */
    public function test_status_cast_returns_enum(): void
    {
        $status = AiChatMessageStatus::cases()[0];

        $message = AiChatMessage::factory()->create([
            'status' => $status,
        ]);

        $fresh = $message->fresh();

        $this->assertInstanceOf(AiChatMessageStatus::class, $fresh->status);
        $this->assertSame($status, $fresh->status);
    }

    /**
     * トークン数と応答時間が整数に Cast されることを確認する。
     */
    public function test_numeric_attributes_are_cast_to_integer(): void
    {
        $message = AiChatMessage::factory()->create([
            'input_tokens' => '120',
            'output_tokens' => '80',
            'response_time_ms' => '1500',
        ]);

        $fresh = $message->fresh();

        $this->assertIsInt($fresh->input_tokens);
        $this->assertSame(120, $fresh->input_tokens);

        $this->assertIsInt($fresh->output_tokens);
        $this->assertSame(80, $fresh->output_tokens);

        $this->assertIsInt($fresh->response_time_ms);
        $this->assertSame(1500, $fresh->response_time_ms);
    }

    /**
     * 数値属性が null の場合、null のまま取得できることを確認する。
     */
    public function test_numeric_attributes_can_be_null(): void
    {
        $message = AiChatMessage::factory()->create([
            'input_tokens' => null,
            'output_tokens' => null,
            'response_time_ms' => null,
        ]);

        $fresh = $message->fresh();

        $this->assertNull($fresh->input_tokens);
        $this->assertNull($fresh->output_tokens);
        $this->assertNull($fresh->response_time_ms);
    }
}
