<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Checks every page outside this site that the site depends on (issue
 * #355): `php scripts/check-external-sources.php`.
 *
 * Reads the register (Core\ExternalSource\ExternalSources), fetches each
 * page, and prints one line per source — divergences first. Exits 0 when
 * everything conforms, 1 on any divergence, 2 when it could not run.
 *
 * Three callers, one verdict:
 * - `scripts/release.sh`'s sixth gate, « Sources externes », which refuses
 *   the release on exit 1;
 * - `.github/workflows/external-sources-check.yml`, weekly, where Claude
 *   reads this report and opens or updates an issue per divergence
 *   (`.claude/skills/external-sources/SKILL.md`);
 * - an agent told to « fixe le backlog » (AGENTS.md), before it lists the
 *   accepted issues.
 *
 * Everything that decides lives in Core\ExternalSource\ExternalSourceChecker,
 * tested on recorded pages; this file only wires the real network in.
 */

use Core\ExternalSource\ExternalSourceChecker;
use Core\ExternalSource\ExternalSources;
use Core\ExternalSource\StreamPageFetcher;

// The in-code guard is the authority (SECURITY.md §24): scripts/ ships, and
// .htaccess does not apply on nginx. Served over HTTP, each request would
// fetch every registered page.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "check-external-sources.php is a CLI script.\n");
    exit(1);
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found — run composer install first.\n");
    exit(2);
}
require $autoload;

// THE SHIPPED SCALE IS THE EXTENSION POINT. None is shipped yet: issue
// #355 adds it in a later pull request. Until then the fees page's amounts
// are extracted and REPORTED, not compared, and the report says so. When
// the shipped scale lands, it is passed as the second argument here and the
// comparison in ExternalSourceChecker switches on — nothing else changes.
$checker = new ExternalSourceChecker(new StreamPageFetcher(), referenceScale: null);
$results = $checker->checkAll(ExternalSources::all());

echo ExternalSourceChecker::report($results);

exit(ExternalSourceChecker::allConform($results) ? 0 : 1);
