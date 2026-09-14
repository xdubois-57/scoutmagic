<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

use Core\Service\DateInput;
use Core\Storage\Location\Backend\StorageBackendInterface;

/**
 * One run of one protection: list the source, copy what is missing, then
 * — and only then — reconcile what the source no longer has.
 *
 * **Two phases, both resumable, under one time budget.** The shape is
 * {@see \Core\Notification\Task\SendNotificationsHandler}'s and is taken
 * rather than reinvented: work until the budget runs out, write down where
 * you got to, and let the scheduler come straight back. A gallery of a
 * hundred thousand media is not listed in twenty seconds, and a pass that
 * had to finish in one run would never finish at all.
 *
 * **Phase 1 — the source inventory.** Page by page, cursor in the
 * database, and every key it meets is stamped as seen in the DESTINATION's
 * inventory. The stamp is what makes phase 2 possible without holding a
 * hundred thousand keys of working state anywhere: once the listing has
 * finished, whatever still carries an older stamp is what the source no
 * longer has.
 *
 * **Phase 2 — reconciliation, and it never runs on a listing that did not
 * finish** (D15). That is the catastrophic failure mode this guard exists
 * for: credentials expire, the source answers « empty », every file is
 * marked as gone, and the grace period then erases the entire copy. So the
 * sweep is reached only by completing phase 1, and a phase 1 that threw is
 * a phase 1 that did not complete.
 */
class ProtectionPass
{
    /**
     * The share of a copy that may go missing in a single pass before the
     * pass refuses to believe it.
     *
     * **A quarter, and with a floor of twenty entries under it.** Both
     * bounds matter: the proportion catches the shape of a real accident —
     * credentials that half-expired, a bucket answering a truncated
     * listing — while the floor stops a unit with six photographs from
     * being told it has lost everything for deleting two of them.
     *
     * **The failure mode of this guard is a copy that keeps too much**,
     * which is the direction to fail in. A pass that stops marking leaves
     * files at the destination that the source has genuinely lost; a pass
     * that marks wrongly deletes files that still exist.
     */
    public const MASS_DISAPPEARANCE_SHARE = 0.25;
    public const MASS_DISAPPEARANCE_FLOOR = 20;

