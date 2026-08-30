<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\MigrationProgress;
use Core\Database\MigrationResult;
use PHPUnit\Framework\TestCase;

/**
 * A self-update runs OLD code against NEW classes, and this is the test
 * that was missing when that took production down.
 *
 * Task\InstallUpdateHandler replaces the files on disk and then runs the
 * migration in the same PHP process. Classes already loaded stay old; a
 * class not yet loaded is autoloaded from the new files. MigrationResult
 * is only ever constructed by MigrationRunner::migrate(), which nothing
 * calls on an ordinary request — so it is reliably not loaded when an
 * update begins, and reliably loaded from the new files moments later.
 *
 * Removing a constructor parameter therefore breaks the update itself, not
 * merely the code that reads it: the old runner's named argument hits a
 * class that no longer declares it, PHP throws "Unknown named parameter",
 * the handler catches it, and the update rolls back. Every retry runs the
 * same old code, so the site can never install the version that would fix
 * it. Six identical rollbacks on scoutmagic.be before this was understood.
 *
 * These are the exact call sites of the last version that shipped the old
 * shape (dev-8e3b6c1). They must keep working until no installation
 * predates it.
 */
class SelfUpdateCompatibilityTest extends TestCase
{
    public function testTheOldRunnersShortCircuitCallStillConstructs(): void
    {
        $result = new MigrationResult(executedStatements: [], warnings: [], backupCreated: false);

        $this->assertTrue($result->complete);
        $this->assertFalse($result->hasChanges());
    }

    public function testTheOldRunnersLockRefusedCallStillConstructs(): void
    {
        $result = new MigrationResult(
            executedStatements: [],
            warnings: [],
            backupCreated: false,
            complete: false,
            progressFraction: 0.0
        );

        $this->assertFalse($result->complete);
        $this->assertSame(0.0, $result->progressFraction);
    }

    /**
     * The shim is inert: it must not change what any current field means.
     * Kept as a real property rather than a discarded argument so the
     * compatibility promise is visible in the type — but nothing reads it,
     * and its presence must not disturb the values that are read.
     */
    public function testTheShimIsInertForCurrentCallers(): void
    {
        $withShim = new MigrationResult(
            executedStatements: ['ALTER TABLE x ADD COLUMN y INT'],
            warnings: ['w'],
            complete: false,
            progressFraction: 0.5,
            backupCreated: true
        );
        $without = new MigrationResult(
            executedStatements: ['ALTER TABLE x ADD COLUMN y INT'],
            warnings: ['w'],
            complete: false,
            progressFraction: 0.5
        );

        $this->assertSame($without->complete, $withShim->complete);
        $this->assertSame($without->progressFraction, $withShim->progressFraction);
        $this->assertSame($without->executedStatements, $withShim->executedStatements);
        $this->assertSame($without->hasChanges(), $withShim->hasChanges());
        $this->assertFalse($without->backupCreated, 'the shim must default to a value nothing depends on');
    }

    /**
     * MigrationProgress is loaded under exactly the same conditions as
     * MigrationResult — nothing constructs it on an ordinary request — so
     * it is the second class the old runner meets in its new shape.
     *
     * This replays the old runner's access pattern verbatim (the version
     * that shipped a statement queue: build the table queue, diff one
     * table at a time, execute one statement at a time). Against a class
     * that had dropped those properties, `array_push()` on a null read
     * raises a TypeError, InstallUpdateHandler rolls the update back, and
     * the retry runs the same old code forever.
     */
    public function testTheOldRunnersQueueDrivenLoopStillRunsAgainstTheNewProgress(): void
    {
        $progress = MigrationProgress::start('hash-of-the-target-schema');

        // Step 1 — build the table queue.
        $progress->actualTableNames = ['users', 'legacy_table'];
        $progress->warnings[] = "Table 'legacy_table' exists in database but not in declared schema.";
        $progress->remainingTableNames = ['users', 'groups'];
        $progress->totalTableCount = count($progress->remainingTableNames);
        $progress->tableQueueBuilt = true;

        // Step 2 — diff one declared table at a time.
        while (!empty($progress->remainingTableNames)) {
            $tableName = array_shift($progress->remainingTableNames);
            array_push($progress->pendingStatements, "ALTER TABLE {$tableName} ADD COLUMN c INT");
            array_push($progress->warnings, ...[]);
        }

        $progress->totalStatementCount = count($progress->pendingStatements);

        // Step 3 — execute one statement at a time; one of them fails.
        while (!empty($progress->pendingStatements)) {
            $statement = array_shift($progress->pendingStatements);
            if (str_contains($statement, 'groups')) {
                $progress->failedCount++;
                $progress->warnings[] = "Failed to execute: {$statement}";
                continue;
            }
            $progress->executedStatements[] = $statement;
        }

        // Step 4 — explicit drops.
        array_push($progress->executedStatements, 'ALTER TABLE users DROP COLUMN gone');
        $progress->dropsApplied = true;

        $this->assertSame(2, $progress->totalTableCount);
        $this->assertSame(2, $progress->totalStatementCount);
        $this->assertSame(1, $progress->failedCount);
        $this->assertSame(
            ['ALTER TABLE users ADD COLUMN c INT', 'ALTER TABLE users DROP COLUMN gone'],
            $progress->executedStatements
        );
    }

