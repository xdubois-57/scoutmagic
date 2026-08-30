<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A source-level guard, for a property no functional test can reach.
 *
 * `public/index.php`'s pending-migration block runs before routing and
 * exits, so nothing that lives behind the router — the scheduler
 * continuation endpoint included — can be exercised while a migration is
 * pending. That is deliberate: visitors must not reach a half-migrated
 * site. What was NOT deliberate is that it also swallows
 * `Scheduler\SchedulerKick::now()`, the hop IT-07 added so a migration
 * would not wait for a visitor. On 2026-08-30 that cost thirty minutes of
 * a frozen queue and one failed update, on an installation that has never
 * run `public/cron.php` once.
 *
 * The block therefore has to drive the migration itself. This test asserts
 * that it still does — an assertion worth having precisely because the
 * defect it guards against passed every functional test, every browser
 * test and a production deploy without anything going red.
 */
class PendingMigrationSelfDriveTest extends TestCase
{
    private function pendingMigrationBlock(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $start = strpos($source, 'if ($migrationIsPending) {');
        $this->assertNotFalse($start, 'public/index.php no longer has a pending-migration block');

        // Everything from the block's opening to the composition root that
        // follows it — the block ends by exiting, so this is all of it.
        $end = strpos($source, '$pdo = $connection->getPdo();', $start);
        $this->assertNotFalse($end, 'could not find the end of the pending-migration block');

        return substr($source, $start, $end - $start);
    }

    public function testTheBlockedRequestIgnitesTheMigrationChain(): void
    {
        $this->assertStringContainsString(
            '->ensureRunning()',
            $this->pendingMigrationBlock(),
            'a request short-circuited by a pending migration must start the chain that finishes it: '
                . 'without this the migration waits for a human on the progress page, since the scheduler '
                . 'is unreachable behind this very block and the reference host has no crontab'
        );
    }

    public function testASliceThatLeavesWorkBehindEmitsTheNextHop(): void
    {
        $this->assertStringContainsString(
            '->continueChain()',
            $this->pendingMigrationBlock(),
            'the ignition emits ONE hop; without this the chain is one slice long'
        );
    }

    public function testACompletedMigrationClosesItsChain(): void
    {
        $this->assertStringContainsString(
            '->finished()',
            $this->pendingMigrationBlock(),
            'the next migration must start from a clean hop counter, not inherit this one\'s'
        );
    }

    /**
     * The hop is a socket write. Doing it before the response is flushed
     * would charge it to whoever asked — including, on the progress page,
     * a real visitor.
     */
    public function testTheHopIsEmittedAfterTheResponseIsFlushed(): void
    {
        $block = $this->pendingMigrationBlock();
        $flush = strpos($block, 'fastcgi_finish_request');
        $ignite = strpos($block, '->ensureRunning()');

        $this->assertNotFalse($flush);
        $this->assertNotFalse($ignite);
        $this->assertLessThan($ignite, $flush, 'flush the page, then emit the hop');
    }
}
