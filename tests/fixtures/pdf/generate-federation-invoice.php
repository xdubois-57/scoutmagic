<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Writes `federation_invoice_sample.pdf`, the golden fixture
 * `Tests\Modules\Fees\Invoice\InvoiceReaderTest` replays on every change to
 * the parser.
 *
 * **What this document is, and is not.** It reproduces the SHAPE of a "Les
 * Scouts" membership invoice — the column order, the reference codes, the
 * nominative lists, the Belgian number format, the page-break repetition,
 * the footer, the template number — with invented names and figures. It is
 * not a real invoice with the names blanked out, and it should not be read
 * as evidence of what one contains: it is evidence of what this parser was
 * built to read. When a real one can be anonymised, replace the data below
 * with it and re-run this script — the assertions are written against the
 * totals and the shapes, not against the names.
 *
 * Hand-assembled rather than rendered through dompdf so the text layer is
 * exactly one line per row: that is what makes the fixture readable as a
 * diff, and what keeps a forty-row invoice inside a few kilobytes.
 *
 *   php tests/fixtures/pdf/generate-federation-invoice.php
 *   php tests/fixtures/pdf/generate-federation-invoice.php --check
 *
 * `--check` compares the committed file byte for byte and exits non-zero
 * otherwise — the same mechanism the reference dataset uses, so a change to
 * the generator that nobody re-ran cannot pass unnoticed.
 */

const OUTPUT = __DIR__ . '/federation_invoice_sample.pdf';
const NBSP = "\u{00A0}";

/**
 * reference, description, section (empty when the line legitimately has
 * none), unit price, and the people listed under it.
 *
 * @var array<array{0: string, 1: string, 2: string, 3: string, 4: array<array{0: string, 1: string, 2: string, 3: string}>}>
 */
$blocks = [
    ['COT_NORM', 'Cotisation normale', 'SV025B1', '39,00', [
        ['DUBOIS', 'Basile', '12/04/2016', 'Animé'],
        ['PISSOORT', 'Anouk', '03/11/2015', 'Animé'],
        ['LAMBERT', 'Noé', '27/08/2016', 'Animé'],
        ['GERARD', 'Lila', '02/02/2016', 'Animé'],
        ['HENRY', 'Tom', '14/07/2015', 'Animé'],
        ['SIMON', 'Jade', '09/09/2016', 'Animé'],
        ['COLLIN', 'Ilan', '21/12/2015', 'Animé'],
        ['MARTIN', 'Zoé', '30/05/2016', 'Animé'],
        ['DENIS', 'Aaron', '18/03/2015', 'Animé'],
        ['CLAES', 'Nina', '07/10/2016', 'Animé'],
        ['ROLAND', 'Hugo', '25/01/2016', 'Animé'],
        ['WILLEMS', 'Sarah', '11/06/2015', 'Animé'],
    ]],
    ['COT_NORM', 'Cotisation normale', 'SV025L1', '39,00', [
        ['DETRY', 'Maxime', '04/04/2013', 'Animé'],
        // Deliberately not upper case: the document is inconsistent about
        // it, and the match has to fold.
        ['Pissoort', 'Corentin', '16/08/2012', 'Animé'],
        ['LEJEUNE', 'Alice', '29/11/2013', 'Animé'],
        ['NOEL', 'Robin', '06/02/2012', 'Animé'],
        ['ANDRE', 'Yasmine', '23/07/2013', 'Animé'],
        ['PIRON', 'Antoine', '13/05/2012', 'Animé'],
        ['LEROY', 'Salomé', '01/09/2013', 'Animé'],
        ['GILLET', 'Nathan', '19/12/2012', 'Animé'],
        ['DUMONT', 'Iris', '28/03/2013', 'Animé'],
        ['CHARLIER', 'Malo', '10/10/2012', 'Animé'],
    ]],
    // The federation prints a label here, not a code: "Staff d'unité",
    // which is this site's STAFFDU section.
    ['COT_NORM', 'Cotisation normale', "Staff d'unité", '39,00', [
        ['DUPONT', 'Sophie', '22/06/1995', "Chef d'unité"],
        ['Lemaire', 'Marc', '10/01/1990', "Chef d'unité"],
        ['BODART', 'Emma', '05/05/1998', 'Animateur'],
        ['THIRY', 'Gilles', '17/02/1997', 'Animateur'],
        ['VERHEYEN', 'Lucie', '08/12/1999', 'Animateur'],
        ['MOREAU', 'Adrien', '26/04/1996', 'Animateur'],
        ['LEGRAND', 'Chloé', '03/03/2000', 'Intendant'],
        ['DELVAUX', 'Simon', '20/09/1994', 'Intendant'],
    ]],
    ['COT_FAM', 'Cotisation famille', 'SV025L1', '31,00', [
        ['MERCIER', 'Alix', '15/03/2013', 'Animé'],
        ['MERCIER', 'Camille', '22/09/2014', 'Animé'],
        ['MERCIER', 'Élise', '30/01/2012', 'Animé'],
        ['MERCIER', 'Louis', '05/06/2011', 'Animé'],
    ]],
    // Twins: same surname, same birth date, two first names. A key built on
    // surname + birth date would merge them into one person.
    ['COT_COUPLE', 'Cotisation couple', 'SV025E1', '35,00', [
        ['BASTIN', 'Théo', '19/02/2010', 'Animé'],
        ['BASTIN', 'Manon', '19/02/2010', 'Animé'],
    ]],
    // No section, and a reference no hardcoded list would carry: the Iama
    // are not exempt, they pay a local membership.
    ['COT_iAM_LOCAL', 'Cotisation iAM (local)', '', '25,00', [
        ['vandenberg', 'lucie', '08/12/2009', 'Animé'],
    ]],
    // Negative unit price, tied to a formation level.
    ['RED_ANIM_BREV', 'Réduction animateur breveté', "Staff d'unité", '-10,00', [
        ['DUPONT', 'Sophie', '22/06/1995', "Chef d'unité"],
        ['Lemaire', 'Marc', '10/01/1990', "Chef d'unité"],
    ]],
];

