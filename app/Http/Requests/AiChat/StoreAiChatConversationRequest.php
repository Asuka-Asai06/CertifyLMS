<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AIチャット会話の作成リクエスト。
 *
 * 受講生が新しい会話を作成する際の入力を検証する。
 * 初回メッセージは任意とし、指定時は2000文字以内とする。
 */
class StoreAiChatConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AiChatConversation::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enrollment_id' => ['nullable', 'ulid'],
            'section_id' => ['nullable', 'ulid'],
            'message' => ['nullable', 'string', 'max:2000'],
            'source' => ['sometimes', 'string', 'in:full-screen'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'enrollment_id' => '受講登録',
            'section_id' => '教材セクション',
            'message' => 'メッセージ',
        ];
    }
}
