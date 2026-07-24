<?php

declare(strict_types=1);

namespace Core\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Generic A4 poster PDF generator (Core\Pdf) — not specific to any module.
 * Renders a simple HTML+CSS layout (title, trimmed body excerpt, QR code)
 * to PDF via dompdf. Introduced for the news module's article posters, but
 * any future module can call it the same way.
 */
class PosterPdfService
{
    private const BODY_EXCERPT_LENGTH = 300;

    /**
     * @return string raw PDF bytes
     */
    public function generate(string $title, string $bodyHtml, string $qrUrl, string $unitShortName = ''): string
    {
        $excerpt = $this->buildExcerpt($bodyHtml);
        $qrDataUri = $this->buildQrCodeDataUri($qrUrl);
        $date = (new \DateTimeImmutable())->format('d/m/Y');

        $html = $this->renderHtml($title, $excerpt, $qrDataUri, $qrUrl, $unitShortName, $date);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildExcerpt(string $bodyHtml): string
    {
        $plainText = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES, 'UTF-8'));
        $plainText = preg_replace('/\s+/u', ' ', $plainText) ?? $plainText;

        if (mb_strlen($plainText) <= self::BODY_EXCERPT_LENGTH) {
            return $plainText;
        }

        return mb_substr($plainText, 0, self::BODY_EXCERPT_LENGTH) . '…';
    }

    private function buildQrCodeDataUri(string $qrUrl): string
    {
        $result = (new Builder(
            writer: new PngWriter(),
            data: $qrUrl,
            size: 600,
            margin: 10
        ))->build();

        return $result->getDataUri();
    }

    private function renderHtml(string $title, string $excerpt, string $qrDataUri, string $qrUrl, string $unitShortName, string $date): string
    {
        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 15mm; }
    body { font-family: DejaVu Sans, sans-serif; color: #222; margin: 0; }
    .title { font-size: 28pt; font-weight: bold; text-align: center; margin-top: 10mm; }
    .divider { border: none; border-top: 1px solid #999; margin: 8mm 0; }
    .excerpt { font-size: 14pt; line-height: 1.5; text-align: left; }
    .qr-wrap { text-align: center; margin-top: 20mm; }
    .qr-wrap img { width: 80mm; height: 80mm; }
    .qr-url { text-align: center; font-family: monospace; font-size: 11pt; margin-top: 4mm; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 8pt; color: #888; }
</style>
</head>
<body>
    <div class="title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>
    <hr class="divider">
    <div class="excerpt">' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</div>
    <div class="qr-wrap">
        <img src="' . $qrDataUri . '" alt="QR code">
    </div>
    <div class="qr-url">' . htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') . '</div>
    <div class="footer">' . htmlspecialchars($unitShortName, ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</div>
</body>
</html>';
    }
}
