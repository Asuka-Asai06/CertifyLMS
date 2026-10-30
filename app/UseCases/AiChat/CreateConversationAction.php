<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Exceptions\AiChat\GeminiUnavailableException;
use App\Models\AiChatConversation;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Support\Facades\DB;

/**
 * AI チャット会話を作成する Action。
 *
 * 会話に受講登録・Section のコンテキストを設定する。
 * 初回質問が指定されている場合は、SendMessageAction で送信する。
 * 質問の送信に失敗した場合も、作成済みの会話を返す。
 */
final class CreateConversationAction
{
    public function __construct(
        private readonly SendMessageAction $sendMessageAction,
        private readonly GeminiService $geminiService,
    ) {}

    /**
     * AI チャット会話を作成する。
     *
     * @param array{
     *     title?: string,
     *     enrollment_id?: string|null,
     *     section_id?: string|null,
     *     message?: string|null
     * } $data
     *
     * @return array{
     *     conversation: AiChatConversation,
     *     failure_message: string|null
     * }
     */
    public function __invoke(User $student, array $data): array
    {
        $message = trim($data['message'] ?? '');

        // フル画面で初回メッセージがある場合のみ、先頭30文字をタイトルにする。
        $hasInitialTitle = ($data['source'] ?? null) === 'full-screen'
            && $message !== '';

        $conversation = DB::transaction(
            fn (): AiChatConversation => $student
                ->aiChatConversations()
                ->create([
                    'title' => $hasInitialTitle
                        ? mb_substr($message, 0, 30)
                        : '新規相談',
                    'enrollment_id' => $data['enrollment_id'] ?? null,
                    'section_id' => $data['section_id'] ?? null,
                    'auto_title_enabled' => ! $hasInitialTitle,
                ]),
        );

        if ($message === '') {
            return [
                'conversation' => $conversation,
                'failure_message' => null,
            ];
        }

        try {
            ($this->sendMessageAction)(
                $conversation,
                ['content' => $message],
                $this->geminiService,
            );

            return [
                'conversation' => $conversation->fresh(),
                'failure_message' => null,
            ];
        } catch (AiChatDailyLimitExceededException) {
            return [
                'conversation' => $conversation->fresh(),
                'failure_message' => '本日の質問送信上限に達しているため、質問を送信できませんでした。',
            ];
        } catch (GeminiUnavailableException) {
            return [
                'conversation' => $conversation->fresh(),
                'failure_message' => '会話は作成されましたが、現在 AI チャットを利用できません。質問は送信されていません。',
            ];
        } catch (GeminiApiException) {
            return [
                'conversation' => $conversation->fresh(),
                'failure_message' => '質問は保存されましたが、AI が応答できませんでした。会話画面から再度お試しください。',
            ];
        }
    }
}
