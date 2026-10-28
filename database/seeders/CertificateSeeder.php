<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TermType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentStatusLog;
use App\Models\User;
use App\Services\CertificatePdfGenerator;
use App\UseCases\Certificate\IssueAction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 卒業生向けの修了証 + 過去 Enrollment 補完シーダー。
 *
 * `EnrollmentSeeder::issueCertificate()` は in_progress 受講生の passed Enrollment に対し
 * 既に Certificate を発行している(動作確認用 demo)。本 Seeder はそれを残しつつ、
 * `UserSeeder` で生成される graduated 受講生 3 名に過去 Enrollment + Certificate を補完して
 * 「修了 → 卒業」フローの履歴を画面で見える状態にする。
 *
 * **設計思想(Seeder 業界標準: 状態補完)**:
 *
 * 1. **graduated 受講生に過去 Enrollment + Certificate**: 各 graduated 受講生に 1 件の passed Enrollment を作り、
 *    Certificate を発行する。Certificate は永続データなので卒業後も DL 可能(プラン機能はロックされるが修了証 DL は可)。
 * 2. **EnrollmentStatusLog 同梱**: learning → passed の遷移ログを併せて INSERT し、状態遷移履歴が成立する状態にする。
 *
 * 依存順序: `UserSeeder` → `PlanSeeder` → `CertificationSeeder` → `EnrollmentSeeder` → 本 Seeder。
 */
final class CertificateSeeder extends Seeder
{
    public function run(): void
    {
        $publishedCertifications = Certification::query()
            ->where('status', CertificationStatus::Published->value)
            ->orderBy('created_at')
            ->get();

        if ($publishedCertifications->isEmpty()) {
            $this->command?->warn(
                'CertificateSeeder: 公開済資格がありません。先に CertificationSeeder を実行してください。'
            );

            return;
        }

        $fixedStudent = User::query()
            ->where('email', 'student@certify-lms.test')
            ->first();

        $pmp = $publishedCertifications->get(4);

        if ($fixedStudent !== null && $pmp !== null) {
            $enrollment = $this->createPastEnrollment($fixedStudent, $pmp, 0);
            $this->issueCertificateForEnrollment($enrollment);
        }

        $graduatedStudents = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::Graduated->value)
            ->orderBy('created_at')
            ->get();

        // 基本情報（coach1）、簿記（coach2）、PMP（coach2）
        $certificationIndexes = [0, 3, 4];

        foreach ($graduatedStudents as $i => $student) {
            $certification = $publishedCertifications->get(
                $certificationIndexes[$i % count($certificationIndexes)]
            );

            if ($certification === null) {
                continue;
            }

            $enrollment = $this->createPastEnrollment(
                $student,
                $certification,
                $i,
            );

            $this->issueCertificateForEnrollment($enrollment);
        }

        $this->generateMissingPdfs();
    }

    /**
     * graduated 受講生に紐づく過去 Enrollment を作る(passed 状態、試験日も plan_expires_at の前)。
     * EnrollmentStatusLog も併せて積む。
     */
    private function createPastEnrollment(User $student, Certification $certification, int $orderIndex): Enrollment
    {
        $existing = Enrollment::query()
            ->where('user_id', $student->id)
            ->where('certification_id', $certification->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $planExpiresAt = $student->plan_expires_at ?? now()->subDays(30 + $orderIndex * 15);
        $examDate = $planExpiresAt->copy()->subDays(10)->toDateString();
        $passedAt = $planExpiresAt->copy()->subDays(7);
        $startedAt = $planExpiresAt->copy()->subDays(90);

        $enrollment = Enrollment::factory()
            ->for($student)
            ->for($certification)
            ->state([
                'status' => EnrollmentStatus::Passed->value,
                'current_term' => TermType::MockPractice->value,
                'exam_date' => $examDate,
                'passed_at' => $passedAt,
            ])
            ->create();

        $enrollment->forceFill(['created_at' => $startedAt, 'updated_at' => $passedAt])->save();

        EnrollmentStatusLog::factory()->for($enrollment)->create([
            'from_status' => null,
            'to_status' => EnrollmentStatus::Learning->value,
            'changed_by_user_id' => $student->id,
            'changed_at' => $startedAt,
            'changed_reason' => '新規登録',
        ]);

        EnrollmentStatusLog::factory()->for($enrollment)->create([
            'from_status' => EnrollmentStatus::Learning->value,
            'to_status' => EnrollmentStatus::Passed->value,
            'changed_by_user_id' => $student->id,
            'changed_at' => $passedAt,
            'changed_reason' => '受講生による修了証受領',
        ]);

        return $enrollment;
    }

    /**
     * IssueAction を通じて修了証を発行する。
     */
    private function issueCertificateForEnrollment(Enrollment $enrollment): void
    {
        if (Certificate::query()
            ->where('enrollment_id', $enrollment->id)
            ->exists()) {
            return;
        }

        app(IssueAction::class)($enrollment);
    }

    /**
     * 既存の証明書について、PDF ファイルがなければ生成する。
     */
    private function generateMissingPdfs(): void
    {
        $generator = app(CertificatePdfGenerator::class);

        Certificate::query()
            ->with(['user', 'certification'])
            ->each(function (Certificate $certificate) use ($generator): void {
                $disk = Storage::disk('private');
                $path = $certificate->pdf_path;

                if (! is_string($path) || $path === '') {
                    $path = 'certificates/'.Str::ulid().'.pdf';

                    $certificate->update([
                        'pdf_path' => $path,
                    ]);
                }

                if ($disk->exists($path)) {
                    return;
                }

                $generator->generate($certificate, $path);
            });
    }
}
