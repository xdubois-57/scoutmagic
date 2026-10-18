<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

/**
 * A scenario that turns the scheduler raises its own time budget.
 *
 * `runScheduler()` (tests/e2e/support/scheduler.js) runs the instance's real
 * public/cron.php, and a pass is not a few seconds: it migrates the whole
 * declared schema, then drains every task the earlier scenarios left
 * overdue. How long that takes depends on everything that ran before, on
 * the engine and on the runner — so it is never small, and never stable.
 *
 * Playwright's default budget is `scaled(60_000)` (playwright.config.js).
 * `gallery-download.spec.js` turned the scheduler inside it, and on CI the
 * pass alone took 53 s: every assertion after it passed, and the test
 * still failed on the timeout, once in a while — the worst kind of red,
 * because a re-run makes it go away. Every other scenario that turns the
 * scheduler had already raised its budget with `test.setTimeout(scaled(…))`;
 * this holds the rule for the next one.
 *
 * Read per test, not per file: a file whose second test turns the
 * scheduler gains nothing from a budget raised in its first. A test is the
 * text from one `test(` call to the next.
 */
class E2eSchedulerBudgetTest extends TestCase
{
    public function testEveryTestThatTurnsTheSchedulerRaisesItsBudget(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $offenders = [];
        $checked = 0;

        foreach (glob($repoRoot . '/tests/e2e/specs/*.spec.js') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            foreach (self::testsIn($source) as $title => $body) {
                if (!str_contains($body, 'runScheduler(')) {
                    continue;
                }
                $checked++;
                if (!str_contains($body, 'test.setTimeout(scaled(')) {
                    $offenders[] = basename($path) . ' › ' . $title;
                }
            }
        }

        // The scan must keep finding them: a reader that stopped matching
        // would pass everything.
        $this->assertGreaterThanOrEqual(6, $checked, 'the scan no longer finds the scenarios that turn the scheduler');
        $this->assertSame(
            [],
            $offenders,
            "Each of these turns the scheduler without raising its budget. Add\n"
            . "    test.setTimeout(scaled(180_000));\n"
            . "at the top of the test (support/timeouts.js), with the reason:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * @return array<string, string> test title => the text up to the next test
     */
    private static function testsIn(string $source): array
    {
        preg_match_all(
            '/^\s*test\(\s*([\'"`])(.*?)\1/m',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $tests = [];
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $start = $matches[0][$i][1];
            $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($source);
            $tests[$matches[2][$i][0]] = substr($source, $start, $end - $start);
        }

        return $tests;
    }
}
