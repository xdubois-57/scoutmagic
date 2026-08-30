<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

/**
 * Resumable state for one in-progress MigrationRunner::migrate() attempt
 * against one target schema hash — persisted (as JSON, via a raw `settings`
 * row, same mechanism as the schema hash cache) between invocations.
 *
 * **What it deliberately no longer holds: the queue of statements still to
 * run.** It used to, and that was a latent way to strand a site forever.
 * `array_shift()` removed a statement from the persisted queue *before*
 * executing it, so a process killed between the `ADD COLUMN` and its
 * checkpoint came back, replayed the statement, collected "Duplicate column
 * name", and — because any PDOException incremented the failure count, and
 * the schema hash was only cached when that count was zero — never
 * converged. The site stayed on the migration-progress page indefinitely.
 *
 * The fix is not a better queue, it is no queue. The real state of the
 * database IS the checkpoint: every pass re-diffs the live schema (three
 * INFORMATION_SCHEMA queries since the bulk read landed) and generates
 * exactly the statements still missing. A statement that already ran is
 * simply not generated again.
 *
 * What remains here is what cannot be re-derived from the database: which
 * attempt this is (the target hash), what has been done so far for
 * reporting, and how many passes in a row have failed the same way — the
 * convergence counter that stops an unfixable migration from looping.
 *
 * The old queue fields are still *declared* and still round-tripped, but
 * only as upgrade shims for the runner of the previous version, which is
 * the code that actually executes during the update that installs this
 * one. Nothing here reads them. See the constructor.
 */
