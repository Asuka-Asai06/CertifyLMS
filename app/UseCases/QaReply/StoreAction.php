<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * QaThread に対する返信を INSERT する Action。
 *
 * - 返信者にはログイン中の User を設定する
 * - 返信先には対象の QaThread を設定する
 * - 通知や Broadcast は本 Action では扱わない
 * - 添付ファイルやネスト回答などは扱わない
 */
final class StoreAction
{
    /**
     * @param User $user 返信を投稿するユーザー
     * @param QaThread $thread 返信先の質問
     * @param array{body: string} $validated
     *
     * @return QaReply 登録した返信
     */
    public function __invoke(
        User $user,
        QaThread $thread,
        array $validated,
    ): QaReply {
        return DB::transaction(function () use (
            $user,
            $thread,
            $validated,
        ): QaReply {
            return QaReply::create([
                'qa_thread_id' => $thread->id,
                'user_id' => $user->id,
                'body' => $validated['body'],
            ]);
        });
    }
}
