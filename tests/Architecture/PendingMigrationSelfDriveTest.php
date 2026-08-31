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
            '?->afterProgressPage()',
            $this->pendingMigrationBlock(),
            'a request short-circuited by a pending migration must start the chain that finishes it: '
                . 'without this the migration waits for a human on the progress page, since the scheduler '
                . 'is unreachable behind this very block and the reference host has no crontab'
        );
    }

    public function testASliceThatLeavesWorkBehindEmitsTheNextHop(): void
    {
        $this->assertStringContainsString(
            '?->afterSlice(',
            $this->pendingMigrationBlock(),
            'the ignition emits ONE hop; without this the chain is one slice long'
        );
    }

    public function testTheBlockBuildsAChainAtAll(): void
    {
        $this->assertStringContainsString(
            'MigrationChain::forPendingMigration(',
            $this->pendingMigrationBlock(),
            'the block must build a chain at all; null from it is the supported "cannot chain here" answer'
        );
    }

    /**
     * Finishing the schema is not finishing the update. Writing VERSION,
     * closing the `update_history` row, purging old backups and notifying
     * all live in the queued `install_update` resume task, and the chain
     * that just migrated has no reason of its own to run it — so the block
     * has to hand back to the scheduler on its last slice.
     *
     * Without it the update sits at `migrating` until an unrelated request
     * happens to arrive, which is precisely what
     * `UpdateHistoryRepository`'s watchdog kept beating on scoutmagic.be:
     * six abandoned updates in forty-eight hours, the last two of them
     * with no schema change at all.
     */
    public function testTheLastMigrationSliceHandsBackToTheScheduler(): void
    {
        $block = $this->pendingMigrationBlock();

        $this->assertStringContainsString(
            'SchedulerKick::fromPdo(',
            $block,
            'the slice that completes the migration must start a scheduler pass: the resume task '
                . 'that finishes the update is queued and nothing else on this host will run it'
        );

        $kick = strpos($block, 'SchedulerKick::fromPdo(');
        $guard = strpos($block, 'if ($stepResult->complete) {');
        $this->assertNotFalse($guard, 'the hand-back must be guarded on the migration being complete');
        $this->assertLessThan($kick, $guard, 'hand back only once the migration is done, not after every slice');
    }

    /**
     * A migration slice is the one stretch of a long update that changes no
     * status, so it is the one stretch the abandoned-update watchdog would
     * read as silence. The endpoint stamps the heartbeat itself.
     */
    public function testASliceStampsTheUpdateHeartbeat(): void
    {
        $this->assertStringContainsString(
            '->touchInProgress()',
            $this->pendingMigrationBlock(),
            'a migration slice is proof the update that queued it is alive, and '
                . 'UpdateHistoryRepository::isStale() measures exactly that'
        );
    }

    /**
     * The two call sites must come AFTER the response they follow has been
     * written, since each ends by writing a socket: `afterSlice()` follows
     * the JSON, `afterProgressPage()` follows the HTML. The flush itself
     * lives inside those methods (MigrationChainTest covers the ordering);
     * what cannot be asserted anywhere but here is that `public/index.php`
     * calls them in the right place.
     */
    public function testEachCallFollowsTheResponseItBelongsTo(): void
    {
        $block = $this->pendingMigrationBlock();

        $json = strpos($block, "'progress' => round(");
        $afterSlice = strpos($block, '?->afterSlice(');
        $this->assertNotFalse($json);
        $this->assertNotFalse($afterSlice);
        $this->assertLessThan($afterSlice, $json, 'write the JSON, then chain');

        $html = strpos($block, 'HTML);');
        $afterPage = strpos($block, '?->afterProgressPage()');
        $this->assertNotFalse($html);
        $this->assertNotFalse($afterPage);
        $this->assertLessThan($afterPage, $html, 'write the page, then ignite');

        // The hand-back writes a socket too, so it belongs after
        // afterSlice() — which is where the response gets flushed.
        $kick = strpos($block, 'SchedulerKick::fromPdo(');
        $this->assertNotFalse($kick);
        $this->assertLessThan($kick, $afterSlice, 'flush, then hand back to the scheduler');
    }
}