final class MigrationProgress
{
    /**
     * @param int $totalStatementCount Snapshot of how many statements the
     *   first pass of this attempt had to run — a stable denominator for
     *   the progress bar, since each later pass legitimately generates
     *   fewer. Display only; never a source of truth.
     * @param int $remainingStatementCount How many the current pass had
     *   left when it last checkpointed, for the same progress bar.
     * @param array<string> $executedStatements Accumulated across the
     *   passes of one attempt, for the final MigrationResult.
     * @param array<string> $warnings Accumulated the same way.
     * @param string $failureSignature Fingerprint of the set of statements
     *   that failed on the last pass — empty when none did.
     * @param int $sameFailureCount How many consecutive passes have failed
     *   with exactly that signature. A migration that cannot converge is
     *   abandoned at a ceiling rather than retried forever, because a site
     *   stuck on the progress page is worse than a schema that is missing
     *   a column (Core\Database\MigrationRunner::CONVERGENCE_ATTEMPTS).
     * @param bool $dropsApplied Whether the explicit drops.sql pass ran.
     *
     * The six parameters after that one are **upgrade shims. Nothing in
     * this version reads them.** They are the state the PREVIOUS runner
     * kept, and they have to stay declared because of how a self-update
     * actually executes: `Task\InstallUpdateHandler` replaces the files on
     * disk and then runs the migration IN THE SAME PHP PROCESS.
     * `MigrationRunner` is already loaded, so the code that runs the
     * migration during an update is the OLD runner — but
     * `MigrationProgress`, which nothing constructs on an ordinary
     * request, is not loaded yet and is autoloaded from the NEW files.
     *
     * The old runner does `array_push($progress->pendingStatements, ...)`
     * and `array_shift($progress->remainingTableNames)`. Against a class
     * that no longer declared them those reads answer null, `array_push()`
     * raises a TypeError, the handler catches it and rolls the update
     * back — and every retry runs the same old code, so the site can never
     * reach the version that would fix it. That is exactly how
     * scoutmagic.be rolled back six times in a row over a removed
     * `MigrationResult` parameter; this class would have done it again for
     * the same reason.
     *
     * They are round-tripped through toArray()/fromArray() as well as
     * declared, so a migration the old runner checkpoints mid-way resumes
     * on the old runner losslessly. In particular $failedCount gates the
     * old runner's schema-hash caching: silently resetting it to 0 across
     * a checkpoint would let it cache a hash for a schema that did not
     * converge. The new runner ignores every one of these keys — it
     * re-derives the statements from the live schema — so a checkpoint
     * written by the old runner and picked up by the new one simply starts
     * a fresh pass, which is the safe direction.
     *
     * Removable once no installation predates the version that introduced
     * the queueless runner — a decision about the field, not about the
     * code. IT-07 (migrating from a separate process rather than the one
     * being updated) removes the whole hazard class and is the real fix.
     *
     * @param bool $tableQueueBuilt Upgrade shim, see above.
     * @param array<string> $actualTableNames Upgrade shim, see above.
     * @param array<string> $remainingTableNames Upgrade shim, see above.
     * @param int $totalTableCount Upgrade shim, see above.
     * @param array<string> $pendingStatements Upgrade shim, see above.
     * @param int $failedCount Upgrade shim, see above.
     */
    public function __construct(
        public readonly string $targetHash,
        public int $totalStatementCount = 0,
        public int $remainingStatementCount = 0,
        public array $executedStatements = [],
        public array $warnings = [],
        public string $failureSignature = '',
        public int $sameFailureCount = 0,
        public bool $dropsApplied = false,
        public bool $tableQueueBuilt = false,
        public array $actualTableNames = [],
        public array $remainingTableNames = [],
        public int $totalTableCount = 0,
        public array $pendingStatements = [],
        public int $failedCount = 0
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
            'total_statement_count' => $this->totalStatementCount,
            'remaining_statement_count' => $this->remainingStatementCount,
            'executed_statements' => $this->executedStatements,
            'warnings' => $this->warnings,
            'failure_signature' => $this->failureSignature,
            'same_failure_count' => $this->sameFailureCount,
            'drops_applied' => $this->dropsApplied,
            'table_queue_built' => $this->tableQueueBuilt,
            'actual_table_names' => $this->actualTableNames,
            'remaining_table_names' => $this->remainingTableNames,
            'total_table_count' => $this->totalTableCount,
            'pending_statements' => $this->pendingStatements,
            'failed_count' => $this->failedCount,
        ];
    }

    /**
     * Null on malformed/incomplete JSON — the caller treats that the same
     * as "no progress yet" and starts a fresh attempt, never a fatal error
     * over a corrupted cache row.
     *
     * Every field is read by name and anything else in $data is ignored,
     * which is what lets a row checkpointed by an older version — one
     * still carrying the long-gone `backup_done` — resume on this code
     * instead of being thrown away mid-migration.
     *
     * `pending_statements`, `remaining_table_names` and the rest of the
     * old queue are read back into their (unused) properties rather than
     * dropped, so the same row still round-trips for the OLD runner: it is
     * the one running while an update installs this version, and it does
     * depend on them.
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
            totalStatementCount: (int) ($data['total_statement_count'] ?? 0),
            remainingStatementCount: (int) ($data['remaining_statement_count'] ?? 0),
            executedStatements: is_array($data['executed_statements'] ?? null) ? $data['executed_statements'] : [],
            warnings: is_array($data['warnings'] ?? null) ? $data['warnings'] : [],
            failureSignature: is_string($data['failure_signature'] ?? null) ? $data['failure_signature'] : '',
            sameFailureCount: (int) ($data['same_failure_count'] ?? 0),
            dropsApplied: (bool) ($data['drops_applied'] ?? false),
            tableQueueBuilt: (bool) ($data['table_queue_built'] ?? false),
            actualTableNames: is_array($data['actual_table_names'] ?? null) ? $data['actual_table_names'] : [],
            remainingTableNames: is_array($data['remaining_table_names'] ?? null) ? $data['remaining_table_names'] : [],
            totalTableCount: (int) ($data['total_table_count'] ?? 0),
            pendingStatements: is_array($data['pending_statements'] ?? null) ? $data['pending_statements'] : [],
            failedCount: (int) ($data['failed_count'] ?? 0)
        );
    }
}
