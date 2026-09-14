<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

/**
 * One location's safety copy on another — a row of `storage_protections`.
 *
 * **Why this exists at all.** Since D10 no backup archive carries a
 * declared storage location, so every declared location is unprotected and
 * the remedy has to live at the same level as the lack (D11): the copy of
 * a location is another location. Nothing smaller would do — a bigger zip
 * was exactly what D10 removed, and for the reason it removed it.
 *
 * **What this object is NOT is the state of the copy.** It carries the
 * relation and the working state of the current pass, and every field in
 * the second group is disposable (D12): losing them makes the next pass
 * inventory the source again, never decide anything differently. What a
 * pass decides on is the inventory FILE, which lives in the destination
 * beside the files it describes — which is what makes the mechanism
 * insensitive to a database restored to last month, and what lets a copy
 * found on a disk in three years describe itself.
 */
final class StorageProtection
{
    public const PHASE_INVENTORY = 'inventory';
    public const PHASE_RECONCILE = 'reconcile';

    public function __construct(
        public readonly int $id,
        public readonly int $sourceLocationId,
        public readonly int $destinationLocationId,
        public readonly bool $enabled,
        public readonly int $gracePeriodDays,
        public readonly int $cadenceHours,
        public readonly ?string $passPhase = null,
        public readonly ?string $passStartedAt = null,
        public readonly ?string $passCursor = null,
        public readonly int $passSeenCount = 0,
        public readonly ?string $lastCompletedPassAt = null,
        public readonly ?string $lastError = null,
        public readonly ?string $createdAt = null
    ) {
    }

    /**
     * Whether a pass is half-finished — which is a different question from
     * « is it due », and the two were confused once in a sibling feature.
     */
    public function isPassInProgress(): bool
    {
        return $this->passPhase !== null;
    }

    /**
     * Whether the nightly pass should run now.
     *
     * **A pass already in progress is always due**, whatever the cadence:
     * the cadence spaces out the START of the work, and a run that stopped
     * on its time budget with keys left must be picked up on the next tick
     * rather than tomorrow night. That is the shape
     * {@see \Core\Notification\Task\SendNotificationsHandler} uses, and the
     * reason it reschedules with a delay of zero.
     */
    public function isDue(\DateTimeImmutable $now): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($this->isPassInProgress()) {
            return true;
        }
        if ($this->lastCompletedPassAt === null) {
            return true;
        }

        $last = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->lastCompletedPassAt);
        if ($last === false) {
            // An unreadable timestamp is a reason to run, never a reason to
            // stop: the cost of one extra pass is a listing, and the cost
            // of skipping for ever is the copy going stale in silence.
            return true;
        }

        return $last->modify('+' . $this->cadenceHours . ' hours') <= $now;
    }
}
