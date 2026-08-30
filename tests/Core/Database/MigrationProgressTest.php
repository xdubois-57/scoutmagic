<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\MigrationProgress;
use PHPUnit\Framework\TestCase;

class MigrationProgressTest extends TestCase
{
    public function testStartCreatesFreshProgressForATargetHash(): void
    {
        $progress = MigrationProgress::start('abc123');

        $this->assertSame('abc123', $progress->targetHash);
        $this->assertFalse($progress->tableQueueBuilt);
        $this->assertSame([], $progress->remainingTableNames);
        $this->assertSame(0, $progress->totalTableCount);
        $this->assertSame(0, $progress->totalStatementCount);
        $this->assertFalse($progress->dropsApplied);
    }

    public function testToArrayThenFromArrayRoundTripsEveryField(): void
    {
        $progress = MigrationProgress::start('abc123');
        $progress->tableQueueBuilt = true;
        $progress->actualTableNames = ['members', 'scout_years'];
        $progress->remainingTableNames = ['scout_years'];
        $progress->totalTableCount = 2;
        $progress->pendingStatements = ['ALTER TABLE members ADD COLUMN foo INT'];
        $progress->totalStatementCount = 1;
        $progress->executedStatements = ['CREATE TABLE members (id INT)'];
        $progress->warnings = ['some warning'];
        $progress->failedCount = 1;
        $progress->dropsApplied = false;

        $restored = MigrationProgress::fromArray($progress->toArray());

        $this->assertNotNull($restored);
        $this->assertSame($progress->targetHash, $restored->targetHash);
        $this->assertSame($progress->tableQueueBuilt, $restored->tableQueueBuilt);
        $this->assertSame($progress->actualTableNames, $restored->actualTableNames);
        $this->assertSame($progress->remainingTableNames, $restored->remainingTableNames);
        $this->assertSame($progress->totalTableCount, $restored->totalTableCount);
        $this->assertSame($progress->pendingStatements, $restored->pendingStatements);
        $this->assertSame($progress->totalStatementCount, $restored->totalStatementCount);
        $this->assertSame($progress->executedStatements, $restored->executedStatements);
        $this->assertSame($progress->warnings, $restored->warnings);
        $this->assertSame($progress->failedCount, $restored->failedCount);
        $this->assertSame($progress->dropsApplied, $restored->dropsApplied);
    }

    public function testFromArrayReturnsNullWhenTargetHashIsMissing(): void
    {
        $this->assertNull(MigrationProgress::fromArray(['table_queue_built' => true]));
    }

    public function testFromArrayReturnsNullWhenTargetHashIsNotAString(): void
    {
        $this->assertNull(MigrationProgress::fromArray(['target_hash' => 123]));
    }

    public function testFromArrayFillsInDefaultsForMissingFields(): void
    {
        $restored = MigrationProgress::fromArray(['target_hash' => 'abc123']);

        $this->assertNotNull($restored);
        $this->assertSame('abc123', $restored->targetHash);
        $this->assertFalse($restored->tableQueueBuilt);
        $this->assertSame([], $restored->remainingTableNames);
        $this->assertSame(0, $restored->totalTableCount);
        $this->assertSame(0, $restored->failedCount);
    }

    /**
     * A site can be mid-migration when the update that removed the backup
     * phase lands: its checkpointed row still carries `backup_done` and
     * `backup_created`. Those keys are simply not read any more — the
     * attempt must resume from the rest of the row, never be discarded (it
     * would restart the migration) and never fatal on the unknown keys.
     */
    public function testFromArrayResumesAProgressRowWrittenBeforeTheBackupPhaseWasRemoved(): void
    {
        $restored = MigrationProgress::fromArray([
            'target_hash' => 'abc123',
            'backup_done' => true,
            'backup_created' => true,
            'table_queue_built' => true,
            'actual_table_names' => ['members'],
            'remaining_table_names' => ['scout_years'],
            'total_table_count' => 2,
        ]);

        $this->assertNotNull($restored);
        $this->assertSame('abc123', $restored->targetHash);
        $this->assertTrue($restored->tableQueueBuilt);
        $this->assertSame(['members'], $restored->actualTableNames);
        $this->assertSame(['scout_years'], $restored->remainingTableNames);
        $this->assertSame(2, $restored->totalTableCount);
    }

    public function testFromArrayIgnoresNonArrayValuesForArrayFields(): void
    {
        $restored = MigrationProgress::fromArray([
            'target_hash' => 'abc123',
            'remaining_table_names' => 'not-an-array',
            'pending_statements' => 42,
        ]);

        $this->assertNotNull($restored);
        $this->assertSame([], $restored->remainingTableNames);
        $this->assertSame([], $restored->pendingStatements);
    }
}
