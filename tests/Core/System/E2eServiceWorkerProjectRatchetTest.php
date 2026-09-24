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
        $projects = strpos($config, 'projects: [');
        $this->assertNotFalse($projects);

        // WHERE the block sits, not merely that it appears once. Moved out
        // of the top-level `use` and into `chromium`'s own, the count
        // stays at one and the suite-wide default is gone: a project added
        // later, declaring nothing, would then inherit Playwright's own
        // default for this option, which is 'allow' — issue #452's failure
        // mode, handed to the next project.
        $this->assertSame(
            1,
            substr_count(substr($config, 0, $projects), "serviceWorkers: 'block'"),
            'the block must stay in the suite-wide `use`, where a project declaring nothing inherits it'
        );
        $this->assertSame(
            0,
            substr_count(substr($config, $projects), "serviceWorkers: 'block'"),
            'a second block inside a project would say the default is not trusted'
        );

        $this->assertSame(
            1,
            substr_count($config, "serviceWorkers: 'allow'"),
            'exactly one project may allow a worker; a second would spread the non-determinism'
        );
        $this->assertSame(
            'service-worker',
            self::projectAllowingWorkers($config),
            'and it must be the worker project — anywhere else, the allowance is the defect'
        );
    }

    /**
     * Which project declares `serviceWorkers: 'allow'`.
     *
     * Counting the allowance without naming its project would pass with
     * it granted to `chromium`, which is the whole suite.
     */
    private static function projectAllowingWorkers(string $config): ?string
    {
        $offset = strpos($config, 'projects: [');
        if ($offset === false) {
            return null;
        }

        $current = null;
        foreach (explode("\n", substr($config, $offset)) as $line) {
            if (preg_match("/name: '([^']+)',/", $line, $matches) === 1) {
                $current = $matches[1];
            }
            if (str_contains($line, "serviceWorkers: 'allow'")) {
                return $current;
            }
        }

        return null;
    }

    public function testTheTwoProjectsPartitionTheSpecs(): void
    {
        $config = self::config();

        // A REAL globstar. `**` is one only when it is a whole path
        // segment: in `**.spec.js` minimatch collapses it to `*.spec.js`,
        // which matches one level and no deeper. Measured with a spec
        // planted in specs/service-worker/nested/: under the collapsed
        // pattern it ran in `chromium`, with workers blocked, proving
        // nothing — issue #452's own failure mode, for a file added later.
        $this->assertMatchesRegularExpression(
            '#const SERVICE_WORKER_SPECS = \'\*\*/service-worker/\*\*/\*\.spec\.js\';#',
            $config,
            'the glob the two projects share must name the worker directory, and recurse into it'
        );

        // Matched by one project, ignored by the other — and WHICH is
        // which is the whole partition. Asserting that both keywords
        // appear somewhere would pass just as well with the two swapped:
        // `chromium` would then run only the worker specs, blocked, and
        // `service-worker` would run everything else with workers on.
        $this->assertSame(
            'testIgnore',
            self::partitionKeywordOf($config, 'chromium'),
            'the default project must IGNORE the worker specs'
        );
        $this->assertSame(
            'testMatch',
            self::partitionKeywordOf($config, 'service-worker'),
            'the worker project must MATCH them, and nothing else'
        );
    }

    /**
     * Which of the two partition keywords a named project carries.
     *
     * Read between that project's `name:` and the next one, which is what
     * anchors each keyword to its own block rather than to the file.
     */
    private static function partitionKeywordOf(string $config, string $project): ?string
    {
        $start = strpos($config, "name: '" . $project . "',");
        if ($start === false) {
            return null;
        }

        $next = strpos($config, 'name: \'', $start + 1);
        $block = $next === false ? substr($config, $start) : substr($config, $start, $next - $start);

        foreach (['testIgnore', 'testMatch'] as $keyword) {
            if (str_contains($block, $keyword . ': SERVICE_WORKER_SPECS,')) {
                return $keyword;
            }
        }

        return null;
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

        // Recursive, because the glob is: a file one level down is as much
        // a spec of this project as a file at the top.
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $found[] = substr($file->getPathname(), strlen(self::repoRoot()) + 1);
            }
        }

        sort($found);
        $this->assertNotSame([], $found, 'the project would otherwise run nothing at all');

        foreach ($found as $path) {
            $this->assertStringEndsWith(
                '.spec.js',
                $path,
                $path . ' is matched by neither project'
            );
        }
    }
}
