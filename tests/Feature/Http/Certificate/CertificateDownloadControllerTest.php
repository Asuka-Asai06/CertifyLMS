<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certificate;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 修了証 PDF ダウンロードの HTTP 統合テスト。
 *
 * 認可、ファイルの存在確認、ダウンロードレスポンスを検証する。
 */
class CertificateDownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_admin_can_download_certificate(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $certificate = $this->createCertificate();

        Storage::disk('private')->put($certificate->pdf_path, '%PDF-test');

        $response = $this->actingAs($admin)
            ->get(route('certificates.download', $certificate));

        $response->assertDownload(
            'certificate-'.$certificate->id.'.pdf'
        );
    }

    public function test_owner_student_can_download_certificate(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->createCertificate($student);

        Storage::disk('private')->put($certificate->pdf_path, '%PDF-test');

        $response = $this->actingAs($student)
            ->get(route('certificates.download', $certificate));

        $response->assertDownload(
            'certificate-'.$certificate->id.'.pdf'
        );
    }

    public function test_other_student_cannot_download_certificate(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();

        $certificate = $this->createCertificate($otherStudent);

        Storage::disk('private')->put($certificate->pdf_path, '%PDF-test');

        $response = $this->actingAs($student)
            ->get(route('certificates.download', $certificate));

        $response->assertForbidden();
    }

    public function test_assigned_coach_can_download_certificate(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $certification->coaches()->attach($coach->id, [
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        $certificate = $this->createCertificate(
            certification: $certification,
        );

        Storage::disk('private')->put($certificate->pdf_path, '%PDF-test');

        $response = $this->actingAs($coach)
            ->get(route('certificates.download', $certificate));

        $response->assertDownload(
            'certificate-'.$certificate->id.'.pdf'
        );
    }

    public function test_unassigned_coach_cannot_download_certificate(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certificate = $this->createCertificate();

        Storage::disk('private')->put($certificate->pdf_path, '%PDF-test');

        $response = $this->actingAs($coach)
            ->get(route('certificates.download', $certificate));

        $response->assertForbidden();
    }

    public function test_returns_404_when_pdf_does_not_exist(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->createCertificate($student);

        // PDF ファイルは作成しない

        $response = $this->actingAs($student)
            ->get(route('certificates.download', $certificate));

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $certificate = $this->createCertificate();

        $response = $this->get(
            route('certificates.download', $certificate)
        );

        $response->assertRedirect(route('login'));
    }

    /**
     * テスト用の修了証を作成する。
     */
    private function createCertificate(
        ?User $student = null,
        ?Certification $certification = null,
    ): Certificate {
        $student ??= User::factory()->student()->inProgress()->create();
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
            'pdf_path' => 'certificates/'.uniqid('test_', true).'.pdf',
            'issued_at' => now(),
        ]);
    }
}
