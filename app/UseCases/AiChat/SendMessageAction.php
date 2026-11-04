<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiUnavailableException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AI チャットのメッセージ送信を管理する Action。
 *
 * 質問の保存、会話履歴の取得、学習コンテキストの取得、
 * Gemini API の呼び出し、回答またはエラーの保存を行う。
 *
 * API 呼び出しに失敗した場合も質問は保持し、失敗した送信も日次送信上限にカウントする。
 */
class SendMessageAction
{
    public function __construct(
        private readonly GenerateConversationTitleAction $generateConversationTitleAction,
        private readonly BuildAiChatContextAction $buildAiChatContextAction,
    ) {}

    /**
     * 質問を送信し、AI の回答またはエラー情報を返す。
     *
     * @param array{content: string} $data
     *
     * @return array{
     *     success: bool,
     *     user_message: AiChatMessage,
     *     assistant_message: AiChatMessage,
     *     conversation: AiChatConversation
     * }
     *
     * @throws AiChatDailyLimitExceededException 日次送信上限を超えた場合
     */
    public function __invoke(
        AiChatConversation $conversation,
        array $data,
        GeminiService $geminiService,
    ): array {
        $student = User::query()
            ->findOrFail($conversation->user_id);

        $dailyLimit = (int) config('ai-chat.daily_limit', 20);

        /*
         * 日次上限の確認と質問の保存を同じトランザクション内で行う。
         * 同一受講生からの同時送信による上限超過を防ぎやすくする。
         */
        [$userMessage, $assistantMessage] = DB::transaction(
            function () use (
                $student,
                $conversation,
                $data,
                $dailyLimit,
            ): array {
                User::query()
                    ->whereKey($student->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $todayCount = AiChatMessage::query()
                    ->where('role', AiChatMessageRole::User->value)
                    ->whereHas(
                        'conversation',
                        fn (Builder $query) => $query
                            ->where('user_id', $student->id),
                    )
                    ->whereBetween('created_at', [
                        now()->startOfDay(),
                        now()->endOfDay(),
                    ])
                    ->count();

                if ($todayCount >= $dailyLimit) {
                    throw new AiChatDailyLimitExceededException;
                }

                $userMessage = $conversation->messages()->create([
                    'role' => AiChatMessageRole::User,
                    'content' => $data['content'],
                    'status' => AiChatMessageStatus::Completed,
                ]);

                $assistantMessage = $conversation->messages()->create([
                    'role' => AiChatMessageRole::Assistant,
                    'content' => '',
                    'status' => AiChatMessageStatus::Pending,
                ]);

                return [$userMessage, $assistantMessage];
            },
        );

        $historyLimit = max(
            1,
            (int) config('ai-chat.history_limit', 20),
        );

        /*
         * 完了済みの過去メッセージと今回の質問を取得する。
         * pending / error の AI メッセージは Gemini に送らない。
         */
        $messages = $conversation->messages()
            ->where(function (Builder $query) use (
                $userMessage,
            ): void {
                $query
                    ->where(
                        'status',
                        AiChatMessageStatus::Completed->value,
                    )
                    ->orWhere('id', $userMessage->id);
            })
            ->where('id', '!=', $assistantMessage->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($historyLimit)
            ->get()
            ->reverse()
            ->values();

        $contents = $messages
            ->map(fn (AiChatMessage $message): array => [
                'role' => $message->role === AiChatMessageRole::Assistant
                    ? 'model'
                    : 'user',
                'parts' => [
                    ['text' => $message->content],
                ],
            ])
            ->all();

        $startedAt = microtime(true);

        try {
            // 会話に応じた学習コンテキストを取得する。
            $systemInstruction = ($this->buildAiChatContextAction)(
                $conversation,
            );

            $result = $geminiService->generate(
                $contents,
                $systemInstruction,
            );

            $assistantMessage->update([
                'content' => $result['text'],
                'status' => AiChatMessageStatus::Completed,
                'model_name' => $result['model'],
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
                'response_time_ms' => (int) round(
                    (microtime(true) - $startedAt) * 1000,
                ),
                'error_detail' => null,
            ]);

            $success = true;
        } catch (Throwable $exception) {
            report($exception);

            $assistantMessage->update([
                'status' => AiChatMessageStatus::Error,
                'content' => '',
                'error_detail' => 'AI の回答取得に失敗しました。',
                'model_name' => (string) config(
                    'ai-chat.gemini.model',
                    'gemini-3.8-flash',
                ),
                'response_time_ms' => (int) round(
                    (microtime(true) - $startedAt) * 1000,
                ),
            ]);

            $conversation->update([
                'last_message_at' => now(),
            ]);

            if ($exception instanceof GeminiUnavailableException) {
                throw $exception;
            }

            if ($exception instanceof GeminiApiException) {
                throw $exception;
            }

            throw new GeminiApiException(
                null,
                $exception,
            );
        }

        $conversation->update([
            'last_message_at' => now(),
        ]);

        ($this->generateConversationTitleAction)(
            $conversation->fresh(),
            $geminiService,
        );

        return [
            'success' => $success,
            'user_message' => $userMessage->fresh(),
            'assistant_message' => $assistantMessage->fresh(),
            'conversation' => $conversation->fresh(),
        ];
    }
}
