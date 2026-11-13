<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * The suite's temporary directory is its own, and goes with it (#1015)
 * ---------------------------------------------------------------------
 * Hundreds of tests create files and directories under sys_get_temp_dir()
 * with a uniqid() or random_bytes() in the name, and many never remove
 * them — no tearDown(), a tearDown() that misses a level, a config file
 * written once and forgotten. On a machine whose /tmp outlives a run (a
 * developer's, a Claude Code container, a self-hosted runner) every run
 * left hundreds more: one container counted over 100 000.
 *
 * So the whole run gets ONE directory of its own under the system's
 * temporary directory, `scoutmagic-phpunit-<pid>-<random>`, TMPDIR points
 * at it — sys_get_temp_dir(), tempnam(), and any process a test starts
 * all land there — and it is removed, recursively, when the run ends. A
 * run killed outright leaves that one directory, named for what it is.
 *
 * **This has to be the first thing that runs.** PHP remembers the answer
 * of sys_get_temp_dir() from its first call; TMPDIR set after that changes
 * nothing, silently. Hence before the autoloader, and hence the check
 * below: a redirect that did not take is a bootstrap that refuses to run,
 * not a suite back to filling /tmp without a sound.
 * Tests\Bootstrap\TestTemporaryDirectoryTest asserts it from inside the run.
 */
(static function (): void {
    $systemTemp = getenv('TMPDIR');
    if (!is_string($systemTemp) || $systemTemp === '' || !is_dir($systemTemp)) {
        $configured = ini_get('sys_temp_dir');
        $systemTemp = is_string($configured) && $configured !== '' ? $configured : '/tmp';
    }

    $runDirectory = rtrim($systemTemp, '/') . '/scoutmagic-phpunit-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!mkdir($runDirectory, 0700) && !is_dir($runDirectory)) {
        throw new RuntimeException('The test run could not create its temporary directory: ' . $runDirectory);
    }
    $runDirectory = (string) realpath($runDirectory);

    putenv('TMPDIR=' . $runDirectory);
    $_ENV['TMPDIR'] = $runDirectory;
    if (sys_get_temp_dir() !== $runDirectory) {
        throw new RuntimeException(
            'sys_get_temp_dir() was called before tests/bootstrap.php redirected it: the run would leave its '
                . 'files in ' . sys_get_temp_dir() . ' (#1015).'
        );
    }
    define('SCOUTMAGIC_TEST_TEMP_DIR', $runDirectory);

    $owner = getmypid();
    register_shutdown_function(static function () use ($runDirectory, $owner): void {
        // A process this one forked inherits the handler, never the directory.
        if (getmypid() !== $owner || !is_dir($runDirectory)) {
            return;
        }
        // Writable again first, parents before children: a test that made
        // a directory read-only would otherwise keep what it holds.
        $directories = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($runDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($directories as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isDir() && !$entry->isLink()) {
                @chmod($entry->getPathname(), 0700);
            }
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($runDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            $path = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($runDirectory);
    });
})();

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
 * The suite runs as an HTTP-only environment, and says so explicitly.
 *
 * Core\Http\RequestScheme defaults to the production policy — HTTPS
 * required, so every cookie is Secure and every response carries HSTS
 * (#751). Tests reason about what PHP detects, so they get the same
 * explicit `https_required = false` a development config/app.php sets;
 * a test that needs the production policy turns it on and restores this.
 */
\Core\Http\RequestScheme::setHttpsRequired(false);

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
