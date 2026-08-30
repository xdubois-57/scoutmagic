<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Writes `attestations_batch_sample.pdf`, the golden fixture
 * `Tests\Modules\Attestations\Service\AttestationPdfReaderTest` replays on
 * every change to the reader.
 *
 * **What this document is, and is not.** It reproduces the SHAPE a batch of
 * federation certificates has — a constant title opening every first page,
 * a different constant opening every second page, the member's name in the
 * identity block, an amount, a footer — with invented names and figures. It
 * is not a real batch with the names blanked out, and it should not be read
 * as evidence of what one contains: it is evidence of what this reader was
 * built to read. A real batch cannot be committed under any amount of
 * anonymisation, because anonymising it *is* replacing every name, which is
 * what this script does. When the federation's real template changes,
 * change the constants below and re-run — the assertions are written
 * against the page arithmetic and the match states, never against the
 * wording.
 *
 * The five people below are chosen for what each one exercises, and the
 * test names them one by one:
 *
 *  - « VANDENBRANDE Margaux » — surname first, the ordinary case;
 *  - « Sacha MEUNIER » — given name first, which the directory must index
 *    just as well, because a printed name carries no clue which is which;
 *  - « Timéo ROSKAM » — an accent, folded on both sides;
 *  - « Zoé HERREMANS » — TWO members of the unit carry this name, so the
 *    line has to come out ambiguous and never resolved;
 *  - « Camille DELACROIX » — nobody of that name is known to the site.
 *
 *   php tests/fixtures/pdf/generate-attestations-batch.php
 *   php tests/fixtures/pdf/generate-attestations-batch.php --check
 *
 * `--check` compares the committed file byte for byte and exits non-zero
 * otherwise — the same mechanism the reference dataset and the federation
 * invoice use, so a change to the generator that nobody re-ran cannot pass
 * unnoticed.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Tests\Modules\Attestations\AttestationsPdfBuilder;

const OUTPUT = __DIR__ . '/attestations_batch_sample.pdf';

/**
 * The constant that opens page 1 of every certificate. Meeting it again is
 * the whole detection mechanism: the reader takes the distance between two
 * occurrences as the number of pages one certificate occupies.
 */
const FIRST_PAGE_TITLE = 'ATTESTATION FISCALE - EXERCICE 2025';

/** The constant that opens page 2. A DIFFERENT one, or a certificate would read as one page. */
const SECOND_PAGE_TITLE = 'ANNEXE - DETAIL DES VERSEMENTS';

/** @var array<array{0: string, 1: string, 2: string}> name, member number, amount */
$people = [
    ['VANDENBRANDE Margaux', 'SV025-000412', '312,00'],
    ['Sacha MEUNIER', 'SV025-000418', '288,00'],
    ['Timéo ROSKAM', 'SV025-000437', '312,00'],
    ['Zoé HERREMANS', 'SV025-000455', '156,00'],
    ['Camille DELACROIX', 'SV025-000461', '288,00'],
];

$pages = [];
foreach ($people as [$name, $memberNumber, $amount]) {
    $pages[] = [
        FIRST_PAGE_TITLE,
        'Les Scouts ASBL - Rue de Dublin 21 - 1050 Bruxelles',
        'Unite : SV025 - Ottignies Petit Ry',
        $name,
        'Numero de membre : ' . $memberNumber,
        'Montant total verse : ' . $amount . ' EUR',
    ];
    $pages[] = [
        SECOND_PAGE_TITLE,
        $name,
        'Cotisation annuelle : 39,00 EUR',
        'Participation camp : ' . $amount . ' EUR',
        'Document genere automatiquement - ne pas renvoyer',
    ];
}

$pdf = AttestationsPdfBuilder::build($pages);

if (in_array('--check', $argv, true)) {
    $committed = @file_get_contents(OUTPUT);
    if ($committed === $pdf) {
        echo "attestations_batch_sample.pdf is up to date.\n";
        exit(0);
    }
    fwrite(STDERR, "attestations_batch_sample.pdf does not match this generator. Re-run it without --check and commit what it wrote.\n");
    exit(1);
}

file_put_contents(OUTPUT, $pdf);
printf(
    "Wrote %d pages (%d certificates of 2 pages), %d bytes.\n",
    count($pages),
    count($people),
    strlen($pdf)
);
