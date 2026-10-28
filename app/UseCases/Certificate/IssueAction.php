<?php

declare(strict_types=1);

namespace App\UseCases\Certificate;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateAlreadyIssuedException;
use App\Exceptions\Certification\EnrollmentNotPassedException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * 修了証を発行するユースケース。
 * 受講生自己発火型の修了処理
 * `\App\UseCases\Enrollment\ReceiveCertificateAction` から呼び出される。
 *
 * 業務分岐:
 * - Enrollment が status=passed かつ passed_at != null でない:
 *   EnrollmentNotPassedException
 * - 同一 Enrollment に対する二重呼出:
 *   CertificateAlreadyIssuedException
 * - PDF の生成または保存に失敗:
 *   例外を送出し、修了証レコードをロールバックする
 *
 * 修了証レコードの INSERT と PDF 生成は DB::transaction() 内で実行する。
 */
final class IssueAction
{
    public function __construct(
        private readonly CertificatePdfGenerator $pdfGenerator,
    ) {}

    /**
     * 修了証レコードと PDF を作成する。
     *
     * @throws EnrollmentNotPassedException 受講登録が修了状態ではない
     * @throws CertificateAlreadyIssuedException 同一 Enrollment で修了証が既発行
     * @throws Throwable PDF 生成・保存などに失敗した場合
     */
    public function __invoke(Enrollment $enrollment): Certificate
    {
        if (
            $enrollment->status !== EnrollmentStatus::Passed
            || $enrollment->passed_at === null
        ) {
            throw new EnrollmentNotPassedException;
        }

        $path = 'certificates/'.Str::ulid().'.pdf';

        try {
            return DB::transaction(function () use ($enrollment, $path): Certificate {
                // 二重発行ガード
                $existing = Certificate::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw new CertificateAlreadyIssuedException;
                }

                $certificate = Certificate::create([
                    'user_id' => $enrollment->user_id,
                    'enrollment_id' => $enrollment->id,
                    'certification_id' => $enrollment->certification_id,
                    'pdf_path' => $path,
                    'issued_at' => now(),
                ]);

                $certificate->load(['user', 'certification']);

                $this->pdfGenerator->generate($certificate, $path);

                return $certificate;
            });
        } catch (Throwable $e) {
            Storage::disk('private')->delete($path);

            throw $e;
        }
    }
}
