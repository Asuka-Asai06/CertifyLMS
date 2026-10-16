<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * プロフィール更新 UpdateProfileRequest のバリデーション検証。
 *
 * name (required + string + max:50)
 * bio (nullable + string + max:1000)
 * meeting_url (コーチのみ nullable + string + url + max:500)
 *
 * 認証ユーザーのロールに応じたバリデーションと、
 * InProgress / Graduated の認可を検証する。
 */
class UpdateProfileRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_valid_student_payload(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create([
            'name' => 'テストユーザー',
        ]);

        // Act
        $response = $this->actingAs($student)->patch(
            route('settings.profile.update'),
            [
                'name' => 'テストユーザー',
                'bio' => '自己紹介です。',
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => 'テストユーザー',
            'bio' => '自己紹介です。',
        ]);
    }

    public function test_validation_passes_with_valid_coach_meeting_url(): void
    {
        // Arrange
        $coach = User::factory()->coach()->create([
            'name' => 'テストユーザー',
        ]);

        // Act
        $response = $this->actingAs($coach)->patch(
            route('settings.profile.update'),
            [
                'name' => 'テストユーザー',
                'bio' => '自己紹介です。',
                'meeting_url' => 'https://example.com/meeting',
            ],
        );

        // Assert
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('users', [
            'id' => $coach->id,
            'meeting_url' => 'https://example.com/meeting',
        ]);
    }

    #[DataProvider('invalidStudentPayloads')]
    public function test_student_validation_fails(
        array $overrides,
        string $expectedErrorField,
    ): void {
        // Arrange
        $student = User::factory()->student()->inProgress()->create([
            'name' => 'テストユーザー',
        ]);

        $payload = array_merge([
            'name' => 'テストユーザー',
            'bio' => '自己紹介です。',
        ], $overrides);

        // Act
        $response = $this->actingAs($student)->patchJson(
            route('settings.profile.update'),
            $payload,
        );

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors($expectedErrorField);
    }

    #[DataProvider('invalidCoachMeetingUrls')]
    public function test_coach_meeting_url_validation_fails(
        array $overrides,
        string $expectedErrorField,
    ): void {
        // Arrange
        $coach = User::factory()->coach()->create([
            'name' => 'テストユーザー',
        ]);

        $payload = array_merge([
            'name' => 'テストユーザー',
            'bio' => '自己紹介です。',
            'meeting_url' => 'https://example.com/meeting',
        ], $overrides);

        // Act
        $response = $this->actingAs($coach)->patchJson(
            route('settings.profile.update'),
            $payload,
        );

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors($expectedErrorField);
    }

    public function test_authorize_returns_forbidden_for_invited_student(): void
    {
        // Arrange
        $student = User::factory()->student()->invited()->create([
            'name' => 'テストユーザー',
        ]);

        // Act
        $response = $this->actingAs($student)->patchJson(
            route('settings.profile.update'),
            [
                'name' => 'テストユーザー',
                'bio' => '自己紹介です。',
            ],
        );

        // Assert
        $response->assertForbidden();
    }

    public function test_authorize_returns_forbidden_for_withdrawn_student(): void
    {
        // Arrange
        $student = User::factory()->student()->withdrawn()->create([
            'name' => 'テストユーザー',
        ]);

        // Act
        $response = $this->actingAs($student)->patchJson(
            route('settings.profile.update'),
            [
                'name' => 'テストユーザー',
                'bio' => '自己紹介です。',
            ],
        );

        // Assert
        $response->assertForbidden();
    }

    public function test_guest_cannot_update_profile(): void
    {
        // Act
        $response = $this->patchJson(
            route('settings.profile.update'),
            [
                'name' => 'テストユーザー',
                'bio' => '自己紹介です。',
            ],
        );

        // Assert
        $response->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidStudentPayloads(): array
    {
        return [
            'name 未指定で 422' => [
                ['name' => ''],
                'name',
            ],
            'name 51 文字で 422' => [
                ['name' => str_repeat('あ', 51)],
                'name',
            ],
            'name 配列で 422' => [
                ['name' => ['テストユーザー']],
                'name',
            ],
            'bio 1001 文字で 422' => [
                ['bio' => str_repeat('あ', 1001)],
                'bio',
            ],
            'bio 配列で 422' => [
                ['bio' => ['自己紹介です。']],
                'bio',
            ],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidCoachMeetingUrls(): array
    {
        return [
            'meeting_url 不正な URL で 422' => [
                ['meeting_url' => 'invalid-url'],
                'meeting_url',
            ],
            'meeting_url 501 文字以上で 422' => [
                ['meeting_url' => 'https://example.com/'.str_repeat('a', 490)],
                'meeting_url',
            ],
            'meeting_url 配列で 422' => [
                ['meeting_url' => ['https://example.com/meeting']],
                'meeting_url',
            ],
        ];
    }
}
