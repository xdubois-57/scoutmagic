<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The weekly check of the external sources asks a silent source a second
 * time (issue #1405).
 *
 * A host that does not answer at all to the one request of the weekly run
 * was declared DIVERGENT and an issue was opened for an address that was
 * right — `federal-data-protection-file` (#760), then `scaleway-privacy`
 * (#1385: no answer to the check, a 403 — alive — to a direct call).
 * `--retry-unreachable` (#760) asks such a source once more, and never one
 * that answered with a status. The release gate already passes it; this
 * pins that the weekly workflow does too, so that taking it out again is
 * seen rather than silently bringing the false alarms back.
 *
 * Line-based rather than YAML-parsed, for the reason
 * `ReleasePipelineIsWiredTest` gives: no YAML parser ships with this
 * repository, and the file is small and hand-written. It fails closed — a
 * restructured workflow whose invocation can no longer be found fails
 * rather than passes.
 */
final class ExternalSourcesWeeklyRetryTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/external-sources-check.yml';
    private const RELEASE_SCRIPT = 'scripts/release.sh';
    private const OPTION = '--retry-unreachable';

    /**
     * The command lines of $path that run the checker — a line that STARTS
     * with it, so a comment or the prose of an agent's prompt that names the
     * script is not mistaken for a call.
     *
     * @return list<string>
     */
    private static function invocations(string $path): array
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, $path . ' could not be read');

        $lines = [];
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^\s*php\s+\S*check-external-sources\.php\b/', $line) === 1) {
                $lines[] = trim($line);
            }
        }

        return $lines;
    }

    public function testTheWeeklyWorkflowRunsTheCheckerExactlyOnce(): void
    {
        $this->assertCount(
            1,
            self::invocations(self::WORKFLOW),
            'the weekly workflow must call scripts/check-external-sources.php on one line this test can read'
        );
    }

    public function testTheWeeklyWorkflowAsksASilentSourceASecondTime(): void
    {
        foreach (self::invocations(self::WORKFLOW) as $line) {
            $this->assertStringContainsString(
                self::OPTION,
                $line,
                'without ' . self::OPTION . ', one request that gets no answer files an issue for an address that is right'
            );
        }
    }

    /** The release gate is not changed by this: it asked twice before, and still does. */
    public function testTheReleaseGateStillAsksASilentSourceASecondTime(): void
    {
        $lines = self::invocations(self::RELEASE_SCRIPT);

        $this->assertNotSame([], $lines, 'scripts/release.sh no longer runs the checker on a line this test can read');
        foreach ($lines as $line) {
            $this->assertStringContainsString(self::OPTION, $line);
        }
    }
}
