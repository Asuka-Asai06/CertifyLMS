<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Settings;

use App\Enums\UserRole;
use App\Models\User;
use App\UseCases\Settings\UpdateProfileAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UpdateProfileAction の責務:
 *
 * - 対象ユーザーの名前と自己紹介を更新
 * - 面談URLはコーチ本人の場合に限り、入力データに含まれていれば更新
 * - 名前・自己紹介・面談URL以外の属性は更新しない
 * - 面談URLが入力データに含まれていない場合は既存値を維持
 */
class UpdateProfileActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_name_and_bio(): void
    {
        $user = User::factory()->student()->inProgress()->create([
            'name' => '変更前の名前',
            'bio' => '変更前の自己紹介',
        ]);

        (new UpdateProfileAction)($user, [
            'name' => '変更後の名前',
            'bio' => '変更後の自己紹介',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => '変更後の名前',
            'bio' => '変更後の自己紹介',
        ]);
    }

    public function test_can_clear_bio(): void
    {
        $user = User::factory()->student()->inProgress()->create([
            'bio' => '変更前の自己紹介',
        ]);

        (new UpdateProfileAction)($user, [
            'name' => $user->name,
            'bio' => null,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bio' => null,
        ]);
    }

    public function test_does_not_update_meeting_url_for_student(): void
    {
        $user = User::factory()->student()->inProgress()->create([
            'meeting_url' => 'https://example.com/old-meeting',
        ]);

        (new UpdateProfileAction)($user, [
            'name' => $user->name,
            'bio' => $user->bio,
            'meeting_url' => 'https://example.com/new-meeting',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'meeting_url' => 'https://example.com/old-meeting',
        ]);
    }

    public function test_updates_meeting_url_for_coach(): void
    {
        $user = User::factory()->coach()->create([
            'meeting_url' => 'https://example.com/old-meeting',
        ]);

        (new UpdateProfileAction)($user, [
            'name' => $user->name,
            'bio' => $user->bio,
            'meeting_url' => 'https://example.com/new-meeting',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'meeting_url' => 'https://example.com/new-meeting',
        ]);
    }

    public function test_can_clear_coach_meeting_url(): void
    {
        $user = User::factory()->coach()->create([
            'meeting_url' => 'https://example.com/old-meeting',
        ]);

        (new UpdateProfileAction)($user, [
            'name' => $user->name,
            'bio' => $user->bio,
            'meeting_url' => null,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'meeting_url' => null,
        ]);
    }

    public function test_does_not_update_meeting_url_when_not_provided(): void
    {
        $user = User::factory()->coach()->create([
            'meeting_url' => 'https://example.com/old-meeting',
        ]);

        (new UpdateProfileAction)($user, [
            'name' => '変更後の名前',
            'bio' => '変更後の自己紹介',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => '変更後の名前',
            'bio' => '変更後の自己紹介',
            'meeting_url' => 'https://example.com/old-meeting',
        ]);
    }

    public function test_does_not_update_attributes_not_allowed_by_action(): void
    {
        $user = User::factory()->student()->inProgress()->create([
            'name' => '変更前の名前',
            'role' => UserRole::Student,
        ]);

        (new UpdateProfileAction)($user, [
            'name' => '変更後の名前',
            'role' => UserRole::Admin,
            'status' => 'graduated',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => '変更後の名前',
            'role' => UserRole::Student->value,
        ]);
    }
}
