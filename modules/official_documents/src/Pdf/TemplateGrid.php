<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Pdf;

/**
 * One of the federation's templates, printed with a millimetre grid over
 * it, so the coordinates in a layout map can be READ rather than guessed.
 *
 * The problem it exists for is the silent one. Values are written at fixed
 * millimetre positions (`ParentalAuthorizationLayout` and its siblings); a
 * margin that moved three millimetres in a new version of a form puts every
 * one of them beside its line, and no test anywhere raises a thing — the
 * document is produced, it is just wrong.
 *
 * A class rather than a few functions inside `scripts/pdf-template-grid.php`
 * for one reason: drawing on these templates is this module's knowledge, and
 * a script is the one shape in this codebase nothing can test. The script
 * stays as the way a maintainer *runs* it — `templates/README.md` step 3
 * names it, so renaming it changes that file in the same change — and this
 * is what it calls.
 */
final class TemplateGrid
{
    /** A light line every 5 mm. */
    public const MINOR_MM = 5;

    /** A red one, carrying its number, every 10 mm. */
    public const MAJOR_MM = 10;

    /**
     * The template with its grid, as bytes — nothing on disk, same as every
     * other document this module produces.
     *
     * @throws \setasign\Fpdi\PdfParser\PdfParserException when the file is
     *   not a PDF this build of FPDI can read, which is exactly what a
     *   template replaced without being converted looks like
     */
    public static function over(string $templatePath): string
    {
        $pdf = new OverlayPdf();
        $pages = $pdf->openTemplate($templatePath);

        for ($page = 1; $page <= $pages; $page++) {
            $pdf->startPage($page);
            self::drawOnCurrentPage($pdf);
        }

        return $pdf->render();
    }

    private static function drawOnCurrentPage(OverlayPdf $pdf): void
    {
        $width = (int) round($pdf->GetPageWidth());
        $height = (int) round($pdf->GetPageHeight());

        $pdf->SetFont(OverlayPdf::FONT_FAMILY, '', 4);

        for ($x = 0; $x <= $width; $x += self::MINOR_MM) {
            $major = $x % self::MAJOR_MM === 0;
            self::line($pdf, $major, $x, 0, $x, $height);
            if ($major) {
                $pdf->SetTextColor(220, 0, 0);
                $pdf->Text($x + 0.4, 3.0, (string) $x);
            }
        }

        for ($y = 0; $y <= $height; $y += self::MINOR_MM) {
            $major = $y % self::MAJOR_MM === 0;
            self::line($pdf, $major, 0, $y, $width, $y);
            if ($major) {
                $pdf->SetTextColor(220, 0, 0);
                $pdf->Text(0.6, $y - 0.6, (string) $y);
            }
        }
    }

    private static function line(OverlayPdf $pdf, bool $major, float $x1, float $y1, float $x2, float $y2): void
    {
        if ($major) {
            $pdf->SetDrawColor(220, 0, 0);
            $pdf->SetLineWidth(0.12);
        } else {
            $pdf->SetDrawColor(150, 150, 230);
            $pdf->SetLineWidth(0.05);
        }

        $pdf->Line($x1, $y1, $x2, $y2);
    }

    /**
     * Where the grid lands when the caller names no target: beside the
     * template, as `<name>-grid.pdf`. Never committed — it is a thing to
     * look at once.
     */
    public static function defaultTargetFor(string $templatePath): string
    {
        return preg_replace('/\.pdf$/i', '', $templatePath) . '-grid.pdf';
    }
}
