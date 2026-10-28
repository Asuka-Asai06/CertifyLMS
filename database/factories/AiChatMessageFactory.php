<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatMessage>
 */
class AiChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => AiChatConversation::factory(),
            'role' => AiChatMessageRole::User,
            'content' => fake()->realText(200),
            'status' => AiChatMessageStatus::Completed,
            'error_detail' => null,
            'model_name' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'response_time_ms' => null,
        ];
    }

    /**
     * ユーザーの質問。
     */
    public function userMessage(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::User,
            'status' => AiChatMessageStatus::Completed,
            'error_detail' => null,
            'model_name' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'response_time_ms' => null,
        ]);
    }

    /**
     * AIの回答。
     */
    public function assistantMessage(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant,
            'status' => AiChatMessageStatus::Completed,
            'error_detail' => null,
            'model_name' => config('ai-chat.gemini.model', 'gemini-3.8-flash'),
            'input_tokens' => fake()->numberBetween(10, 500),
            'output_tokens' => fake()->numberBetween(10, 1000),
            'response_time_ms' => fake()->numberBetween(100, 5000),
        ]);
    }

    /**
     * AIの応答処理中。
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant,
            'status' => AiChatMessageStatus::Pending,
            'error_detail' => null,
            'model_name' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'response_time_ms' => null,
        ]);
    }

    /**
     * AIの応答処理失敗。
     */
    public function error(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant,
            'status' => AiChatMessageStatus::Error,
            'error_detail' => 'AIの応答生成に失敗しました。',
            'model_name' => config('ai-chat.gemini.model', 'gemini-3.8-flash'),
            'input_tokens' => null,
            'output_tokens' => null,
            'response_time_ms' => fake()->numberBetween(100, 5000),
        ]);
    }
}
