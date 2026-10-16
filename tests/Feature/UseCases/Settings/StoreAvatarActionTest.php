<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Settings;

use App\Models\User;
use App\UseCases\Settings\StoreAvatarAction;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * StoreAvatarAction の責務:
 *
 * - アップロードされた画像から ULID と拡張子を使って保存先パスを生成
 * - 新しいプロフィール画像を public disk の avatars/ 配下に保存
 * - DB の avatar_url を新しい画像の公開用パスに更新
 * - 既存画像が avatars/ 配下にある場合、DB のコミット後に削除
 * - 画像の保存に失敗した場合は例外を送出
 * - DB 更新に失敗した場合は新しく保存した画像を削除し、例外を再送出
 */
class StoreAvatarActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_avatar_and_updates_avatar_url(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->inProgress()->create();

        $file = UploadedFile::fake()->image('avatar.png');

        (new StoreAvatarAction)($user, $file);

        $user->refresh();

        $this->assertNotNull($user->avatar_url);
        $this->assertMatchesRegularExpression(
            '#^/storage/avatars/[0-9A-HJKMNP-TV-Z]{26}\.png$#',
            $user->avatar_url,
        );

        Storage::disk('public')->assertExists(
            str_replace('/storage/', '', $user->avatar_url),
        );
    }

    public function test_deletes_old_avatar_after_storing_new_avatar(): void
    {
        Storage::fake('public');

        $oldPath = 'avatars/old-avatar.png';

        Storage::disk('public')->put($oldPath, 'old avatar content');

        $user = User::factory()->student()->inProgress()->create([
            'avatar_url' => "/storage/{$oldPath}",
        ]);

        $file = UploadedFile::fake()->image('new-avatar.png');

        (new StoreAvatarAction)($user, $file);

        $user->refresh();

        $this->assertNotSame(
            "/storage/{$oldPath}",
            $user->avatar_url,
        );

        Storage::disk('public')->assertMissing($oldPath);

        Storage::disk('public')->assertExists(
            str_replace('/storage/', '', $user->avatar_url),
        );
    }

    public function test_does_not_delete_old_file_outside_avatars_directory(): void
    {
        Storage::fake('public');

        $oldPath = 'profile/old-avatar.png';

        Storage::disk('public')->put($oldPath, 'old avatar content');

        $user = User::factory()->student()->inProgress()->create([
            'avatar_url' => "/storage/{$oldPath}",
        ]);

        $file = UploadedFile::fake()->image('new-avatar.png');

        (new StoreAvatarAction)($user, $file);

        $user->refresh();

        Storage::disk('public')->assertExists($oldPath);

        Storage::disk('public')->assertExists(
            str_replace('/storage/', '', $user->avatar_url),
        );
    }

    public function test_throws_when_avatar_cannot_be_stored(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $disk = \Mockery::mock(
            Filesystem::class,
        );

        $disk->shouldReceive('putFileAs')
            ->once()
            ->andReturn(false);

        $disk->shouldReceive('delete')
            ->once()
            ->with(\Mockery::type('string'))
            ->andReturn(true);

        Storage::shouldReceive('disk')
            ->with('public')
            ->twice()
            ->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'プロフィール画像を保存できませんでした。',
        );

        (new StoreAvatarAction)(
            $user,
            UploadedFile::fake()->image('avatar.png'),
        );
    }

    public function test_deletes_new_avatar_when_database_update_fails(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->inProgress()->create();

        User::updating(function (): void {
            throw new \RuntimeException('DB 更新に失敗しました。');
        });

        try {
            try {
                (new StoreAvatarAction)(
                    $user,
                    UploadedFile::fake()->image('avatar.png'),
                );

                $this->fail('例外が送出されることを期待しています。');
            } catch (\RuntimeException $e) {
                $this->assertSame(
                    'DB 更新に失敗しました。',
                    $e->getMessage(),
                );
            }

            $this->assertNull($user->fresh()->avatar_url);
            $this->assertSame(
                [],
                Storage::disk('public')->allFiles('avatars'),
            );
        } finally {
            User::flushEventListeners();
        }
    }
}
