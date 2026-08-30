<?php

declare(strict_types=1);

namespace Tests\Core\Database;

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
}
