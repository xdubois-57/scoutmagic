<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * Development utility: print one of the federation's templates with a
 * millimetre grid over it, so the coordinates in a document's layout map
 * can be READ rather than guessed.
 *
 * The problem it exists for is the silent one. Values are written at fixed
 * millimetre positions (Modules\OfficialDocuments\Pdf\
 * ParentalAuthorizationLayout and its siblings); a margin that moved three
 * millimetres in a new version of a form puts every one of them beside its
 * line, and no test anywhere raises a thing — the document is produced,
 * it is just wrong. `modules/official_documents/templates/README.md` step 3
 * names this script, so renaming it means changing that file in the same
 * change.
 *
 *     php scripts/pdf-template-grid.php modules/official_documents/templates/autorisation-parentale.pdf
 *     php scripts/pdf-template-grid.php <template.pdf> <output.pdf>
 *
 * With no second argument the grid lands beside the template as
 * `<name>-grid.pdf`. Never committed — it is a thing to look at once.
 *
 * Not a route, not a service, and deliberately a script: it runs on a
 * maintainer's machine when a template changes, which is a handful of times
 * in the life of the project.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This is a command-line utility.\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$source = $argv[1] ?? '';
if ($source === '' || !is_file($source)) {
    fwrite(STDERR, "Usage: php scripts/pdf-template-grid.php <template.pdf> [output.pdf]\n");
    exit(1);
}

$target = $argv[2] ?? preg_replace('/\.pdf$/i', '', $source) . '-grid.pdf';

/** Every 5 mm a light line, every 10 mm a red one carrying its number. */
const MINOR_MM = 5;
const MAJOR_MM = 10;

$pdf = new Modules\OfficialDocuments\Pdf\OverlayPdf();
$pages = $pdf->openTemplate($source);

for ($page = 1; $page <= $pages; $page++) {
    $pdf->startPage($page);

    $width = (int) round($pdf->GetPageWidth());
    $height = (int) round($pdf->GetPageHeight());

    $pdf->SetFont(Modules\OfficialDocuments\Pdf\OverlayPdf::FONT_FAMILY, '', 4);

    for ($x = 0; $x <= $width; $x += MINOR_MM) {
        gridLine($pdf, $x % MAJOR_MM === 0, $x, 0, $x, $height);
        if ($x % MAJOR_MM === 0) {
            $pdf->SetTextColor(220, 0, 0);
            $pdf->Text($x + 0.4, 3.0, (string) $x);
        }
    }

    for ($y = 0; $y <= $height; $y += MINOR_MM) {
        gridLine($pdf, $y % MAJOR_MM === 0, 0, $y, $width, $y);
        if ($y % MAJOR_MM === 0) {
            $pdf->SetTextColor(220, 0, 0);
            $pdf->Text(0.6, $y - 0.6, (string) $y);
        }
    }
}

file_put_contents($target, $pdf->render());

echo "Grid written to {$target}\n";
echo "Read a coordinate off it, type it into the document's layout map, and LOOK at the result.\n";

function gridLine(
    Modules\OfficialDocuments\Pdf\OverlayPdf $pdf,
    bool $major,
    float $x1,
    float $y1,
    float $x2,
    float $y2
): void {
    if ($major) {
        $pdf->SetDrawColor(220, 0, 0);
        $pdf->SetLineWidth(0.12);
    } else {
        $pdf->SetDrawColor(150, 150, 230);
        $pdf->SetLineWidth(0.05);
    }

    $pdf->Line($x1, $y1, $x2, $y2);
}
