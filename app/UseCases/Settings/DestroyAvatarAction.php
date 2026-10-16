<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * プロフィール画像の削除ユースケース。
 *
 * DB の avatar_url を null に更新し、コミット後に Storage 上の実ファイルを削除する。
 * トランザクション ROLLBACK 時には Storage の削除をスキップし、不可逆な実ファイル削除を防ぐ。
 */
final class DestroyAvatarAction
{
    public function __invoke(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $avatarUrl = $user->avatar_url;

            // プロフィール画像が未設定なら何もしない。
            if ($avatarUrl === null || $avatarUrl === '') {
                return;
            }

            $path = Str::after($avatarUrl, '/storage/');

            $user->update([
                'avatar_url' => null,
            ]);

            if (Str::startsWith($path, 'avatars/')) {
                DB::afterCommit(
                    fn () => Storage::disk('public')->delete($path),
                );
            }
        });
    }
}