    /**
     * How a moment is written into the inventory.
     *
     * The same shape the `pass_started_at` column holds, so that a value
     * written by one run and read back by the next round-trips to the
     * identical string — which {@see InventoryEntry::seenBy()} compares
     * for exact equality.
     */
    public const STAMP_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly StorageInventoryStore $inventories,
        private readonly ProtectedCopier $copier,
        private readonly ?\Closure $now = null
    ) {
    }

    /**
     * Works this protection for as long as $hasTimeLeft allows.
     *
     * @param callable():bool $hasTimeLeft
     */
    public function run(
        StorageProtection $protection,
        StorageBackendInterface $source,
        StorageBackendInterface $destination,
        string $sourceLabel,
        callable $hasTimeLeft
    ): ProtectionPassResult {
        $now = $this->now();
        // `Y-m-d H:i:s`, which is what the `pass_started_at` column holds:
        // a resumed run reads that column back and must produce the
        // identical string, since an entry's stamp is compared to it for
        // exact equality.
        $passStartedAt = $protection->passStartedAt !== null
            ? (DateInput::fromStorage($protection->passStartedAt)?->format(self::STAMP_FORMAT)
                ?? $now->format(self::STAMP_FORMAT))
            : $now->format(self::STAMP_FORMAT);

        $inventory = $this->inventories->load(
            $destination,
            $protection->sourceLocationId,
            $sourceLabel,
            $protection->destinationLocationId
        );
        $fingerprint = $inventory->fingerprint();

        $result = $protection->passPhase === StorageProtection::PHASE_RECONCILE
            ? $this->reconcile($protection, $destination, $inventory, $passStartedAt, $now, $hasTimeLeft)
            : $this->inventorySource($protection, $source, $destination, $inventory, $passStartedAt, $hasTimeLeft);

        // **Written before the result is acted on**, because everything
        // the run learned — what it copied, what it stamped, what it
        // marked — is in this document and nowhere else. A run that
        // copied for twenty seconds and did not write it would copy the
        // same files again tomorrow.
        $this->inventories->save($destination, $inventory, $fingerprint);

        // The reconciliation is reached by COMPLETING the listing, never
        // by asking whether it looked complete (D15).
        if ($result->phase === StorageProtection::PHASE_RECONCILE && !$result->finished) {
            return $result;
        }

        if ($result->finished && $result->phase === StorageProtection::PHASE_INVENTORY) {
            $sweep = $this->reconcile($protection, $destination, $inventory, $passStartedAt, $now, $hasTimeLeft);
            $this->inventories->save($destination, $inventory, $fingerprint);

            return $sweep->withCopyCounts($result);
        }

        return $result;
    }

    /**
     * Phase 1: walk the source, copying what the destination is missing.
     */
    private function inventorySource(
        StorageProtection $protection,
        StorageBackendInterface $source,
        StorageBackendInterface $destination,
        StorageInventory $inventory,
        string $passStartedAt,
        callable $hasTimeLeft
    ): ProtectionPassResult {
        $cursor = $protection->passCursor;
        $seen = $protection->passSeenCount;
        $copied = 0;
        $failures = [];

        while (true) {
            $listing = $source->list('', $cursor);

            foreach ($listing->objects as $object) {
                // **The budget is asked once per object, and that is
                // enough.** A key that is already up to date costs no I/O
                // beyond the listing that produced it, so a page of them
                // passes in microseconds; and a key that needs copying
                // hands the same budget to the copier, which pauses
                // between slices rather than at the end of a film.
                if (!$hasTimeLeft()) {
                    return ProtectionPassResult::paused(
                        StorageProtection::PHASE_INVENTORY,
                        $passStartedAt,
                        $cursor,
                        $seen,
                        $copied,
                        $failures
                    );
                }

                // The subsystem's own bookkeeping is not content, wherever
                // it is met. A source that is also somebody's destination
                // carries an inventory of its own, and copying it onward
                // would describe the wrong pair of locations.
                if (StorageInventoryStore::isReservedKey($object->key)) {
                    $cursor = $object->key;
                    continue;
                }

                $existing = $inventory->get($object->key);
                $upToDate = $existing !== null
                    && $existing->isComplete()
                    && $existing->sizeBytes === $object->sizeBytes;

                if (!$upToDate) {
                    $outcome = $this->copier->copy(
                        $source,
                        $destination,
                        $object->key,
                        $object->sizeBytes,
                        'application/octet-stream',
                        $hasTimeLeft
                    );

                    if ($outcome->isPaused()) {
                        // **The half-copy is written down as a half-copy**,
                        // which is what an entry with no copy date is for
                        // ({@see InventoryEntry::isComplete()}). It is
                        // never counted as protected, and the next run
                        // re-copies it — but it is now something the
                        // inventory knows about, so if the source loses
                        // this key while the copy is interrupted, the
                        // sweep meets the entry, runs its grace period,
                        // and the delete reclaims the partial object.
                        // Recorded as seen by THIS pass, so a copy
                        // spanning several nights is never mistaken for a
                        // file the source has dropped.
                        $inventory->put($object->key, new InventoryEntry(
                            sizeBytes: $object->sizeBytes,
                            lastSeenAt: $passStartedAt
                        ));

                        // **The cursor is deliberately NOT advanced.** The
                        // next run re-lists from the previous key, meets
                        // this one again, and resumes from where the
                        // partial object ends.
                        return ProtectionPassResult::paused(
                            StorageProtection::PHASE_INVENTORY,
                            $passStartedAt,
                            $cursor,
                            $seen,
                            $copied,
                            $failures
                        );
                    }

                    if ($outcome->isCompleted()) {
                        $entry = new InventoryEntry(
                            sizeBytes: $object->sizeBytes,
                            md5: $outcome->md5,
                            copiedAt: $passStartedAt,
                            absentFromSourceSince: null,
                            resumeSession: null,
                            lastSeenAt: $passStartedAt
                        );
                        $inventory->put($object->key, $entry);
                        $copied++;
                    } else {
                        // Refused or failed: noted, and the pass moves on
                        // (D14). What must NOT happen is an entry
                        // recording a file the destination does not hold.
                        $failures[$object->key] = $outcome->reason ?? 'Échec sans motif.';
                        $inventory->remove($object->key);
                    }
                } else {
                    $inventory->put($object->key, $existing->seenAt($passStartedAt));
                }

                $seen++;
                $cursor = $object->key;
            }

            if ($listing->cursor === null) {
                return ProtectionPassResult::finished(
                    StorageProtection::PHASE_INVENTORY,
                    $passStartedAt,
                    $seen,
                    $copied,
                    $failures
                );
            }
            $cursor = $listing->cursor;

            // **And once per page, before asking for the next one.** The
            // per-object check above never fires on a page that holds
            // nothing this pass has to look at — every key reserved, or
            // simply empty — so a source paginating through many such
            // pages would run past the budget without once being asked.
            if (!$hasTimeLeft()) {
                return ProtectionPassResult::paused(
                    StorageProtection::PHASE_INVENTORY,
                    $passStartedAt,
                    $cursor,
                    $seen,
                    $copied,
                    $failures
                );
            }
        }
    }

    /**
     * Phase 2: what the source no longer has.
     *
     * Three moves and they are deliberately separate. Marking is
     * reversible and costs nothing, so it happens freely — unless the
     * guard below refuses to believe the scale of it. Deleting is
     * irreversible, so it happens only to entries a PREVIOUS pass marked
     * and whose grace period has since run out.
     */
    private function reconcile(
        StorageProtection $protection,
        StorageBackendInterface $destination,
        StorageInventory $inventory,
        string $passStartedAt,
        \DateTimeImmutable $now,
        callable $hasTimeLeft
    ): ProtectionPassResult {
        // **The stamp comes from the caller, never recomputed here.** It
        // has to be the SAME string phase 1 wrote, and phase 1 falls back
        // to « now » when the protection row carries no start — which is
        // every first run. Reading the row again in this method answered
        // an empty string on exactly those runs, so the comparison below
        // matched nothing and a first pass concluded that no file had
        // ever disappeared. Silent, and the kind of silence that only
        // shows up as « the copy never lets go of anything ».
        $missing = [];
        $complete = 0;
        foreach ($inventory->entries() as $key => $entry) {
            if ($entry->isComplete()) {
                $complete++;
            }
            if (!$entry->seenBy($passStartedAt) && $entry->absentFromSourceSince === null) {
                $missing[] = $key;
            }
        }

        $refusedAsTooMany = $complete >= self::MASS_DISAPPEARANCE_FLOOR
            && count($missing) > (int) ceil($complete * self::MASS_DISAPPEARANCE_SHARE);

        if (!$refusedAsTooMany) {
            $stamp = $now->format(self::STAMP_FORMAT);
            foreach ($missing as $key) {
                $entry = $inventory->get($key);
                if ($entry !== null) {
                    $inventory->put($key, $entry->withAbsentSince($stamp));
                }
            }
        }

        // **Marking is free; deleting is not, and only the second half
        // is budgeted.** The loop above is arithmetic over a document
        // already in memory. This one makes a request per key — on a
        // bucket, one round trip each — and a single night on which a
        // large batch of entries comes out of its grace period together
        // (an album deleted weeks ago, marked in one pass) would run past
        // whatever `max_execution_time` this host allows. Killed there,
        // the run loses the inventory it never got to save and re-does
        // every one of those deletions tomorrow.
        //
        // **No cursor is needed for it**, unlike the listing: the marks
        // are in the document and they persist. Stopping early leaves the
        // remaining entries marked and expired, and the next pass deletes
        // them. The sweep still finished the thing that has to be atomic —
        // deciding — and only the carrying-out is spread over nights.
        $deleted = 0;
        foreach ($inventory->entries() as $key => $entry) {
            if (!$entry->graceExpired($protection->gracePeriodDays, $now)) {
                continue;
            }
            if (!$hasTimeLeft()) {
                break;
            }
            // `delete` on a key that is already gone is a success, not an
            // error — otherwise every pass that follows a restore fails on
            // ghosts. Both backends answer that way; this only has to not
            // undo it.
            $destination->delete($key);
            $inventory->remove($key);
            $deleted++;
        }

        return ProtectionPassResult::finishedSweep(
            $passStartedAt,
            count($missing),
            $deleted,
            $refusedAsTooMany
        );
    }

    private function now(): \DateTimeImmutable
    {
        $now = $this->now !== null ? ($this->now)() : new \DateTimeImmutable();

        return $now instanceof \DateTimeImmutable ? $now : new \DateTimeImmutable();
    }
}
