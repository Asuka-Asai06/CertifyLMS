<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ProfileController の HTTP 統合テスト。
 * 認可漏れ / FormRequest バリデーション失敗 / 代表的な正常系を網羅する。
 */
class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_profile_settings(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($user)
            ->get(route('settings.profile.edit'));

        $response->assertOk();
        $response->assertViewIs('settings.profile');
    }

    public function test_user_can_update_name_and_bio(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($user)
            ->patch(route('settings.profile.update'), [
                'name' => '更新後の名前',
                'bio' => '更新後の自己紹介',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => '更新後の名前',
            'bio' => '更新後の自己紹介',
        ]);
    }

    public function test_user_can_clear_bio(): void
    {
        $user = User::factory()
            ->student()
            ->inProgress()
            ->create([
                'bio' => '以前の自己紹介',
            ]);

        $this->actingAs($user)
            ->patch(route('settings.profile.update'), [
                'name' => $user->name,
                'bio' => '',
            ])
            ->assertRedirect();

        $this->assertNull($user->fresh()->bio);
    }

    public function test_user_cannot_change_email_role_or_status_through_profile_update(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $originalEmail = $user->email;
        $originalRole = $user->role;
        $originalStatus = $user->status;

        $response = $this->actingAs($user)
            ->patch(route('settings.profile.update'), [
                'name' => '更新後の名前',
                'bio' => '更新後の自己紹介',
                'email' => 'changed@example.com',
                'role' => UserRole::Admin->value,
                'status' => UserStatus::Graduated->value,
            ]);

        $response->assertRedirect();

        $user->refresh();

        $this->assertSame($originalEmail, $user->email);
        $this->assertSame($originalRole, $user->role);
        $this->assertSame($originalStatus, $user->status);
        $this->assertSame('更新後の名前', $user->name);
    }

    public function test_coach_can_update_meeting_url(): void
    {
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create([
                'meeting_url' => 'https://example.com/old-meeting',
            ]);

        $response = $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => $coach->name,
                'bio' => $coach->bio,
                'meeting_url' => 'https://example.com/new-meeting',
            ]);

        $response->assertRedirect();

        $this->assertSame(
            'https://example.com/new-meeting',
            $coach->fresh()->meeting_url,
        );
    }

    public function test_coach_can_clear_meeting_url_with_empty_string(): void
    {
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create([
                'meeting_url' => 'https://example.com/meeting',
            ]);

        $response = $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => $coach->name,
                'bio' => $coach->bio,
                'meeting_url' => '',
            ]);

        $response->assertRedirect();

        $this->assertNull($coach->fresh()->meeting_url);
    }

    public function test_coach_can_clear_meeting_url_with_null(): void
    {
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create([
                'meeting_url' => 'https://example.com/meeting',
            ]);

        $response = $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => $coach->name,
                'bio' => $coach->bio,
                'meeting_url' => null,
            ]);

        $response->assertRedirect();

        $this->assertNull($coach->fresh()->meeting_url);
    }

    public function test_invalid_meeting_url_is_rejected_for_coach(): void
    {
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create([
                'meeting_url' => 'https://example.com/meeting',
            ]);

        $response = $this->actingAs($coach)
            ->patch(route('settings.profile.update'), [
                'name' => $coach->name,
                'bio' => $coach->bio,
                'meeting_url' => 'not-a-url',
            ]);

        $response->assertSessionHasErrors('meeting_url');

        $this->assertSame(
            'https://example.com/meeting',
            $coach->fresh()->meeting_url,
        );
    }

    public function test_student_cannot_change_meeting_url(): void
    {
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create([
                'meeting_url' => null,
            ]);

        $this->actingAs($student)
            ->patch(route('settings.profile.update'), [
                'name' => $student->name,
                'bio' => $student->bio,
                'meeting_url' => 'https://example.com/meeting',
            ])
            ->assertRedirect();

        $this->assertNull($student->fresh()->meeting_url);
    }

    public function test_admin_cannot_change_meeting_url(): void
    {
        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create([
                'meeting_url' => null,
            ]);

        $this->actingAs($admin)
            ->patch(route('settings.profile.update'), [
                'name' => $admin->name,
                'bio' => $admin->bio,
                'meeting_url' => 'https://example.com/meeting',
            ])
            ->assertRedirect();

        $this->assertNull($admin->fresh()->meeting_url);
    }

    public function test_name_is_required(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($user)
            ->patch(route('settings.profile.update'), [
                'name' => '',
                'bio' => $user->bio,
            ]);

        $response->assertSessionHasErrors('name');

        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_name_cannot_exceed_100_characters(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($user)
            ->patch(route('settings.profile.update'), [
                'name' => str_repeat('a', 101),
                'bio' => $user->bio,
            ]);

        $response->assertSessionHasErrors('name');

        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_bio_cannot_exceed_1000_characters(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($user)
            ->patch(route('settings.profile.update'), [
                'name' => $user->name,
                'bio' => str_repeat('a', 1001),
            ]);

        $response->assertSessionHasErrors('bio');

        $this->assertSame($user->bio, $user->fresh()->bio);
    }

    public function test_graduated_student_can_view_profile_settings(): void
    {
        $student = User::factory()->student()->graduated()->create();

        $response = $this->actingAs($student)
            ->get(route('settings.profile.edit'));

        $response->assertOk();
    }

    public function test_graduated_student_can_update_profile(): void
    {
        $student = User::factory()->student()->graduated()->create();

        $response = $this->actingAs($student)
            ->patch(route('settings.profile.update'), [
                'name' => '卒業後の名前変更',
                'bio' => '卒業後の自己紹介変更',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => '卒業後の名前変更',
            'bio' => '卒業後の自己紹介変更',
        ]);
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get(route('settings.profile.edit'));

        $response->assertRedirect('/login');
    }

    public function test_unauthenticated_user_cannot_update_profile(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $response = $this->patch(route('settings.profile.update'), [
            'name' => '不正な更新',
            'bio' => '不正な自己紹介',
        ]);

        $response->assertRedirect('/login');

        $this->assertNotSame('不正な更新', $user->fresh()->name);
    }

    public function test_invited_student_cannot_update_profile(): void
    {
        $student = User::factory()->student()->invited()->create();

        $originalName = $student->name;

        $response = $this->actingAs($student)
            ->patch(route('settings.profile.update'), [
                'name' => '更新後の名前',
                'bio' => '更新後の自己紹介',
            ]);

        $response->assertForbidden();

        $this->assertSame($originalName, $student->fresh()->name);
    }

    public function test_withdrawn_student_cannot_update_profile(): void
    {
        $student = User::factory()->student()->withdrawn()->create();

        $originalName = $student->name;

        $response = $this->actingAs($student)
            ->patch(route('settings.profile.update'), [
                'name' => '更新後の名前',
                'bio' => '更新後の自己紹介',
            ]);
        $response->assertForbidden();

        $this->assertSame($originalName, $student->fresh()->name);
    }

    public function test_user_can_upload_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->inProgress()->create();
        $avatar = UploadedFile::fake()->image('avatar.png');

        $response = $this->actingAs($user)
            ->post(route('settings.avatar.store'), [
                'avatar' => $avatar,
            ]);

        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHas(
            'success',
            'アバター画像を更新しました。',
        );

        $user->refresh();

        $this->assertNotNull($user->avatar_url);
        $this->assertStringStartsWith(
            '/storage/avatars/',
            $user->avatar_url,
        );

        $path = str_replace('/storage/', '', $user->avatar_url);

        Storage::disk('public')->assertExists($path);
    }

    public function test_user_can_delete_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->inProgress()->create();

        $path = 'avatars/test-avatar.png';

        Storage::disk('public')->put($path, 'fake avatar content');

        $user->update([
            'avatar_url' => "/storage/{$path}",
        ]);

        $response = $this->actingAs($user)
            ->delete(route('settings.avatar.destroy'));

        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHas(
            'success',
            'アバター画像を削除しました。',
        );

        $user->refresh();

        $this->assertNull($user->avatar_url);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_guest_cannot_upload_avatar(): void
    {
        $response = $this->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_guest_cannot_delete_avatar(): void
    {
        $response = $this->delete(route('settings.avatar.destroy'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_cannot_upload_non_image_file_as_avatar(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($user)
            ->from(route('settings.profile.edit'))
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->create(
                    'document.pdf',
                    100,
                    'application/pdf',
                ),
            ]);

        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHasErrors('avatar');
    }

    public function test_user_cannot_upload_avatar_larger_than_2mb(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($user)
            ->from(route('settings.profile.edit'))
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('avatar.png')->size(2049),
            ]);

        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHasErrors('avatar');
    }

    public function test_user_must_provide_avatar_file(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($user)
            ->from(route('settings.profile.edit'))
            ->post(route('settings.avatar.store'), []);

        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHasErrors('avatar');
    }

    public function test_invited_student_cannot_upload_avatar(): void
    {
        $user = User::factory()->student()->invited()->create();

        $response = $this->actingAs($user)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('avatar.png'),
            ]);

        $response->assertForbidden();
    }

    public function test_withdrawn_student_cannot_upload_avatar(): void
    {
        $user = User::factory()->student()->withdrawn()->create();

        $response = $this->actingAs($user)
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('avatar.png'),
            ]);

        $response->assertForbidden();
    }
}
