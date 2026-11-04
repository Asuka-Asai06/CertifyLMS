<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 発行済み修了証 PDF をダウンロードする。
 */
class CertificateDownloadController extends Controller
{
    public function __invoke(Certificate $certificate): StreamedResponse|Response
    {
        $this->authorize('download', $certificate);

        $disk = Storage::disk('private');

        abort_unless($disk->exists($certificate->pdf_path), 404);

        return $disk->download(
            $certificate->pdf_path,
            'certificate-'.$certificate->id.'.pdf',
        );
    }
}