/**
 * Negative and with no nominative list at all — the deposit already
 * invoiced, deducted from the final.
 *
 * @var array<array{0: string, 1: string, 2: string, 3: string, 4: int}>
 */
$adjustments = [
    ['COT_ACOMPTE', "Déduction de l'acompte facturé", '', '-300,00', 1],
];

$toCents = static fn(string $amount): int => (int) round((float) str_replace(',', '.', $amount) * 100);
$fromCents = static fn(int $cents): string =>
    ($cents < 0 ? '-' : '') . number_format(abs($cents) / 100, 2, ',', NBSP);

$total = 0;
/** @var string[][] $rows */
$rows = [];
foreach ($blocks as [$reference, $description, $section, $price, $people]) {
    $quantity = count($people);
    $amount = $toCents($price) * $quantity;
    $total += $amount;

    $row = [trim($reference . ' ' . $description . ' ' . $section)
        . ' ' . $price . ' ' . $quantity . ' ' . $fromCents($amount)];
    foreach ($people as [$last, $first, $birth, $function]) {
        $row[] = $last . ' ' . $first . ' ' . $birth . ' ' . $function;
    }
    $rows[] = $row;
}
foreach ($adjustments as [$reference, $description, $section, $price, $quantity]) {
    $amount = $toCents($price) * $quantity;
    $total += $amount;
    $rows[] = [trim($reference . ' ' . $description . ' ' . $section)
        . ' ' . $price . ' ' . $quantity . ' ' . $fromCents($amount)];
}

$header = 'Référence Description Section P.U. Qt. Montant';
$page1 = [
    'Les Scouts ASBL',
    'Rue de Dublin 21 - 1050 Bruxelles',
    'Facture n° F2026/000123',
    'Date : 08/01/2026',
    'Unité : SV025 - Ottignies Vert Brabant',
    $header,
];
$page2 = [$header];

// The break falls mid-document, and the last block of page 1 is repeated at
// the top of page 2 exactly as a real page break repeats it. The parser has
// to read that as one line whose lists merge, not as two lines.
$blocksOnFirstPage = 4;
foreach ($rows as $index => $row) {
    foreach ($row as $line) {
        if ($index < $blocksOnFirstPage) {
            $page1[] = $line;
        } else {
            $page2[] = $line;
        }
    }
    if ($index === $blocksOnFirstPage - 1) {
        $page1[] = 'Page 1/2';
        foreach ($row as $line) {
            $page2[] = $line;
        }
    }
}

$page2[] = 'TOTAL A PAYER ' . $fromCents($total);
$page2[] = 'IBAN : BE71 0961 2345 6769';
$page2[] = 'Communication structurée : +++123/4567/89012+++';
$page2[] = 'Report0024 v.01';
$page2[] = 'Page 2/2';

$pdf = buildPdf([$page1, $page2]);

if (in_array('--check', $argv, true)) {
    $committed = @file_get_contents(OUTPUT);
    if ($committed === $pdf) {
        echo "federation_invoice_sample.pdf is up to date.\n";
        exit(0);
    }
    fwrite(STDERR, "federation_invoice_sample.pdf does not match this generator. Re-run it without --check and commit what it wrote.\n");
    exit(1);
}

file_put_contents(OUTPUT, $pdf);
echo 'Total: ' . $fromCents($total) . ' — wrote ' . strlen($pdf) . " bytes.\n";

/** @param string[][] $pages */
function buildPdf(array $pages): string
{
    $objects = [];
    $pageObjIds = [];
    $contentIds = [];
    $nextId = 3;
    foreach ($pages as $ignored) {
        $pageObjIds[] = $nextId;
        $contentIds[] = $nextId + 1;
        $nextId += 2;
    }
    $fontId = $nextId;

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjIds));
    $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pages) . ' >>';

    foreach ($pages as $i => $lines) {
        $objects[$pageObjIds[$i]] = '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 ' . $fontId
            . ' 0 R >> >> /MediaBox [0 0 595 842] /Contents ' . $contentIds[$i] . ' 0 R >>';

        $stream = '';
        $y = 820;
        foreach ($lines as $line) {
            $stream .= 'BT /F1 8 Tf 24 ' . $y . ' Td (' . pdfString($line) . ") Tj ET\n";
            $y -= 14;
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

    return $pdf . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
}

/** WinAnsi (CP1252) — what the font declares — plus PDF string escaping. */
function pdfString(string $utf8): string
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
