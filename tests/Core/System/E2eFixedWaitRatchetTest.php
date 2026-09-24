<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

/**
 * A Playwright scenario does not wait a fixed number of milliseconds.
 *
 * The reason is a bug that was invisible until the day it wasn't. Two
 * scenarios wrote `waitForTimeout(4000)` to clear `Core\Security\HumanCheck`,
 * which refuses a form submitted faster than a human could have filled it.
 * That 4000 is a copy of the server's threshold plus a margin — and the
 * threshold is a **setting**, `human_check_min_delay_seconds`, not a
 * constant. Raise it past four seconds and those two scenarios turn red
 * with no regression behind the failure and nothing in the message to say
 * so, while the five that call `waitOutHumanCheckDelay()` stay correct:
 * that helper decodes the token the form actually carries and waits out
 * only what is left to run.
 *
 * The wider rule is the same one in every Playwright guide: a fixed sleep
 * is a guess about a machine's speed. It is too short on a loaded CI
 * runner — the failure everyone sees — and too long everywhere else, which
 * is the cost nobody attributes to it.
 *
 * BOTH DIRECTIONS, like `Tests\Security\StoredDateReadingRatchetTest`: a
 * new call fails, and so does a stale entry for a file that no longer has
 * one.
 */
class E2eFixedWaitRatchetTest extends TestCase
{
    /**
     * The two places allowed to wait by the clock, and why.
     *
     * @var array<string, array{count: int, reason: string}>
     */
    private const DELIBERATE = [
        'tests/e2e/support/human-check.js' => [
            'count' => 1,
            'reason' => 'The home. It waits out what the token says is LEFT of the delay, '
                . 'having decoded the moment the form was issued — which is the opposite of '
                . 'guessing, and is why every scenario calls this instead of counting itself.',
        ],
        'tests/e2e/specs/pwa-prefetch-once.spec.js' => [
            'count' => 1,
            'reason' => 'Proving a prefetch happens ONCE means watching a window in which a '
                . 'second one would have happened. There is no event for "nothing else came", '
                . 'so the window is the assertion.',
        ],
    ];

    public function testNoScenarioWaitsAFixedNumberOfMilliseconds(): void
    {
        $offenders = [];
        $seen = [];
        $repoRoot = dirname(__DIR__, 3);

        foreach ($this->specFiles($repoRoot) as $relative => $path) {
            $source = (string) file_get_contents($path);

            // The whole file, and `\s*` between the name and its
            // parenthesis: `waitForTimeout (4000)` is the same fixed wait,
            // so is one written across a line break, and a ratchet that a
            // space walks past is not one.
            $count = preg_match_all(
                '/waitForTimeout\s*\(/',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE
            );

            if ($count === false || $count === 0) {
                continue;
            }

            if (!isset(self::DELIBERATE[$relative])) {
                foreach ($matches[0] as [, $offset]) {
                    $line = substr_count($source, "\n", 0, $offset) + 1;
                    $offenders[] = sprintf('%s:%d', $relative, $line);
                }
            }

            $seen[$relative] = $count;
        }

        $this->assertSame(
            [],
            $offenders,
            "A scenario must not wait a fixed number of milliseconds: it is a guess about a\n"
            . "machine's speed, too short on a loaded runner and too long everywhere else.\n"
            . "Wait for the thing itself — an expect(), a response, a URL — or, for the human\n"
            . "check, call waitOutHumanCheckDelay(), which reads the delay left from the token\n"
            . "rather than copying a threshold that is a setting.\n\n"
            . implode("\n", $offenders)
        );

        $expected = array_map(static fn (array $entry): int => $entry['count'], self::DELIBERATE);
        ksort($expected);
        ksort($seen);

        $this->assertSame(
            $expected,
            $seen,
            "The list of deliberate fixed waits is out of date. Remove the entry if the wait is\n"
            . 'gone; add one, with its reason, if a new scenario genuinely needs a window.'
        );
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function specFiles(string $repoRoot): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repoRoot . '/tests/e2e', \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'js') {
                continue;
            }

            $found[substr($file->getPathname(), strlen($repoRoot) + 1)] = $file->getPathname();
        }

        return $found;
    }
}
