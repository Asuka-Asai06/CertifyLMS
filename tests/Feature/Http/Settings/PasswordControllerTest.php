<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_password(): void
    {
        $currentPassword = 'Current-password-123';
        $newPassword = 'New-password-123';

        $user = User::factory()
            ->student()
            ->inProgress()
            ->create([
                'password' => $currentPassword,
            ]);

        $response = $this->actingAs($user)
            ->put(route('settings.password.update'), [
                'current_password' => $currentPassword,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]);

        $response->assertRedirect(
            route('settings.profile.edit', ['tab' => 'password']),
        );

        $response->assertSessionHas(
            'success',
            'パスワードを変更しました。',
        );

        $this->assertTrue(
            Hash::check($newPassword, $user->refresh()->password),
        );
    }

    public function test_guest_cannot_update_password(): void
    {
        $response = $this->put(route('settings.password.update'), [
            'current_password' => 'Current-password-123',
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_user_cannot_update_password_with_incorrect_current_password(): void
    {
        $currentPassword = 'Current-password-123';

        $user = User::factory()
            ->student()
            ->inProgress()
            ->create([
                'password' => $currentPassword,
            ]);

        $response = $this->actingAs($user)
            ->from(route('settings.profile.edit', ['tab' => 'password']))
            ->put(route('settings.password.update'), [
                'current_password' => 'Wrong-password-123',
                'password' => 'New-password-123',
                'password_confirmation' => 'New-password-123',
            ]);

        $response->assertRedirect(
            route('settings.profile.edit', ['tab' => 'password']),
        );

        $response->assertSessionHasErrorsIn(
            'updatePassword',
            'current_password',
        );

        $this->assertTrue(
            Hash::check($currentPassword, $user->refresh()->password),
        );
    }

    public function test_user_cannot_update_password_when_confirmation_does_not_match(): void
    {
        $currentPassword = 'Current-password-123';

        $user = User::factory()
            ->student()
            ->inProgress()
            ->create([
                'password' => $currentPassword,
            ]);

        $response = $this->actingAs($user)
            ->from(route('settings.profile.edit', ['tab' => 'password']))
            ->put(route('settings.password.update'), [
                'current_password' => $currentPassword,
                'password' => 'New-password-123',
                'password_confirmation' => 'Different-password-123',
            ]);

        $response->assertRedirect(
            route('settings.profile.edit', ['tab' => 'password']),
        );

        $response->assertSessionHasErrorsIn(
            'updatePassword',
            'password',
        );

        $this->assertTrue(
            Hash::check($currentPassword, $user->refresh()->password),
        );
    }
}
