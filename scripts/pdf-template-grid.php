<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * Development utility: print one of the federation's templates with a
 * millimetre grid over it, so the coordinates in a document's layout map can
 * be READ rather than guessed.
 *
 *     php scripts/pdf-template-grid.php modules/official_documents/templates/autorisation-parentale.pdf
 *     php scripts/pdf-template-grid.php <template.pdf> <output.pdf>
 *
 * With no second argument the grid lands beside the template as
 * `<name>-grid.pdf`. `modules/official_documents/templates/README.md` step 3
 * names this script, so renaming it means changing that file in the same
 * change.
 *
 * The drawing itself lives in `Modules\OfficialDocuments\Pdf\TemplateGrid`,
 * which is where it can be tested; what is left here is argument reading and
 * one `file_put_contents`. Not a route and not a service, deliberately: it
 * runs on a maintainer's machine when a template changes, which is a handful
 * of times in the life of the project.
 */

declare(strict_types=1);

use Modules\OfficialDocuments\Pdf\TemplateGrid;

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

$target = $argv[2] ?? TemplateGrid::defaultTargetFor($source);

file_put_contents($target, TemplateGrid::over($source));

echo "Grid written to {$target}\n";
echo "Read a coordinate off it, type it into the document's layout map, and LOOK at the result.\n";
