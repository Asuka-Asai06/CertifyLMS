<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

class CertificatePdfGenerator
{
    /**
     * 修了証 PDF を生成し、private ディスクへ保存する。
     *
     * @throws RuntimeException PDF の生成または保存に失敗した場合
     */
    public function generate(Certificate $certificate, string $path): void
    {
        $fontConfig = (new FontVariables)->getDefaults();
        $config = (new ConfigVariables)->getDefaults();

        $mpdf = new Mpdf([
            'mode' => 'ja',
            'format' => 'A4',
            'fontDir' => array_merge(
                $config['fontDir'],
                ['/usr/share/fonts/opentype/ipafont-gothic'],
            ),
            'fontdata' => $fontConfig['fontdata'] + [
                'ipagothic' => [
                    'R' => 'ipag.ttf',
                ],
            ],
            'default_font' => 'ipagothic',
        ]);

        $html = view('certificates.pdf', [
            'certificate' => $certificate,
        ])->render();

        $mpdf->WriteHTML($html);

        $pdf = $mpdf->Output('', Destination::STRING_RETURN);

        $saved = Storage::disk('private')->put($path, $pdf);

        if (! $saved) {
            throw new RuntimeException('修了証 PDF の保存に失敗しました。');
        }
    }
}
