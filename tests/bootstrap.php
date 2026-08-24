<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * PHPUnit's bootstrap: Composer's autoloader, plus one thing that has to
 * happen before a single test renders a template.
 *
 * ---------------------------------------------------------------------
 * Why the compiled Twig cache is dropped here
 * ---------------------------------------------------------------------
 * Core\View\TwigFactory caches compiled templates under
 * storage/temp/twig_cache/<VERSION> and keys that cache on VERSION ALONE,
 * with `auto_reload` off whenever `debug` is false — which is what a test
 * builds. That is a deliberate production choice (an install upgrades by
 * version, and TwigFactory's own docblock says editing a .twig in place is
 * what debug mode is for), and it is correct there.
 *
 * It is not correct here. A developer's checkout edits .twig files all day
 * without touching VERSION, so the cache holds whatever those templates
 * looked like the first time a test rendered them — and every later run
 * asserts against that, not against the file on disk.
 *
 * This is not hypothetical: a test asserting on a badge that a template
 * had just gained failed on a working tree that contained the badge,
 * because the compiled copy predated it. It read as a real defect in the
 * feature, and half an hour went into looking for it in the code. The
 * reverse is worse and quieter: a template whose fix is not compiled yet
 * keeps a test green.
 *
 * Cheap to do and safe to do: the cache is derived data with no source of
 * truth of its own, and it is rebuilt by the first render that needs each
 * template. scripts/e2e-support.php drops the same directory at
 * provisioning, for exactly the same reason.
 */

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * The suite runs under the application's own wall clock.
 *
 * public/index.php and public/cron.php both call this; a suite left on the
 * host's `date.timezone` (UTC in CI, anything at all on a developer's
 * machine) would be testing a regime the application never runs in.
 *
 * Note what this does NOT fix, because it cannot: SQLite's CURRENT_TIMESTAMP
 * is UTC and it has no session timezone to align the way MySQL's is
 * (Core\Database\Connection). A test on the in-memory SQLite database
 * (Tests\DatabaseTestHelper) must therefore write its timestamps from PHP
 * rather than leaning on a column default or on `datetime('now')` whenever
 * it later compares them against a PHP-computed instant — which is exactly
 * the rule the repositories under test follow. See Core\Config\AppClock.
 */
\Core\Config\AppClock::apply();

/**
 * When this bootstrap ran. Tests\Bootstrap\TwigCacheFreshnessTest asserts
 * that every compiled template the suite has produced is newer than this,
 * which is what keeps the drop below from being quietly reverted.
 */
define('SCOUTMAGIC_TEST_BOOTSTRAP_AT', time());

(static function (): void {
    $cacheRoot = __DIR__ . '/../storage/temp/twig_cache';
    if (!is_dir($cacheRoot)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entries as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
            continue;
        }
        @unlink($entry->getPathname());
    }

    @rmdir($cacheRoot);
})();
