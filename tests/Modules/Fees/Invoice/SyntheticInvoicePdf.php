<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Fees\Invoice;

/** Builds a text-layer PDF in memory, exclusively from invented test data. */
final class SyntheticInvoicePdf
{
    /** @param list<list<string>> $pages */
    public static function build(array $pages): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = [];
        $fontId = 3 + count($pages) * 2;
        foreach ($pages as $index => $lines) {
            $pageId = 3 + $index * 2;
            $contentId = $pageId + 1;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 '
                . $fontId . ' 0 R >> >> /MediaBox [0 0 595 842] /Contents ' . $contentId . ' 0 R >>';
            $stream = '';
            foreach ($lines as $row => $line) {
                foreach (explode("\t", $line) as $column => $cell) {
                    $encoded = iconv('UTF-8', 'Windows-1252', $cell);
                    if ($encoded === false) {
                        throw new \InvalidArgumentException('The synthetic PDF uses an unsupported character.');
                    }
                    $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
                    // A real PDF separates cells by their positions, not by
                    // literal tab bytes inside a text operand.
                    $stream .= 'BT /F1 9 Tf ' . (24 + $column * 310) . ' ' . (810 - $row * 14)
                        . ' Td (' . $escaped . ") Tj ET\n";
                }
            }
            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($fontId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $fontId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $pdf . 'trailer << /Size ' . ($fontId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
