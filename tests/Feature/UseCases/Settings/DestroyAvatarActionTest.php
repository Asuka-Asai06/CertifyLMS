<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Settings;

use App\Models\User;
use App\UseCases\Settings\DestroyAvatarAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DestroyAvatarAction の責務:
 *
 * - 対象ユーザーのプロフィール画像 URL を取得
 * - DB の avatar_url を null に更新
 * - DB のコミット後に avatars/ 配下の画像ファイルを削除
 * - プロフィール画像が未設定の場合は何もしない
 * - avatars/ 配下以外のファイルは削除しない
 */
class DestroyAvatarActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_avatar_and_clears_avatar_url(): void
    {
        Storage::fake('public');

        $path = 'avatars/test-avatar.png';

        Storage::disk('public')->put($path, 'fake avatar content');

        $user = User::factory()->student()->inProgress()->create([
            'avatar_url' => "/storage/{$path}",
        ]);

        (new DestroyAvatarAction)($user);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'avatar_url' => null,
        ]);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_does_nothing_when_avatar_is_not_set(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->inProgress()->create([
            'avatar_url' => null,
        ]);

        (new DestroyAvatarAction)($user);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'avatar_url' => null,
        ]);
    }

    public function test_does_not_delete_file_outside_avatars_directory(): void
    {
        Storage::fake('public');

        $path = 'profile/test-avatar.png';

        Storage::disk('public')->put($path, 'fake avatar content');

        $user = User::factory()->student()->inProgress()->create([
            'avatar_url' => "/storage/{$path}",
        ]);

        (new DestroyAvatarAction)($user);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'avatar_url' => null,
        ]);

        Storage::disk('public')->assertExists($path);
    }
}
