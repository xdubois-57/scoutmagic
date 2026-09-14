<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

/**
 * Where one run of a protection got to, and what it did.
 *
 * The caller needs three things from it: whether to come straight back
 * (`finished` is false), what to write into the disposable working state
 * so the next run resumes, and what to say in the journal.
 */
final class ProtectionPassResult
{
    /**
     * @param array<string, string> $failures key => French reason
     */
    private function __construct(
        public readonly string $phase,
        public readonly bool $finished,
        /**
         * The moment this run stamped its inventory entries with.
         *
         * **Carried back out because the caller has to persist THIS
         * string, not one of its own.** A paused run wrote `lastSeenAt`
         * into every entry it met; the next run reads `pass_started_at`
         * back and compares its own stamp to those for exact equality
         * ({@see InventoryEntry::seenBy()}). A persist site that computed
         * its own « now » — twenty seconds later, which is the whole
         * budget — stored a value no entry carries, and run 2 then
         * concluded that everything run 1 had just seen was gone from the
         * source. On a big enough source that is either a deletion
         * countdown started on present files or the D15 circuit breaker
         * tripping every single night.
         */
        public readonly string $passStartedAt,
        /** The backend's own next-page token, opaque by contract. */
        public readonly ?string $cursor = null,
        /** The last key of that page this run finished with. */
        public readonly ?string $pageLastKey = null,
        public readonly int $seenCount = 0,
        public readonly int $copiedCount = 0,
        public readonly array $failures = [],
        public readonly int $markedAbsentCount = 0,
        public readonly int $deletedCount = 0,
        /**
         * Whether the sweep refused to mark the disappearances it found
         * because there were too many of them at once (D15).
         *
         * Not a failure of the run: everything that could be copied was.
         * It is a refusal to act on one conclusion, and the thing an
         * administrator has to be told about.
         */
        public readonly bool $refusedMassDisappearance = false
    ) {
    }

    /**
     * @param array<string, string> $failures
     */
    public static function paused(
        string $phase,
        string $passStartedAt,
        ?string $cursor,
        ?string $pageLastKey,
        int $seenCount,
        int $copiedCount,
        array $failures
    ): self {
        return new self($phase, false, $passStartedAt, $cursor, $pageLastKey, $seenCount, $copiedCount, $failures);
    }

    /**
     * @param array<string, string> $failures
     */
    public static function finished(
        string $phase,
        string $passStartedAt,
        int $seenCount,
        int $copiedCount,
        array $failures
    ): self {
        return new self($phase, true, $passStartedAt, null, null, $seenCount, $copiedCount, $failures);
    }

    public static function finishedSweep(
        string $passStartedAt,
        int $markedAbsent,
        int $deleted,
        bool $refusedMassDisappearance
    ): self {
        return new self(
            StorageProtection::PHASE_RECONCILE,
            true,
            $passStartedAt,
            null,
            null,
            0,
            0,
            [],
            $markedAbsent,
            $deleted,
            $refusedMassDisappearance
        );
    }

    /**
     * A sweep that ran out of time part-way through its deletions.
     *
     * **It carries no cursor, and that is not an omission.** What phase 2
     * has to remember is which entries it decided about, and those
     * decisions are written into the inventory itself as
     * `absentFromSourceSince`. A run that resumes in this phase re-reads
     * the document, finds the entries still marked, and carries on
     * deleting — so the only thing the row has to hold is the phase.
     */
    public static function pausedSweep(
        string $passStartedAt,
        int $markedAbsent,
        int $deleted,
        bool $refusedMassDisappearance
    ): self {
        return new self(
            StorageProtection::PHASE_RECONCILE,
            false,
            $passStartedAt,
            null,
            null,
            0,
            0,
            [],
            $markedAbsent,
            $deleted,
            $refusedMassDisappearance
        );
    }

    /**
     * The sweep's own result, carrying forward what the listing phase that
     * preceded it did — so one run that did both reports both.
     */
    public function withCopyCounts(self $inventoryPhase): self
    {
        return new self(
            $this->phase,
            $this->finished,
            $this->passStartedAt,
            $this->cursor,
            $this->pageLastKey,
            $inventoryPhase->seenCount,
            $inventoryPhase->copiedCount,
            $inventoryPhase->failures,
            $this->markedAbsentCount,
            $this->deletedCount,
            $this->refusedMassDisappearance
        );
    }
}
