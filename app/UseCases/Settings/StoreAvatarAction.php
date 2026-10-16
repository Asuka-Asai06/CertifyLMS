<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * プロフィール画像のアップロードユースケース。
 *
 * `avatars/{ulid}.{ext}` 形式で public disk に保存し、DB の avatar_url に公開用パスを保存する。
 * DB 更新に失敗した場合は、保存した画像を削除する。
 * 既存画像は DB 更新のコミット後に削除する。
 */
final class StoreAvatarAction
{
    public function __invoke(User $user, UploadedFile $file): void
    {
        $ulid = (string) Str::ulid();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $path = "avatars/{$ulid}.{$ext}";
        $avatarUrl = "/storage/{$path}";
        $oldAvatarUrl = $user->avatar_url;

        try {
            DB::transaction(function () use (
                $user,
                $file,
                $path,
                $avatarUrl,
                $oldAvatarUrl,
            ): void {
                $storedPath = Storage::disk('public')->putFileAs(
                    'avatars',
                    $file,
                    basename($path),
                );

                if ($storedPath === false) {
                    throw new RuntimeException('プロフィール画像を保存できませんでした。');
                }

                $user->update([
                    'avatar_url' => $avatarUrl,
                ]);

                // DB 更新のコミット後に既存画像を削除する。
                if ($oldAvatarUrl !== null) {
                    $oldPath = Str::after($oldAvatarUrl, '/storage/');

                    if (Str::startsWith($oldPath, 'avatars/')) {
                        DB::afterCommit(
                            fn () => Storage::disk('public')->delete($oldPath),
                        );
                    }
                }
            });
        } catch (\Throwable $e) {
            // DB 更新に失敗した場合、新しく保存した画像を残さない。
            Storage::disk('public')->delete($path);

            throw $e;
        }
    }
}
