<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 管理者向け AnnouncementController の HTTP 統合テスト。
 *
 * - 管理者による一覧・作成・詳細画面の表示
 * - お知らせ配信と配信履歴の保存
 * - FormRequest による入力バリデーション
 * - 管理者以外のアクセス制限
 * - 未認証ユーザーのログイン画面へのリダイレクト
 */
class AnnouncementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_announcement_list_for_admin(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $announcement = Announcement::factory()->create();

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.announcements.index'));

        // Assert
        $response->assertOk();
        $response->assertViewIs('announcement.management.index');
        $response->assertViewHas('announcements', function ($announcements) use ($announcement) {
            return $announcements->pluck('id')->contains($announcement->id);
        });
    }

    public function test_create_returns_announcement_form_for_admin(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.announcements.create'));

        // Assert
        $response->assertOk();
        $response->assertViewIs('announcement.management.create');
    }

    public function test_show_returns_announcement_detail_for_admin(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $announcement = Announcement::factory()->create();

        // Act
        $response = $this->actingAs($admin)
            ->get(route('admin.announcements.show', $announcement));

        // Assert
        $response->assertOk();
        $response->assertViewIs('announcement.management.show');
        $response->assertViewHas('announcement', function ($result) use ($announcement) {
            return $result->id === $announcement->id;
        });
    }

    public function test_store_creates_announcement_for_all_students(): void
    {
        // Arrange
        Notification::fake();

        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'メンテナンスのお知らせ',
                'body' => 'システムメンテナンスを実施します。',
                'target_type' => AnnouncementTargetType::AllStudents->value,
            ]);

        // Assert
        $response->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'title' => 'メンテナンスのお知らせ',
            'body' => 'システムメンテナンスを実施します。',
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => null,
            'target_user_id' => null,
            'created_by' => $admin->id,
        ]);

        $announcement = Announcement::query()
            ->where('title', 'メンテナンスのお知らせ')
            ->firstOrFail();

        $response->assertRedirect(
            route('admin.announcements.show', $announcement),
        );

        $this->assertNotNull($announcement->dispatched_at);
    }

    public function test_store_creates_announcement_for_published_certification(): void
    {
        // Arrange
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        // Act
        $response = $this->actingAs($admin)
            ->post(route('admin.announcements.store'), [
                'title' => '資格別のお知らせ',
                'body' => '指定資格の受講生向けのお知らせです。',
                'target_type' => AnnouncementTargetType::Certification->value,
                'target_certification_id' => $certification->id,
            ]);

        // Assert
        $response->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'title' => '資格別のお知らせ',
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
            'target_user_id' => null,
            'created_by' => $admin->id,
        ]);
    }

    public function test_store_creates_announcement_for_selected_student(): void
    {
        // Arrange
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();

        // Act
        $response = $this->actingAs($admin)
            ->post(route('admin.announcements.store'), [
                'title' => '個別のお知らせ',
                'body' => '個別にお知らせします。',
                'target_type' => AnnouncementTargetType::User->value,
                'target_user_id' => $student->id,
            ]);

        // Assert
        $response->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'title' => '個別のお知らせ',
            'target_type' => AnnouncementTargetType::User->value,
            'target_certification_id' => null,
            'target_user_id' => $student->id,
            'created_by' => $admin->id,
            'dispatched_count' => 1,
        ]);
    }

    public function test_store_returns_validation_error_when_title_is_missing(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();

        // Act
        $response = $this->actingAs($admin)
            ->postJson(route('admin.announcements.store'), [
                'body' => 'お知らせ本文です。',
                'target_type' => AnnouncementTargetType::AllStudents->value,
            ]);

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['title']);

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_store_rejects_draft_certification(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->draft()->create();

        // Act
        $response = $this->actingAs($admin)
            ->postJson(route('admin.announcements.store'), [
                'title' => '資格別のお知らせ',
                'body' => 'お知らせ本文です。',
                'target_type' => AnnouncementTargetType::Certification->value,
                'target_certification_id' => $certification->id,
            ]);

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'target_certification_id',
        ]);

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_store_rejects_user_who_is_not_an_in_progress_student(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        // Act
        $response = $this->actingAs($admin)
            ->postJson(route('admin.announcements.store'), [
                'title' => '個別のお知らせ',
                'body' => 'お知らせ本文です。',
                'target_type' => AnnouncementTargetType::User->value,
                'target_user_id' => $coach->id,
            ]);

        // Assert
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_user_id']);

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_coach_cannot_access_announcement_management(): void
    {
        // Arrange
        $coach = User::factory()->coach()->inProgress()->create();
        $announcement = Announcement::factory()->create();

        // Act & Assert
        $this->actingAs($coach)
            ->get(route('admin.announcements.index'))
            ->assertForbidden();

        $this->actingAs($coach)
            ->get(route('admin.announcements.create'))
            ->assertForbidden();

        $this->actingAs($coach)
            ->get(route('admin.announcements.show', $announcement))
            ->assertForbidden();

        $this->actingAs($coach)
            ->post(route('admin.announcements.store'), [
                'title' => '不正な配信',
                'body' => '管理者以外からの配信です。',
                'target_type' => AnnouncementTargetType::AllStudents->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('announcements', [
            'title' => '不正な配信',
        ]);
    }

    public function test_student_cannot_access_announcement_management(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create();
        $announcement = Announcement::factory()->create();

        // Act & Assert
        $this->actingAs($student)
            ->get(route('admin.announcements.index'))
            ->assertForbidden();

        $this->actingAs($student)
            ->get(route('admin.announcements.create'))
            ->assertForbidden();

        $this->actingAs($student)
            ->get(route('admin.announcements.show', $announcement))
            ->assertForbidden();

        $this->actingAs($student)
            ->post(route('admin.announcements.store'), [
                'title' => '不正な配信',
                'body' => '受講生からの配信です。',
                'target_type' => AnnouncementTargetType::AllStudents->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('announcements', [
            'title' => '不正な配信',
        ]);
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        // Act & Assert
        $this->get(route('admin.announcements.index'))
            ->assertRedirect(route('login'));

        $this->get(route('admin.announcements.create'))
            ->assertRedirect(route('login'));

        $this->get(route('admin.announcements.show', Announcement::factory()->create()))
            ->assertRedirect(route('login'));

        $this->post(route('admin.announcements.store'), [
            'title' => '未認証ユーザーからのお知らせ',
            'body' => 'お知らせ本文です。',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('announcements', [
            'title' => '未認証ユーザーからのお知らせ',
        ]);
    }
}