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

    /**
     * The fingerprint of a divergence, as the workflow computes it: the
     * command substitution of its `fingerprint=` line.
     */
    private static function fingerprintCommand(): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . self::WORKFLOW);
        self::assertIsString($contents);
        self::assertSame(
            1,
            preg_match('/^\s*fingerprint="\$\((.+)\)"\s*$/m', $contents, $matches),
            'the workflow no longer computes its fingerprint on a line this test can read'
        );

        return $matches[1];
    }

    private static function fingerprintOf(string $section): string
    {
        $process = proc_open(
            ['bash', '-c', 'section="$1"; printf "%s" "$(' . self::fingerprintCommand() . ')"', 'bash', $section],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out;
    }

    /**
     * The fingerprint is how next week's run knows an open issue is still
     * about the same divergence: the same fingerprint gets no comment, a new
     * one gets « the divergence changed ». The checker words a source that
     * stayed silent through its retry with « after 2 attempts », which one
     * request never said — so, once the retry is on, every issue already
     * open for a silent source would be told, once, that its divergence
     * changed when nothing about the source had. The number of attempts is
     * how it was asked, not what is wrong: it must not be part of what is
     * compared.
     */
    public function testTheAttemptsASilentSourceWasAskedForAreNotPartOfItsFingerprint(): void
    {
        $once = "[scaleway-privacy] https://example.test/privacy\n"
            . "  ✗ no answer (Connection timed out) — unknown domain, or the host is down";
        $twice = "[scaleway-privacy] https://example.test/privacy\n"
            . "  ✗ no answer after 2 attempts (Connection timed out) — unknown domain, or the host is down";

        $this->assertNotSame('', self::fingerprintOf($once), 'the fingerprint command did not run');
        $this->assertSame(self::fingerprintOf($once), self::fingerprintOf($twice));
    }

    /** What the fingerprint is for still works: a divergence that really changed has another one. */
    public function testAChangedDivergenceStillHasAnotherFingerprint(): void
    {
        $silent = "[x] https://example.test\n  ✗ no answer (Connection timed out) — unknown domain, or the host is down";
        $gone = "[x] https://example.test\n  ✗ HTTP 404, expected 200";

        $this->assertNotSame(self::fingerprintOf($silent), self::fingerprintOf($gone));
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
