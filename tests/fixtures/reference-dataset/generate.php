<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Regenerates the reference dataset's Desk exports and its photo manifest.
 *
 *   php tests/fixtures/reference-dataset/generate.php            write the files
 *   php tests/fixtures/reference-dataset/generate.php --check    verify them
 *
 * **The generated files are committed.** An instance being built has nothing
 * to generate, and a reviewer reading a pull request sees the data change, not
 * just the code that produces it. `--check` regenerates everything in memory
 * and compares it byte for byte with what is on disk: a generator edited
 * without re-running it becomes a failing test rather than a silent
 * divergence. Same mechanism, same reason, as `js-typecheck-baseline.json`.
 *
 * Where to make a change:
 *   - the size or shape of the unit  → UnitBlueprint
 *   - a named behaviour to exercise  → ScenarioCatalog, then ScenarioPeople
 *   - which photo belongs to a section → PhotoLot::GROUP_PHOTOS
 * then re-run this script and commit what it wrote.
 *
 * CLI only, and for the same reason as public/cron.php (SECURITY.md §24):
 * there is nothing to serve to a browser here, and this script writes into the
 * repository. The dataset directory ships in no release artifact —
 * scripts/release.sh excludes `tests/*` and fails the release outright if
 * `reference-dataset` ever appears in the archive — and these classes are
 * autoloaded through composer's `autoload-dev`, so they do not exist at all in
 * a `--no-dev` install.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../../vendor/autoload.php';

use Tests\Fixtures\ReferenceDataset\DatasetGenerator;

$check = in_array('--check', $argv, true);
$generator = new DatasetGenerator(__DIR__);

if (!$check) {
    foreach ($generator->generate() as $relativePath => $contents) {
        $absolute = __DIR__ . '/' . $relativePath;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, $contents);
        printf("écrit   %-28s %6d lignes\n", $relativePath, substr_count($contents, "\n"));
    }

    foreach ($generator->summary() as $line) {
        echo $line, "\n";
    }

    exit(0);
}

$differences = $generator->differences();

if ($differences === []) {
    echo "vérifié — les fichiers générés sont à jour.\n";
    exit(0);
}

foreach ($differences as $difference) {
    echo 'DIVERGENCE  ', $difference, "\n";
}
echo "\nRelancez « php tests/fixtures/reference-dataset/generate.php » puis committez le résultat.\n";
exit(1);
