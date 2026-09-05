<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * PHP line-coverage collector for the end-to-end run.
 *
 * Loaded as `auto_prepend_file` on the `php -S` process scripts/e2e.sh
 * starts, and only when coverage was asked for (E2E_COVERAGE=1 — see that
 * script). Every request records which application lines the browser
 * actually caused to run; `scripts/e2e-support.php merge-coverage` folds
 * the fragments into one Clover report SonarQube Cloud reads alongside
 * PHPUnit's.
 *
 * Why a prepend file: the code under test runs in a *different process*
 * from Playwright, one execution per request, so nothing on the browser
 * side can instrument it. This is the only seam where collection can
 * start before the application boots and stop after it has responded.
 *
 * Why the bare pcov extension rather than phpunit/php-code-coverage,
 * which the merge step does use: loading Composer's autoloader here would
 * break the application. public/index.php opens with
 *
 *     $composerAutoloader = require_once __DIR__ . '/../vendor/autoload.php';
 *
 * and passes that value to Core\System\ComposerAutoloadSync::apply(). A
 * second `require_once` of an already-loaded file returns `true`, not the
 * ClassLoader — so prepending anything that autoloads first turns every
 * single request into a TypeError. (Found exactly that way: switching the
 * collector on made all five scenarios fail at once.) pcov is an
 * extension, needs no autoloader, and cannot collide with the
 * application's.
 *
 * Deliberately silent and non-fatal on every failure path: a coverage
 * collector that can 500 the application would turn a reporting nicety
 * into a test-suite outage.
 *
 * @see scripts/e2e.sh
 * @see scripts/e2e-support.php
 */

(static function (): void {
    $outputDir = getenv('E2E_COVERAGE_DIR');
    if (!is_string($outputDir) || $outputDir === '' || !is_dir($outputDir)) {
        return;
    }

    if (!extension_loaded('pcov') || !function_exists('pcov\start')) {
        return;
    }

    \pcov\start();

    register_shutdown_function(static function () use ($outputDir): void {
        try {
            \pcov\stop();

            /** @var array<string, array<int, int>> $data */
            $data = \pcov\collect(\pcov\all);
            if ($data === []) {
                return;
            }

            $data = e2e_coverage_map_instance_paths($data);

            // One file per request rather than one shared file: several
            // executions write here over a run, and appending to a single
            // file would be a lost-update race. Merging is the merge
            // step's job, not the writer's.
            $path = sprintf(
                '%s/%s-%s.cov',
                $outputDir,
                // Unique and sortable without needing any coordination.
                (string) hrtime(true),
                bin2hex(random_bytes(6))
            );

            file_put_contents($path, serialize($data), LOCK_EX);
        } catch (Throwable) {
            // See this file's docblock: never fatal.
        }
    });
})();

/**
 * Re-attribute the throwaway instance's own files to the repository.
 *
 * scripts/e2e-support.php serves the application from a temporary
 * instance directory whose public/ is a real copy (index.php's __DIR__
 * has to resolve inside it). Coverage for that copy is therefore recorded
 * against a /tmp path that means nothing to SonarQube, which only knows
 * the repository. core/ and modules/ need no such treatment: they are
 * symlinks, and PHP resolves __FILE__ through symlinks, so they are
 * already recorded at their repository paths.
 *
 * @param array<string, array<int, int>> $data
 * @return array<string, array<int, int>>
 */
function e2e_coverage_map_instance_paths(array $data): array
{
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

    // A cron pass has no document root: scripts/e2e-support.php's
    // run-scheduler executes the instance's public/cron.php on the CLI,
    // where $_SERVER['DOCUMENT_ROOT'] is simply absent. Without this the
    // whole pass — the entry point and everything a scheduled task
    // reaches through it — was recorded against the temporary instance
    // path and dropped by the merge, so the report read 0 % for code that
    // seven passes a run execute from end to end.
    if (!is_string($documentRoot) || $documentRoot === '') {
        $instanceDir = getenv('E2E_INSTANCE_DIR');
        $documentRoot = is_string($instanceDir) && $instanceDir !== ''
            ? $instanceDir . '/public'
            : '';
    }

    $instancePublic = is_string($documentRoot) && $documentRoot !== '' ? realpath($documentRoot) : false;
    if ($instancePublic === false) {
        return $data;
    }

    $repositoryPublic = dirname(__DIR__) . '/public';
    if ($instancePublic === $repositoryPublic) {
        return $data;
    }

    $mapped = [];
    foreach ($data as $file => $lines) {
        $mapped[str_starts_with($file, $instancePublic . '/')
            ? $repositoryPublic . substr($file, strlen($instancePublic))
            : $file] = $lines;
    }

    return $mapped;
}
