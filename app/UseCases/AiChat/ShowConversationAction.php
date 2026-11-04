<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;

/**
 * AI チャット会話の詳細取得 Action。
 * 会話に紐づく受講登録・資格情報・Section・メッセージを取得する。
 */
final class ShowConversationAction
{
    public function __invoke(AiChatConversation $conversation): AiChatConversation
    {
        return $conversation->load([
            'enrollment.certification',
            'section',
            'messages',
        ]);
    }
}
