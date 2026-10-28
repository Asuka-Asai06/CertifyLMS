<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\User;
use App\Policies\CertificatePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CertificatePolicy のダウンロード認可を検証する Unit テスト。
 *
 * admin: すべて許可
 * coach: 担当資格の修了証のみ許可
 * student: 自分の修了証のみ許可
 */
class CertificatePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_download_any_certificate(): void
    {
        $admin = User::factory()->admin()->create();
        $certificate = $this->createCertificate();
        $policy = new CertificatePolicy;

        $this->assertTrue($policy->download($admin, $certificate));
    }

    public function test_student_can_download_own_certificate(): void
    {
        $student = User::factory()->student()->create();
        $certificate = $this->createCertificate($student);
        $policy = new CertificatePolicy;

        $this->assertTrue($policy->download($student, $certificate));
    }

    public function test_student_cannot_download_other_students_certificate(): void
    {
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $certificate = $this->createCertificate($otherStudent);
        $policy = new CertificatePolicy;

        $this->assertFalse($policy->download($student, $certificate));
    }

    public function test_assigned_coach_can_download_certificate(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        $certificate = $this->createCertificate(
            certification: $certification,
        );

        $policy = new CertificatePolicy;

        $this->assertTrue($policy->download($coach, $certificate));
    }

    public function test_coach_cannot_download_unassigned_certification_certificate(): void
    {
        $coach = User::factory()->coach()->create();
        $certificate = $this->createCertificate();
        $policy = new CertificatePolicy;

        $this->assertFalse($policy->download($coach, $certificate));
    }

    public function test_unassigned_coach_cannot_download_certificate_after_unassignment(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        $assignment = CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now()->subDay(),
            'unassigned_at' => null,
        ]);

        $certificate = $this->createCertificate(
            certification: $certification,
        );

        // 担当解除済みにする
        $assignment->update(['unassigned_at' => now()]);

        $policy = new CertificatePolicy;

        $this->assertFalse($policy->download($coach, $certificate));
    }

    /**
     * テスト用の修了証を作成する。
     */
    private function createCertificate(
        ?User $student = null,
        ?Certification $certification = null,
    ): Certificate {
        $student ??= User::factory()->student()->create();
        $certification ??= Certification::factory()->published()->create();

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->passed()
            ->create();

        return Certificate::create([
            'user_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'certification_id' => $certification->id,
            'pdf_path' => 'certificates/'.Str::ulid().'.pdf',
            'issued_at' => now(),
        ]);
    }
}
