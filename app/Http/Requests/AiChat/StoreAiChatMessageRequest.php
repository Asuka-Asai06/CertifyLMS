<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AIチャットメッセージの送信リクエスト。
 *
 * 受講生が既存の会話にメッセージを送信する際の入力を検証する。
 * 会話に対する送信権限を確認し、メッセージ本文を検証する。
 */
class StoreAiChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        if (! $conversation instanceof AiChatConversation) {
            return false;
        }

        return $this->user()?->can('sendMessage', $conversation) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'content' => 'メッセージ',
        ];
    }
}
