<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A recurring task re-arms itself through `rearm()`, never through the
 * unguarded `schedule()`/`scheduleAfter()`.
 *
 * **The defect this exists for ran for weeks on the reference
 * installation.** Its event journal was 91 % « tâche planifiée terminée »
 * — 1 588 entries in 48 hours — because `sync_mailboxes` ran NINE times
 * per pass, `analyze_stored_messages` once a minute, and half the purges
 * twice a day instead of once. The mechanism has two halves:
 *
 * 1. A duplicate chain is BORN when a page view seeds a task whose row is
 *    `processing` at that instant — the seed's guard only sees `pending`,
 *    so it finds nothing and queues a second chain. `public/index.php`
 *    runs those seeds on every request, so the window is hit regularly.
 * 2. A duplicate chain NEVER DIES while each copy re-arms blindly: N rows
 *    run, N rows are queued, and the count is stable at N for ever.
 *
 * Closing (2) makes (1) harmless: whichever copy runs first queues the
 * successor, every other copy finds it pending and stands down, and the
 * chain is back to one row after a single pass. That is what `rearm()`
 * does and what `scheduleAfter()` deliberately does not.
 *
 * The signature of a recurring chain is its **fixed reference**: a
 * one-shot follow-up (a retry carrying a payload, a batch's next slice, a
 * fan-out) passes none, and those are left alone.
 */
class RecurringTasksRearmTest extends TestCase
{
    public function testNoTaskHandlerArmsARecurringChainWithoutTheGuard(): void
    {
        $offenders = [];

        foreach (self::handlerFiles() as $file) {
            $source = (string) file_get_contents($file);
            $relative = substr($file, strlen(dirname(__DIR__, 2)) + 1);

            foreach (self::unguardedReferencedCalls($source) as $line => $call) {
                $offenders[] = $relative . ':' . $line . ' — ' . $call;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These calls arm a task under a FIXED REFERENCE without the duplicate guard.\n"
            . "A fixed reference means a recurring chain; use rearm() or rearmAfter() so a\n"
            . "duplicate chain collapses on its next pass instead of living for ever:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * The guard above is only worth anything if it is reading real files:
     * a glob that matched nothing would pass silently for ever.
     */
    public function testTheScanActuallyFoundTheTaskHandlers(): void
    {
        $this->assertGreaterThanOrEqual(30, count(self::handlerFiles()));
    }

    /**
     * @return list<string>
     */
    private static function handlerFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach (['core', 'modules'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/implements[^{]*TaskHandlerInterface/', $source) === 1) {
                    $found[] = $file->getPathname();
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * @return array<int, string> line number => the offending call, collapsed
     */
    private static function unguardedReferencedCalls(string $source): array
    {
        $found = [];
        $lines = explode("\n", $source);

        foreach ($lines as $index => $line) {
            if (preg_match('/(?<!re)(?:scheduleAfter|->schedule)\s*\(/', $line) !== 1) {
                continue;
            }

            // The call can span several lines; a reference, when there is
            // one, is its last argument.
            $call = (string) preg_replace('/\s+/', ' ', implode(' ', array_slice($lines, $index, 8)));
            $call = substr($call, 0, (int) (strpos($call, ');') ?: strlen($call)));

            if (preg_match("/,\s*(self::REFERENCE|'[a-z_]+')\s*\)?$/", trim($call)) === 1) {
                $found[$index + 1] = trim($call);
            }
        }

        return $found;
    }
}
