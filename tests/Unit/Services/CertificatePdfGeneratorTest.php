<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\CertificatePdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CertificatePdfGenerator の PDF 生成・保存を検証する Unit テスト。
 */
class CertificatePdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_generate_creates_pdf_on_private_disk(): void
    {
        // Arrange
        $certificate = $this->createCertificate();
        $path = 'certificates/'.Str::ulid().'.pdf';
        $generator = app(CertificatePdfGenerator::class);

        // Act
        $generator->generate($certificate, $path);

        // Assert
        Storage::disk('private')->assertExists($path);

        $pdf = Storage::disk('private')->get($path);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertNotSame('', $pdf);
    }

    public function test_generate_creates_non_empty_pdf_file(): void
    {
        // Arrange
        $certificate = $this->createCertificate();
        $path = 'certificates/'.Str::ulid().'.pdf';
        $generator = app(CertificatePdfGenerator::class);

        // Act
        $generator->generate($certificate, $path);

        // Assert
        $disk = Storage::disk('private');

        $this->assertTrue($disk->exists($path));
        $this->assertGreaterThan(0, $disk->size($path));
    }

    /**
     * テスト用の修了証を作成する。
     */
    private function createCertificate(): Certificate
    {
        $student = User::factory()->student()->inProgress()->create();

        $certification = Certification::factory()
            ->published()
            ->create();

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
