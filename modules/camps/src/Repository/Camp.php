<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/**
 * One stay at one place (schema.sql: camp_camps).
 *
 * `bookedByName` arrives already decrypted — Repository\CampRepository is
 * the only layer that sees the ciphertext.
 */
class Camp
{
    public const STAY_GRAND_CAMP = 'grand_camp';
    public const STAY_WEEKEND = 'weekend';
    public const STAY_OTHER = 'other';

    public const STATUS_TO_CONFIRM = 'to_confirm';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @param int[] $sectionIds
     */
    public function __construct(
        public readonly int $id,
        public readonly int $placeId,
        public readonly string $stayType,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly ?int $yearOnly,
        public readonly string $status,
        public readonly ?int $priceCents,
        public readonly ?int $participantCount,
        public readonly ?int $bookedByMemberId,
        public readonly ?string $bookedByName,
        public readonly array $sectionIds,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {
    }

    /**
     * THE definition of "à venir", and the only one — Repository\
     * CampRepository::UPCOMING_SQL says the same thing to the database and
     * is pinned against this method by a test, because two definitions of
     * this would put a camp in the "À venir" list and in the "passés" list
     * of its own place on the same afternoon.
     *
     * A cancelled camp is never upcoming, whatever its dates. A year-only
     * camp stays upcoming for the whole of its year and becomes past on 1
     * January of the next one: "on y va en 2029" is a plan until 2029 is
     * over, and nobody is going to move it by hand.
     */
    public function isUpcoming(\DateTimeImmutable $today): bool
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return false;
        }
        if ($this->endDate !== null) {
            return $this->endDate >= $today->format('Y-m-d');
        }
        if ($this->yearOnly !== null) {
            return $this->yearOnly >= (int) $today->format('Y');
        }

        // Neither dates nor a year: forbidden by Service\CampService, and
        // if one ever exists it is not a plan anybody can act on.
        return false;
    }

    /**
     * The year a stay belongs to, for sorting and for the "avis de 2024"
     * caption. Null only for the malformed row above.
     */
    public function year(): ?int
    {
        if ($this->endDate !== null) {
            return (int) substr($this->endDate, 0, 4);
        }

        return $this->yearOnly;
    }

    /**
     * What the lists sort on, newest first. A year-only camp sorts as if
     * it ended on 31 December, so it lands after every dated camp of the
     * same year rather than before all of them.
     */
    public function sortKey(): string
    {
        if ($this->endDate !== null) {
            return $this->endDate;
        }

        return $this->yearOnly !== null ? $this->yearOnly . '-12-31' : '0000-00-00';
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
