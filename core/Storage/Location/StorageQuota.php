<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

/**
 * How much room a storage location says it has.
 *
 * Bytes, like everything else that measures storage in this codebase
 * (`Core\Storage\DiskBudget`), so the screen can format the two the same
 * way and nobody has to remember which number is in what unit.
 *
 * **It used to be `Core\Maintenance\Remote\RemoteQuota`, and moving it is
 * the point of IT-05 rather than tidying.** The free space of a Drive
 * account was a fact about the off-site backup as long as the off-site
 * backup was the only thing that knew what a Drive was. Now a Drive is a
 * storage location like any other, the answer is
 * {@see Backend\QuotaReportingBackend::quota()}, and a backend reaching up
 * into `Core\Maintenance` for the type of its own return value would be
 * the layering inversion ARCHITECTURE.md §4 exists to prevent.
 */
final class StorageQuota
{
    public function __construct(
        public readonly int $usedBytes,
        public readonly int $limitBytes
    ) {
    }

    public function freeBytes(): int
    {
        return max(0, $this->limitBytes - $this->usedBytes);
    }

    /**
     * How full the account is, or null when there is nothing to be full
     * of.
     *
     * Null means *unknown*, never *empty*: an account that declares no
     * limit reports zero here, and rounding that into « 0 % occupé »
     * would put a reassuring number on a screen where no measurement
     * exists. Rounded and capped the same way
     * {@see \Core\Storage\StorageUsage::usedPercent()} does it, so the
     * local disk and the remote destination read alike.
     */
    public function usedPercent(): ?int
    {
        if ($this->limitBytes <= 0) {
            return null;
        }

        return (int) min(100, round($this->usedBytes / $this->limitBytes * 100));
    }
}
