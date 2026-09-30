<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaThreadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaThreadPolicy の ability × Role × 資格状態 × 投稿者のマトリクス検証。
 *
 * view (admin=全件 / coach=担当中かつpublished / student=published のみ) /
 * create (student のみ) /
 * update (student かつ自分の質問のみ) /
 * delete (admin=全件 / student=自分の質問のみ) /
 * resolve (student かつ自分の質問のみ) /
 * unresolve (student かつ自分の質問のみ)
 * の 6 ability を網羅する。
 *
 * すべての ability で UserStatus::InProgress を要求し、
 * Graduated などの受講中ではないユーザーは不許可とする。
 */
class QaThreadPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_can_view_any_thread(): void
    {
        // Arrange
        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        $draft = Certification::factory()->create([
            'status' => CertificationStatus::Draft,
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $draft->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->view($admin, $thread);

        // Assert
        $this->assertTrue(
            $result,
            '受講中の管理者は資格の公開状態に関係なく質問を閲覧できるはず',
        );
    }

    public function test_assigned_active_coach_can_view_published_thread(): void
    {
        // Arrange
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create();

        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        $certification = Certification::factory()->create([
            'status' => CertificationStatus::Published,
        ]);

        $certification->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->view($coach, $thread);

        // Assert
        $this->assertTrue(
            $result,
            '担当中かつ受講中のコーチは公開中資格の質問を閲覧できるはず',
        );
    }

    public function test_active_coach_cannot_view_thread_for_unassigned_certification(): void
    {
        // Arrange
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create();

        $certification = Certification::factory()->create([
            'status' => CertificationStatus::Published,
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->view($coach, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '担当外の資格に紐づく質問はコーチが閲覧できないはず',
        );
    }

    public function test_assigned_active_coach_cannot_view_unpublished_thread(): void
    {
        // Arrange
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create();

        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        $certification = Certification::factory()->create([
            'status' => CertificationStatus::Draft,
        ]);

        $certification->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->view($coach, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '担当中のコーチでも非公開資格の質問は閲覧できないはず',
        );
    }

    public function test_active_student_can_view_published_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $certification = Certification::factory()->create([
            'status' => CertificationStatus::Published,
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->view($student, $thread);

        // Assert
        $this->assertTrue(
            $result,
            '受講中の学生は公開中資格の質問を閲覧できるはず',
        );
    }

    public function test_active_student_cannot_view_unpublished_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $certification = Certification::factory()->create([
            'status' => CertificationStatus::Draft,
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->view($student, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '受講中の学生でも非公開資格の質問は閲覧できないはず',
        );
    }

    public function test_inactive_user_cannot_view_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->create([
                'status' => UserStatus::Graduated,
            ]);

        $certification = Certification::factory()->create([
            'status' => CertificationStatus::Published,
        ]);

        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->view($student, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '受講中ではないユーザーは質問を閲覧できないはず',
        );
    }

    public function test_active_student_can_create_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        // Act
        $result = (new QaThreadPolicy)->create($student);

        // Assert
        $this->assertTrue(
            $result,
            '受講中の学生は質問を作成できるはず',
        );
    }

    public function test_coach_cannot_create_thread(): void
    {
        // Arrange
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create();

        // Act
        $result = (new QaThreadPolicy)->create($coach);

        // Assert
        $this->assertFalse(
            $result,
            'コーチは質問を作成できないはず',
        );
    }

    public function test_admin_cannot_create_thread(): void
    {
        // Arrange
        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        // Act
        $result = (new QaThreadPolicy)->create($admin);

        // Assert
        $this->assertFalse(
            $result,
            '管理者は質問を作成できないはず',
        );
    }

    public function test_inactive_student_cannot_create_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->create([
                'status' => UserStatus::Graduated,
            ]);

        // Act
        $result = (new QaThreadPolicy)->create($student);

        // Assert
        $this->assertFalse(
            $result,
            '受講中ではない学生は質問を作成できないはず',
        );
    }

    public function test_active_student_can_update_own_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->update($student, $thread);

        // Assert
        $this->assertTrue(
            $result,
            '受講中の学生は自分の質問を編集できるはず',
        );
    }

    public function test_active_student_cannot_update_other_student_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $otherStudent = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create([
            'user_id' => $otherStudent->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->update($student, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '学生は他の学生の質問を編集できないはず',
        );
    }

    public function test_coach_cannot_update_thread(): void
    {
        // Arrange
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create();

        // Act
        $result = (new QaThreadPolicy)->update($coach, $thread);

        // Assert
        $this->assertFalse(
            $result,
            'コーチは質問を編集できないはず',
        );
    }

    public function test_admin_cannot_update_thread(): void
    {
        // Arrange
        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create();

        // Act
        $result = (new QaThreadPolicy)->update($admin, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '管理者は質問本文を編集できないはず',
        );
    }

    public function test_inactive_student_cannot_update_own_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->create([
                'status' => UserStatus::Graduated,
            ]);

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->update($student, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '受講中ではない学生は自分の質問も編集できないはず',
        );
    }

    public function test_active_admin_can_delete_any_thread(): void
    {
        // Arrange
        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create();

        // Act
        $result = (new QaThreadPolicy)->delete($admin, $thread);

        // Assert
        $this->assertTrue(
            $result,
            '受講中の管理者は任意の質問を削除できるはず',
        );
    }

    public function test_active_student_can_delete_own_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->delete($student, $thread);

        // Assert
        $this->assertTrue(
            $result,
            '受講中の学生は自分の質問を削除できるはず',
        );
    }

    public function test_active_student_cannot_delete_other_student_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $otherStudent = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create([
            'user_id' => $otherStudent->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->delete($student, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '学生は他の学生の質問を削除できないはず',
        );
    }

    public function test_coach_cannot_delete_thread(): void
    {
        // Arrange
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create();

        // Act
        $result = (new QaThreadPolicy)->delete($coach, $thread);

        // Assert
        $this->assertFalse(
            $result,
            'コーチは質問を削除できないはず',
        );
    }

    public function test_inactive_admin_cannot_delete_thread(): void
    {
        // Arrange
        $admin = User::factory()
            ->admin()
            ->create([
                'status' => UserStatus::Graduated,
            ]);

        $thread = QaThread::factory()->create();

        // Act
        $result = (new QaThreadPolicy)->delete($admin, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '受講中ではない管理者は質問を削除できないはず',
        );
    }

    public function test_active_student_can_resolve_own_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->resolve($student, $thread);

        // Assert
        $this->assertTrue(
            $result,
            '受講中の学生は自分の質問を解決済みにできるはず',
        );
    }

    public function test_active_student_cannot_resolve_other_student_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $otherStudent = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create([
            'user_id' => $otherStudent->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->resolve($student, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '学生は他の学生の質問を解決済みにできないはず',
        );
    }

    public function test_coach_cannot_resolve_thread(): void
    {
        // Arrange
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create();

        // Act
        $result = (new QaThreadPolicy)->resolve($coach, $thread);

        // Assert
        $this->assertFalse(
            $result,
            'コーチは質問を解決済みにできないはず',
        );
    }

    public function test_admin_cannot_resolve_thread(): void
    {
        // Arrange
        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()->create();

        // Act
        $result = (new QaThreadPolicy)->resolve($admin, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '管理者は他人の質問を解決済みにできないはず',
        );
    }

    public function test_inactive_student_cannot_resolve_own_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->create([
                'status' => UserStatus::Graduated,
            ]);

        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
        ]);

        // Act
        $result = (new QaThreadPolicy)->resolve($student, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '受講中ではない学生は自分の質問も解決済みにできないはず',
        );
    }

    public function test_active_student_can_unresolve_own_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()
            ->resolved()
            ->create([
                'user_id' => $student->id,
            ]);

        // Act
        $result = (new QaThreadPolicy)->unresolve($student, $thread);

        // Assert
        $this->assertTrue(
            $result,
            '受講中の学生は自分の質問を未解決に戻せるはず',
        );
    }

    public function test_active_student_cannot_unresolve_other_student_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $otherStudent = User::factory()
            ->student()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()
            ->resolved()
            ->create([
                'user_id' => $otherStudent->id,
            ]);

        // Act
        $result = (new QaThreadPolicy)->unresolve($student, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '学生は他の学生の質問を未解決に戻せないはず',
        );
    }

    public function test_coach_cannot_unresolve_thread(): void
    {
        // Arrange
        $coach = User::factory()
            ->coach()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()
            ->resolved()
            ->create();

        // Act
        $result = (new QaThreadPolicy)->unresolve($coach, $thread);

        // Assert
        $this->assertFalse(
            $result,
            'コーチは質問を未解決に戻せないはず',
        );
    }

    public function test_admin_cannot_unresolve_thread(): void
    {
        // Arrange
        $admin = User::factory()
            ->admin()
            ->inProgress()
            ->create();

        $thread = QaThread::factory()
            ->resolved()
            ->create();

        // Act
        $result = (new QaThreadPolicy)->unresolve($admin, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '管理者は他人の質問を未解決に戻せないはず',
        );
    }

    public function test_inactive_student_cannot_unresolve_own_thread(): void
    {
        // Arrange
        $student = User::factory()
            ->student()
            ->create([
                'status' => UserStatus::Graduated,
            ]);

        $thread = QaThread::factory()
            ->resolved()
            ->create([
                'user_id' => $student->id,
            ]);

        // Act
        $result = (new QaThreadPolicy)->unresolve($student, $thread);

        // Assert
        $this->assertFalse(
            $result,
            '受講中ではない学生は自分の質問を未解決に戻せないはず',
        );
    }
}
