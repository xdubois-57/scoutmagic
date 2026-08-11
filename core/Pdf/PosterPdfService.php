<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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

    // Titles have no practical length cap upstream (news.title is a plain
    // VARCHAR(255) with no maxlength on the editor field), so — unlike the
    // body excerpt, which is already bounded by the 300-char summary field
    // — this truncation is the only thing standing between an admin typing
    // a very long title and the poster overflowing onto a second page.
    private const TITLE_EXCERPT_LENGTH = 100;

    /**
     * @param ?string $imageDataUri already-encoded `data:...;base64,...` —
     *                dompdf runs with isRemoteEnabled=false, so any image
     *                must be embedded rather than fetched by URL/path.
     * @return string raw PDF bytes
     */
    public function generate(string $title, string $bodyHtml, string $qrUrl, string $unitShortName = '', ?string $imageDataUri = null): string
    {
        $titleExcerpt = $this->truncate($title, self::TITLE_EXCERPT_LENGTH);
        $excerpt = $this->buildExcerpt($bodyHtml);
        $qrDataUri = $this->buildQrCodeDataUri($qrUrl);
        $date = (new \DateTimeImmutable())->format('d/m/Y');

        $html = $this->renderHtml($titleExcerpt, $excerpt, $qrDataUri, $qrUrl, $unitShortName, $date, $imageDataUri);

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

        return $this->truncate($plainText, self::BODY_EXCERPT_LENGTH);
    }

    private function truncate(string $text, int $maxLength): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength)) . '…';
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

    private function renderHtml(string $title, string $excerpt, string $qrDataUri, string $qrUrl, string $unitShortName, string $date, ?string $imageDataUri): string
    {
        $imageHtml = $imageDataUri !== null
            ? '<div class="image-wrap"><img src="' . $imageDataUri . '" alt=""></div>'
            : '';

        // A4 content area under the 15mm @page margin is 267mm tall. The
        // .poster wrapper is deliberately shorter (252mm, leaving 15mm of
        // headroom for the fixed footer) AND clips overflow — the sizes
        // below are budgeted to fit comfortably within that on their own,
        // but the clip is what guarantees a single page even in an edge
        // case the budget didn't anticipate, rather than ever letting
        // content spill onto a second page.
        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 15mm; }
    body { font-family: DejaVu Sans, sans-serif; color: #222; margin: 0; }
    .poster { height: 252mm; overflow: hidden; }
    .title { font-size: 22pt; font-weight: bold; text-align: center; line-height: 1.25; margin-top: 4mm; }
    .image-wrap { text-align: center; margin-top: 6mm; }
    .image-wrap img { max-width: 100mm; max-height: 65mm; width: auto; height: auto; }
    .divider { border: none; border-top: 1px solid #999; margin: 6mm 0; }
    .excerpt { font-size: 13pt; line-height: 1.4; text-align: left; }
    .qr-wrap { text-align: center; margin-top: 10mm; }
    .qr-wrap img { width: 55mm; height: 55mm; }
    .qr-url { text-align: center; font-family: monospace; font-size: 10pt; margin-top: 3mm; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 8pt; color: #888; }
</style>
</head>
<body>
    <div class="poster">
        <div class="title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>
        ' . $imageHtml . '
        <hr class="divider">
        <div class="excerpt">' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</div>
        <div class="qr-wrap">
            <img src="' . $qrDataUri . '" alt="QR code">
        </div>
        <div class="qr-url">' . htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') . '</div>
    </div>
    <div class="footer">' . htmlspecialchars($unitShortName, ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</div>
</body>
</html>';
    }
}
