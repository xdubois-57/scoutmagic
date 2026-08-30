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
        $this->assertSame(0, $progress->totalStatementCount);
        $this->assertSame(0, $progress->remainingStatementCount);
        $this->assertSame('', $progress->failureSignature);
        $this->assertSame(0, $progress->sameFailureCount);
        $this->assertFalse($progress->dropsApplied);
    }

    public function testToArrayThenFromArrayRoundTripsEveryField(): void
    {
        $progress = MigrationProgress::start('abc123');
        $progress->totalStatementCount = 7;
        $progress->remainingStatementCount = 3;
        $progress->executedStatements = ['CREATE TABLE members (id INT)'];
        $progress->warnings = ['some warning'];
        $progress->failureSignature = 'deadbeef';
        $progress->sameFailureCount = 2;
        $progress->dropsApplied = true;

        $restored = MigrationProgress::fromArray($progress->toArray());

        $this->assertNotNull($restored);
        $this->assertSame($progress->targetHash, $restored->targetHash);
        $this->assertSame($progress->totalStatementCount, $restored->totalStatementCount);
        $this->assertSame($progress->remainingStatementCount, $restored->remainingStatementCount);
        $this->assertSame($progress->executedStatements, $restored->executedStatements);
        $this->assertSame($progress->warnings, $restored->warnings);
        $this->assertSame($progress->failureSignature, $restored->failureSignature);
        $this->assertSame($progress->sameFailureCount, $restored->sameFailureCount);
        $this->assertSame($progress->dropsApplied, $restored->dropsApplied);
    }

    public function testFromArrayReturnsNullWhenTargetHashIsMissing(): void
    {
        $this->assertNull(MigrationProgress::fromArray(['same_failure_count' => 2]));
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
        $this->assertSame(0, $restored->totalStatementCount);
        $this->assertSame('', $restored->failureSignature);
        $this->assertSame(0, $restored->sameFailureCount);
        $this->assertFalse($restored->dropsApplied);
    }

    /**
     * A site can be mid-migration when the update that reshaped this state
     * lands: its checkpointed row still carries the queue fields that used
     * to be persisted (`pending_statements`, `remaining_table_names`,
     * `table_queue_built`) and, further back, `backup_done`. Those keys are
     * simply not read any more — the attempt must resume from the rest of
     * the row, never be discarded (which would restart the migration) and
     * never fatal on the unknown keys.
     *
     * Losing the queue costs nothing now: every pass re-derives the
     * statements from the live schema.
     */
    public function testFromArrayResumesAProgressRowWrittenByAnEarlierVersion(): void
    {
        $restored = MigrationProgress::fromArray([
            'target_hash' => 'abc123',
            'backup_done' => true,
            'backup_created' => true,
            'table_queue_built' => true,
            'actual_table_names' => ['members'],
            'remaining_table_names' => ['scout_years'],
            'total_table_count' => 2,
            'pending_statements' => ['ALTER TABLE members ADD COLUMN foo INT'],
            'total_statement_count' => 5,
            'executed_statements' => ['CREATE TABLE members (id INT)'],
            'failed_count' => 1,
        ]);

        $this->assertNotNull($restored);
        $this->assertSame('abc123', $restored->targetHash);
        $this->assertSame(5, $restored->totalStatementCount);
        $this->assertSame(['CREATE TABLE members (id INT)'], $restored->executedStatements);
        // The old failure count does not become the new convergence
        // counter: they mean different things, and inheriting it would
        // shorten the very first attempt's budget of retries.
        $this->assertSame(0, $restored->sameFailureCount);
    }

    public function testFromArrayIgnoresNonArrayValuesForArrayFields(): void
    {
        $restored = MigrationProgress::fromArray([
            'target_hash' => 'abc123',
            'executed_statements' => 'not-an-array',
            'warnings' => 42,
        ]);

        $this->assertNotNull($restored);
        $this->assertSame([], $restored->executedStatements);
        $this->assertSame([], $restored->warnings);
    }

    public function testFromArrayIgnoresANonStringFailureSignature(): void
    {
        $restored = MigrationProgress::fromArray([
            'target_hash' => 'abc123',
            'failure_signature' => ['not', 'a', 'string'],
        ]);

        $this->assertNotNull($restored);
        $this->assertSame('', $restored->failureSignature);
    }
}
