<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;

/**
 * AI チャット会話のタイトル更新 Action。
 *
 * 手動でタイトルを更新した場合、自動更新を無効にする。
 */
final class UpdateConversationAction
{
    /**
     * 会話のタイトルを更新する。
     *
     * @param array{title: string} $data
     */
    public function __invoke(
        AiChatConversation $conversation,
        array $data,
    ): AiChatConversation {
        $conversation->update([
            'title' => $data['title'],
            'auto_title_enabled' => false,
        ]);

        return $conversation;
    }
}
