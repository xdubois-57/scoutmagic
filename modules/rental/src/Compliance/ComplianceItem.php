<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Compliance;

/**
 * One entry in an asset's paperwork register (§6.33).
 *
 * **Deliberately says nothing about compliance.** There is no `isValid()`
 * and no status enum, because the module knows no regulation: what counts
 * as a valid fire certificate differs by commune, by federation and by
 * year, and a green tick computed from a date would be a legal opinion this
 * software has no business giving. What it can honestly answer is "has this
 * date passed, and is it close" — which is what the two methods below do,
 * and all they do.
 */
class ComplianceItem
{
    public function __construct(
        public readonly int $id,
        public readonly int $assetId,
        public readonly string $label,
        public readonly ?int $fileId,
        public readonly ?string $expiresOn,
        public readonly ?string $remark,
        public readonly ?string $remindedOn,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt
    ) {
    }

    /**
     * Whether the date on the entry is in the past. Not "whether the asset
     * is non-compliant" — the unit may have renewed the paper and not typed
     * it in yet, and this class has no way to know.
     */
    public function hasExpired(\DateTimeImmutable $today): bool
    {
        return $this->expiresOn !== null
            && new \DateTimeImmutable($this->expiresOn) < $today->setTime(0, 0);
    }

    /**
     * Whether the date falls inside the next $days days.
     */
    public function expiresWithin(int $days, \DateTimeImmutable $today): bool
    {
        if ($this->expiresOn === null || $this->hasExpired($today)) {
            return false;
        }

        return new \DateTimeImmutable($this->expiresOn) <= $today->setTime(0, 0)->modify('+' . $days . ' days');
    }

    /**
     * Days until the date, negative once it has passed. Null when the entry
     * carries no date at all — plenty of paperwork simply does not expire,
     * and forcing a number there would invent one.
     */
    public function daysUntilExpiry(\DateTimeImmutable $today): ?int
    {
        if ($this->expiresOn === null) {
            return null;
        }

        return (int) $today->setTime(0, 0)->diff(new \DateTimeImmutable($this->expiresOn))->format('%r%a');
    }
}
