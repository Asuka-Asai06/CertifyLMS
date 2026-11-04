<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * 受講生の AI チャット初期表示データ取得 Action。
 *
 * 会話一覧を最終メッセージ日時の降順で取得する。
 * 会話に紐づく受講登録の資格情報と Section を eager load する。
 */
final class IndexAction
{
    /**
     * @return array{
     *     conversations: Collection<int, AiChatConversation>,
     *     defaultEnrollment: Enrollment|null
     * }
     */
    public function __invoke(User $student): array
    {
        $conversations = $student->aiChatConversations()
            ->with([
                'enrollment.certification',
                'section',
            ])
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->limit(30)
            ->get();

        $defaultEnrollment = $student->defaultEnrollment()
            ->with('certification')
            ->first();

        return [
            'conversations' => $conversations,
            'defaultEnrollment' => $defaultEnrollment,
        ];
    }
}