    /**
     * Declaring the properties is not enough on its own: the old runner
     * checkpoints between statements and resumes from the persisted row,
     * so a shim that is not serialised loses the queue and — worse —
     * resets $failedCount, which is what gates the old runner's
     * schema-hash caching. A hash cached after a failed pass is a schema
     * the site then believes is up to date.
     */
    public function testTheOldRunnersCheckpointSurvivesARoundTrip(): void
    {
        $progress = MigrationProgress::start('target');
        $progress->tableQueueBuilt = true;
        $progress->actualTableNames = ['users'];
        $progress->remainingTableNames = ['groups'];
        $progress->totalTableCount = 2;
        $progress->pendingStatements = ['ALTER TABLE users ADD COLUMN c INT'];
        $progress->failedCount = 1;

        $resumed = MigrationProgress::fromArray(
            (array) json_decode((string) json_encode($progress->toArray()), true)
        );

        $this->assertNotNull($resumed);
        $this->assertTrue($resumed->tableQueueBuilt);
        $this->assertSame(['users'], $resumed->actualTableNames);
        $this->assertSame(['groups'], $resumed->remainingTableNames);
        $this->assertSame(2, $resumed->totalTableCount);
        $this->assertSame(['ALTER TABLE users ADD COLUMN c INT'], $resumed->pendingStatements);
        $this->assertSame(1, $resumed->failedCount);
    }

    /**
     * And the shims must not disturb this version's own state: the new
     * runner never touches them, so a progress row it writes and reads
     * back must be identical whether or not they carry values.
     */
    public function testTheProgressShimsAreInertForTheCurrentRunner(): void
    {
        $progress = MigrationProgress::start('target');
        $progress->totalStatementCount = 9;
        $progress->remainingStatementCount = 4;
        $progress->executedStatements = ['ALTER TABLE users ADD COLUMN c INT'];
        $progress->failureSignature = 'abc123';
        $progress->sameFailureCount = 2;

        $polluted = clone $progress;
        $polluted->pendingStatements = ['SOMETHING THE OLD RUNNER LEFT'];
        $polluted->failedCount = 7;
        $polluted->tableQueueBuilt = true;

        $clean = MigrationProgress::fromArray($progress->toArray());
        $fromPolluted = MigrationProgress::fromArray($polluted->toArray());

        $this->assertNotNull($clean);
        $this->assertNotNull($fromPolluted);
        $this->assertSame($clean->totalStatementCount, $fromPolluted->totalStatementCount);
        $this->assertSame($clean->remainingStatementCount, $fromPolluted->remainingStatementCount);
        $this->assertSame($clean->executedStatements, $fromPolluted->executedStatements);
        $this->assertSame($clean->failureSignature, $fromPolluted->failureSignature);
        $this->assertSame($clean->sameFailureCount, $fromPolluted->sameFailureCount);
        $this->assertSame([], $clean->pendingStatements, 'the shim must default to empty');
        $this->assertSame(0, $clean->failedCount);
    }
}
