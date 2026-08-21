<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Generic multi-page A4 document generator (Core\Pdf) — a contract, an
 * invoice, an attestation, anything that is a body of rich text with a
 * heading and a footer.
 *
 * Sibling of `PosterPdfService`, not a replacement: a poster is one page
 * built around a QR code, deliberately truncating its title and its body,
 * which is exactly wrong for a document whose whole point is to say
 * everything it says. Same dompdf underneath — no new dependency.
 *
 * **The caller owns the sanitizing.** This renders whatever HTML it is
 * handed, so a caller that has not passed its rich text through
 * `Core\Security\HtmlSanitizer` and escaped every substituted value first
 * is handing dompdf whatever a third party typed. dompdf is an HTML
 * renderer, and a renter's name containing markup would otherwise be
 * interpreted.
 *
 * Remote content is off (`isRemoteEnabled`), so an image has to arrive as a
 * `data:` URI. That is the point rather than a limitation: a document that
 * fetched a URL at render time would leak a request to whoever wrote the
 * markup, and would render differently depending on the network.
 */
class DocumentPdfService
{
    /**
     * @param string $bodyHtml Already sanitized, with every substituted value already escaped.
     * @param string[] $metaLines Short lines under the title — reference, dates, a total.
     * @return string raw PDF bytes
     */
    public function generate(
        string $title,
        string $bodyHtml,
        string $unitName = '',
        array $metaLines = [],
        ?string $footerNote = null
    ): string {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->renderHtml($title, $bodyHtml, $unitName, $metaLines, $footerNote));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param string[] $metaLines
     */
    private function renderHtml(
        string $title,
        string $bodyHtml,
        string $unitName,
        array $metaLines,
        ?string $footerNote
    ): string {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeUnit = htmlspecialchars($unitName, ENT_QUOTES, 'UTF-8');
        $safeFooter = $footerNote !== null ? htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8') : '';

        $meta = '';
        foreach ($metaLines as $line) {
            $meta .= '<div class="meta">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        // The page number comes from dompdf's own `page` counter rather
        // than from a script: a document is printed and signed, and "page 2
        // of 3" is what tells a reader nothing is missing.
        return <<<HTML
            <!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <style>
                    @page { margin: 24mm 18mm 22mm 18mm; }
                    body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; line-height: 1.5; color: #1a1a1a; }
                    header { border-bottom: 1px solid #888; padding-bottom: 8px; margin-bottom: 18px; }
                    h1 { font-size: 16pt; margin: 0 0 4px 0; }
                    .unit { font-size: 9pt; color: #555; }
                    .meta { font-size: 9pt; color: #333; }
                    .body { text-align: justify; }
                    .body table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                    .body td, .body th { border: 1px solid #bbb; padding: 4px 6px; font-size: 9.5pt; }
                    .body img { max-width: 100%; }
                    footer { position: fixed; bottom: -14mm; left: 0; right: 0;
                             font-size: 8pt; color: #666; border-top: 1px solid #ddd; padding-top: 4px; }
                    .page-number:after { content: counter(page); }
                </style>
            </head>
            <body>
                <header>
                    <h1>{$safeTitle}</h1>
                    <div class="unit">{$safeUnit}</div>
                    {$meta}
                </header>
                <div class="body">{$bodyHtml}</div>
                <footer>{$safeFooter} <span style="float:right;">Page <span class="page-number"></span></span></footer>
            </body>
            </html>
            HTML;
    }
}
