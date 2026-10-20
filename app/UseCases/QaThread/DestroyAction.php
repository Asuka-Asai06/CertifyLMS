<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\UserRole;
use App\Exceptions\QaThread\QaThreadHasRepliesException;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DestroyAction
{
    /**
     * @throws QaThreadHasRepliesException
     */
    public function __invoke(QaThread $thread, User $user): void
    {
        // 管理者以外は、回答付きスレッドを削除できない。
        if (
            $user->role !== UserRole::Admin
            && $thread->replies()->exists()
        ) {
            throw new QaThreadHasRepliesException;
        }

        DB::transaction(function () use ($thread, $user): void {
            // 管理者による削除では、回答も削除する。
            if ($user->role === UserRole::Admin) {
                $thread->replies()->delete();
            }

            $thread->delete();
        });
    }
}
