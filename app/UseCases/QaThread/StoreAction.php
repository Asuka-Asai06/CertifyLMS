<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * QaThread に質問を INSERT する Action。
 *
 * - 投稿者にはログイン中の User を設定する
 * - 新規質問は未解決状態で登録する
 * - resolved_at は新規登録時には null とする
 */
final class StoreAction
{
    /**
     * @param User $user 質問を投稿するユーザー
     * @param array{certification_id: string, title: string, body: string} $validated
     *
     * @return QaThread 登録した質問
     */
    public function __invoke(User $user, array $validated): QaThread
    {
        return DB::transaction(function () use ($user, $validated): QaThread {
            return QaThread::create([
                'user_id' => $user->id,
                'certification_id' => $validated['certification_id'],
                'title' => $validated['title'],
                'body' => $validated['body'],
                'status' => QaThreadStatus::Unresolved,
                'resolved_at' => null,
            ]);
        });
    }
}
