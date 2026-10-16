<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * アバター画像アップロード StoreAvatarRequest の検証。
 *
 * avatar（required + image + mimes:jpg,jpeg,png,webp + max:2048）
 * InProgress / Graduated の認可と、画像形式・サイズのバリデーションを検証する。
 */
class StoreAvatarRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_valid_png(): void
    {
        // Arrange
        $user = User::factory()->student()->inProgress()->create([
            'name' => 'テストユーザー',
        ]);

        // Act
        $response = $this->actingAs($user)->postJson(
            route('settings.avatar.store'),
            [
                'avatar' => UploadedFile::fake()->image('avatar.png'),
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('settings.profile.edit'));
    }

    public function test_validation_passes_with_valid_jpg(): void
    {
        // Arrange
        $user = User::factory()->student()->inProgress()->create([
            'name' => 'テストユーザー',
        ]);

        // Act
        $response = $this->actingAs($user)->postJson(
            route('settings.avatar.store'),
            [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('settings.profile.edit'));
    }

    public function test_validation_passes_with_valid_webp(): void
    {
        // Arrange
        $user = User::factory()->student()->inProgress()->create([
            'name' => 'テストユーザー',
        ]);

        // Act
        $response = $this->actingAs($user)->postJson(
            route('settings.avatar.store'),
            [
                'avatar' => UploadedFile::fake()->image('avatar.webp'),
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('settings.profile.edit'));
    }

    #[DataProvider('invalidAvatarPayloads')]
    public function test_validation_fails_with_invalid_avatar(
        array $payload,
        string $expectedErrorField,
    ): void {
        // Arrange
        $user = User::factory()->student()->inProgress()->create([
            'name' => 'テストユーザー',
        ]);

        // Act
        $response = $this->actingAs($user)->postJson(
            route('settings.avatar.store'),
            $payload,
        );

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors($expectedErrorField);
    }

    public function test_authorize_returns_forbidden_for_invited_student(): void
    {
        // Arrange
        $user = User::factory()->student()->invited()->create([
            'name' => 'テストユーザー',
        ]);

        // Act
        $response = $this->actingAs($user)->postJson(
            route('settings.avatar.store'),
            [
                'avatar' => UploadedFile::fake()->image('avatar.png'),
            ],
        );

        // Assert
        $response->assertForbidden();
    }

    public function test_authorize_returns_forbidden_for_withdrawn_student(): void
    {
        // Arrange
        $user = User::factory()->student()->withdrawn()->create([
            'name' => 'テストユーザー',
        ]);

        // Act
        $response = $this->actingAs($user)->postJson(
            route('settings.avatar.store'),
            [
                'avatar' => UploadedFile::fake()->image('avatar.png'),
            ],
        );

        // Assert
        $response->assertForbidden();
    }

    public function test_guest_cannot_upload_avatar(): void
    {
        // Act
        $response = $this->postJson(
            route('settings.avatar.store'),
            [
                'avatar' => UploadedFile::fake()->image('avatar.png'),
            ],
        );

        // Assert
        $response->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidAvatarPayloads(): array
    {
        return [
            'avatar 未指定で 422' => [
                [],
                'avatar',
            ],
            'avatar null で 422' => [
                ['avatar' => null],
                'avatar',
            ],
            'avatar 画像以外で 422' => [
                ['avatar' => UploadedFile::fake()->create(
                    'document.pdf',
                    100,
                    'application/pdf',
                )],
                'avatar',
            ],
            'avatar gif で 422' => [
                ['avatar' => UploadedFile::fake()->image('avatar.gif')],
                'avatar',
            ],
            'avatar 2MB 超過で 422' => [
                ['avatar' => UploadedFile::fake()->image('avatar.png')->size(2049)],
                'avatar',
            ],
        ];
    }
}
