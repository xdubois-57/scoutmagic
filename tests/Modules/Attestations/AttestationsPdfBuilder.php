<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Attestations;

/**
 * Assembles a minimal, deterministic PDF whose text layer is exactly one
 * line per string given — the same hand-rolled approach as
 * `tests/fixtures/pdf/generate-federation-invoice.php`, and for the same
 * two reasons: the fixture stays readable as a diff, and the bytes are
 * reproducible so `--check` can compare them.
 *
 * **Deliberately not rendered through dompdf.** dompdf stamps a
 * `/CreationDate` and a `/Producer` into every document, so two runs of the
 * same generator differ and a byte-for-byte check is impossible. The
 * producer matters for a second reason: `smalot/pdfparser` takes an
 * entirely different code path for a document whose Producer begins with
 * "FPDF" (`Page::isFpdf()`), which is what FPDI itself writes — so a
 * fixture built with FPDI would exercise a branch a real federation PDF
 * never goes through, and miss the one the reader actually uses.
 *
 * Lives under `tests/` rather than in the module: it builds documents for
 * tests to read, and nothing in production ever composes a PDF here.
 */
final class AttestationsPdfBuilder
{
    /**
     * @param list<list<string>> $pages one list of lines per page, in
     *                                  reading order — the reader's
     *                                  "text fields of a page"
     */
    public static function build(array $pages): string
    {
        $objects = [];
        $pageObjectIds = [];
        $contentIds = [];
        $nextId = 3;
        foreach ($pages as $ignored) {
            $pageObjectIds[] = $nextId;
            $contentIds[] = $nextId + 1;
            $nextId += 2;
        }
        $fontId = $nextId;

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pages) . ' >>';

        foreach ($pages as $i => $lines) {
            $objects[$pageObjectIds[$i]] = '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 '
                . $fontId . ' 0 R >> >> /MediaBox [0 0 595 842] /Contents ' . $contentIds[$i] . ' 0 R >>';

            $stream = '';
            $y = 800;
            foreach ($lines as $line) {
                $stream .= 'BT /F1 11 Tf 60 ' . $y . ' Td (' . self::pdfString($line) . ") Tj ET\n";
                $y -= 20;
            }
            $objects[$contentIds[$i]] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
        }

        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $max; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        return $pdf . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n"
            . $xrefOffset . "\n%%EOF\n";
    }

    /** WinAnsi (CP1252) — what the font declares — plus PDF string escaping. */
    private static function pdfString(string $utf8): string
    {
        $bytes = @iconv('UTF-8', 'CP1252//TRANSLIT', $utf8);
        if ($bytes === false) {
            $bytes = $utf8;
        }

        $out = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $char = $bytes[$i];
            $code = ord($char);
            if ($char === '(' || $char === ')' || $char === '\\') {
                $out .= '\\' . $char;
            } elseif ($code < 32 || $code > 126) {
                $out .= sprintf('\\%03o', $code);
            } else {
                $out .= $char;
            }
        }

        return $out;
    }
}
