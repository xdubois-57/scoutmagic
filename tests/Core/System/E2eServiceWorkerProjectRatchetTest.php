<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

/**
 * The service worker runs in exactly one project, and only there.
 *
 * Issue #452's finding was that `public/sw.js` was never executed as a
 * service worker anywhere: jsdom has no worker runtime and says so, and
 * `playwright.config.js` blocked workers for the whole suite. The fix is
 * a second Playwright project — `service-worker`, the only one with
 * `serviceWorkers: 'allow'` — scoped to `specs/service-worker/**`.
 *
 * That arrangement has two ways of quietly coming undone, and this holds
 * both:
 *
 * - the block being relaxed for the whole suite, which would put every
 *   other spec back at the mercy of a worker caching in the background —
 *   the non-determinism the config's own comment describes;
 * - the partition slipping, so a worker spec is run by the default
 *   project (blocked, hence proving nothing) or by both (twice, once
 *   pointlessly).
 *
 * Read as text rather than by running Playwright, for the reason
 * `Tests\Core\System\E2eCollapsePanelRatchetTest` gives: the E2E suite
 * needs a provisioned instance and thirteen minutes, and this question is
 * about what the file says.
 */
final class E2eServiceWorkerProjectRatchetTest extends TestCase
{
    private const CONFIG = 'tests/e2e/playwright.config.js';

    /** Where a spec is allowed to register a real worker. */
    private const WORKER_SPEC_DIRECTORY = 'tests/e2e/specs/service-worker';

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function config(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/' . self::CONFIG);
    }

    public function testTheBlockIsStillTheSuiteDefaultAndTheAllowanceIsSingle(): void
    {
        $config = self::config();

        $this->assertSame(
            1,
            substr_count($config, "serviceWorkers: 'block'"),
            'the suite default must stay a block — every spec but the worker\'s own depends on it'
        );

        $this->assertSame(
            1,
            substr_count($config, "serviceWorkers: 'allow'"),
            'exactly one project may allow a worker; a second would spread the non-determinism'
        );
    }

    public function testTheTwoProjectsPartitionTheSpecs(): void
    {
        $config = self::config();

        $this->assertMatchesRegularExpression(
            '/const SERVICE_WORKER_SPECS = \'\*\*\/service-worker\/\*\*\.spec\.js\';/',
            $config,
            'the glob the two projects share must still name the worker directory'
        );

        // Matched by one project, ignored by the other. A spec claimed by
        // both would run twice — once with the worker blocked, which is
        // the shape this whole arrangement exists to avoid.
        $this->assertStringContainsString('testIgnore: SERVICE_WORKER_SPECS,', $config);
        $this->assertStringContainsString('testMatch: SERVICE_WORKER_SPECS,', $config);
    }

    /**
     * The security scan replays the browser suite through OWASP ZAP, and
     * a worker answering a navigation out of Cache Storage is a request
     * ZAP never sees — which does not merely add noise, it SHRINKS the
     * site map the scan is measured against.
     *
     * `scripts/dast.sh` therefore names the project it replays. Asserted
     * here rather than left to the comment there, because the failure it
     * prevents is silent on both sides: the scan would still pass, over
     * less.
     */
    public function testTheSecurityScanReplaysOnlyTheProjectWithoutAWorker(): void
    {
        $dast = (string) file_get_contents(self::repoRoot() . '/scripts/dast.sh');

        $this->assertStringContainsString(
            '--project=chromium --grep-invert @full',
            $dast,
            'the scan must name the project it replays, or a worker will serve pages ZAP never records'
        );
    }

    /**
     * A file the glob would not match is a file that runs in neither
     * project — present, green by absence, and proving nothing.
     */
    public function testEveryFileInTheWorkerDirectoryIsASpecTheGlobMatches(): void
    {
        $directory = self::repoRoot() . '/' . self::WORKER_SPEC_DIRECTORY;
        $this->assertDirectoryExists($directory);

        $files = array_values(array_diff((array) scandir($directory), ['.', '..']));

        $this->assertNotSame([], $files, 'the project would otherwise run nothing at all');

        foreach ($files as $file) {
            $this->assertStringEndsWith(
                '.spec.js',
                (string) $file,
                self::WORKER_SPEC_DIRECTORY . '/' . $file . ' is matched by neither project'
            );
        }
    }
}
