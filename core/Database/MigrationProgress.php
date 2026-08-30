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
     */
    public function __construct(
        public readonly string $targetHash,
        public int $totalStatementCount = 0,
        public int $remainingStatementCount = 0,
        public array $executedStatements = [],
        public array $warnings = [],
        public string $failureSignature = '',
        public int $sameFailureCount = 0,
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
            'total_statement_count' => $this->totalStatementCount,
            'remaining_statement_count' => $this->remainingStatementCount,
            'executed_statements' => $this->executedStatements,
            'warnings' => $this->warnings,
            'failure_signature' => $this->failureSignature,
            'same_failure_count' => $this->sameFailureCount,
            'drops_applied' => $this->dropsApplied,
        ];
    }

    /**
     * Null on malformed/incomplete JSON — the caller treats that the same
     * as "no progress yet" and starts a fresh attempt, never a fatal error
     * over a corrupted cache row.
     *
     * Every field is read by name and anything else in $data is ignored,
     * which is what lets a row checkpointed by an older version — one
     * still carrying `pending_statements`, `remaining_table_names` or the
     * long-gone `backup_done` — resume on this code instead of being
     * thrown away mid-migration. Dropping those keys costs nothing now
     * that the statements are re-derived from the database anyway.
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
            dropsApplied: (bool) ($data['drops_applied'] ?? false)
        );
    }
}
