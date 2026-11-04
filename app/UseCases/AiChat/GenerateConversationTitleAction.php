<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI チャット会話のタイトルを生成・更新する Action。
 */
final class GenerateConversationTitleAction
{
    /**
     * 会話の内容に基づいてタイトルを生成する。
     *
     * タイトル生成に失敗しても、通常のメッセージ送信には影響させない。
     */
    public function __invoke(
        AiChatConversation $conversation,
        GeminiService $geminiService,
    ): void {
        $conversation->refresh();

        if (! $conversation->auto_title_enabled) {
            return;
        }

        try {
            $messages = $conversation->messages()
                ->where('status', AiChatMessageStatus::Completed->value)
                ->whereIn('role', [
                    AiChatMessageRole::User->value,
                    AiChatMessageRole::Assistant->value,
                ])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['role', 'content']);

            if ($messages->isEmpty()) {
                return;
            }

            $history = $messages
                ->map(fn ($message): string => sprintf(
                    '%s: %s',
                    $message->role === AiChatMessageRole::User
                        ? '受講生'
                        : 'AI',
                    $message->content,
                ))
                ->implode("\n");

            $result = $geminiService->generate(
                [
                    [
                        'role' => 'user',
                        'parts' => [[
                            'text' => "次の会話内容を表す日本語のタイトルを1つ作成してください。\n"
                                ."条件: 100文字以内、タイトルだけを出力、説明や引用符は不要。\n\n"
                                .$history,
                        ]],
                    ],
                ],
                'あなたは会話のタイトルを作成するアシスタントです。'
                    .'会話の主題を簡潔に表すタイトルだけを返してください。',
            );

            $title = trim($result['text']);
            $title = trim($title, " \t\n\r\0\x0B\"'「」『』");

            if ($title === '') {
                return;
            }

            $title = mb_substr($title, 0, 100);

            // 生成中に手動編集された場合、上書きしない。
            $conversation->refresh();

            if (! $conversation->auto_title_enabled) {
                return;
            }

            $conversation->update(['title' => $title]);
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('AIチャットのタイトル生成に失敗しました。', [
                'conversation_id' => $conversation->id,
                'exception' => $exception::class,
            ]);
        }
    }
}
