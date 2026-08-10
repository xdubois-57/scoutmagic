<?php

declare(strict_types=1);

namespace Core\Database;

/**
 * Resumable state for one in-progress MigrationRunner::migrate() attempt
 * against one target schema hash — persisted (as JSON, via a raw `settings`
 * row, same mechanism as the schema hash cache) between invocations so a
 * migration that doesn't finish within its time budget picks up exactly
 * where it left off on the next call instead of starting over.
 *
 * Deliberately lean: it never stores full introspected TableDefinitions
 * (potentially large — every column/index/FK of every table in the
 * database), only table NAMES and SQL statement STRINGS. `settings.
 * setting_value` is a plain TEXT column; a full-schema dump for a
 * database with many tables could plausibly approach its size limit,
 * while the queues here (table names, a handful of DDL statements) stay
 * small regardless of how large the database itself is.
 */
final class MigrationProgress
{
    /**
     * @param bool $tableQueueBuilt Whether $actualTableNames/
     *   $remainingTableNames have been populated yet. Needed because an
     *   empty $remainingTableNames is otherwise ambiguous: it means either
     *   "queue not built yet" or "every table has been diffed" — the two
     *   are reached at opposite ends of the diff phase and must be told
     *   apart.
     * @param array<string> $actualTableNames Every table that existed in
     *   the database when this attempt started (snapshotted once) — used
     *   to decide, for each remaining table name, whether it needs
     *   introspecting (existing table) or not (brand new table, nothing
     *   to introspect).
     * @param array<string> $remainingTableNames Queue of declared table
     *   names not yet diffed against the database.
     * @param int $totalTableCount Snapshot of count($remainingTableNames)
     *   taken once, the moment the queue is built — a stable denominator
     *   for progress reporting (Core\Http\Controller's migration-step
     *   endpoint), since $remainingTableNames itself keeps shrinking.
     * @param array<string> $pendingStatements Queue of DDL statements not
     *   yet executed.
     * @param int $totalStatementCount Snapshot of count($pendingStatements)
     *   taken once, the moment the diff phase finishes queueing every
     *   statement — same stable-denominator rationale as $totalTableCount.
     * @param array<string> $executedStatements Statements executed so far
     *   in this attempt (accumulated for the final MigrationResult).
     * @param array<string> $warnings Accumulated so far.
     */
    public function __construct(
        public readonly string $targetHash,
        public bool $backupDone = false,
        public bool $backupCreated = false,
        public bool $tableQueueBuilt = false,
        public array $actualTableNames = [],
        public array $remainingTableNames = [],
        public int $totalTableCount = 0,
        public array $pendingStatements = [],
        public int $totalStatementCount = 0,
        public array $executedStatements = [],
        public array $warnings = [],
        public int $failedCount = 0,
        public bool $dropsApplied = false
    ) {
    }

    public static function start(string $targetHash): self
    {
        return new self($targetHash);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target_hash' => $this->targetHash,
            'backup_done' => $this->backupDone,
            'backup_created' => $this->backupCreated,
            'table_queue_built' => $this->tableQueueBuilt,
            'actual_table_names' => $this->actualTableNames,
            'remaining_table_names' => $this->remainingTableNames,
            'total_table_count' => $this->totalTableCount,
            'pending_statements' => $this->pendingStatements,
            'total_statement_count' => $this->totalStatementCount,
            'executed_statements' => $this->executedStatements,
            'warnings' => $this->warnings,
            'failed_count' => $this->failedCount,
            'drops_applied' => $this->dropsApplied,
        ];
    }

    /**
     * Null on malformed/incomplete JSON — the caller treats that the same
     * as "no progress yet" and starts a fresh attempt, never a fatal error
     * over a corrupted cache row.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (!isset($data['target_hash']) || !is_string($data['target_hash'])) {
            return null;
        }

        return new self(
            targetHash: $data['target_hash'],
            backupDone: (bool) ($data['backup_done'] ?? false),
            backupCreated: (bool) ($data['backup_created'] ?? false),
            tableQueueBuilt: (bool) ($data['table_queue_built'] ?? false),
            actualTableNames: is_array($data['actual_table_names'] ?? null) ? $data['actual_table_names'] : [],
            remainingTableNames: is_array($data['remaining_table_names'] ?? null) ? $data['remaining_table_names'] : [],
            totalTableCount: (int) ($data['total_table_count'] ?? 0),
            pendingStatements: is_array($data['pending_statements'] ?? null) ? $data['pending_statements'] : [],
            totalStatementCount: (int) ($data['total_statement_count'] ?? 0),
            executedStatements: is_array($data['executed_statements'] ?? null) ? $data['executed_statements'] : [],
            warnings: is_array($data['warnings'] ?? null) ? $data['warnings'] : [],
            failedCount: (int) ($data['failed_count'] ?? 0),
            dropsApplied: (bool) ($data['drops_applied'] ?? false)
        );
    }
}
