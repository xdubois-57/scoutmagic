<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Repository;

/**
 * Seats asked on one offer, for named people — accepted or refused WHOLE
 * (D4). Seats are counted in people: `passengerCount`, never one per
 * request.
 */
final class SeatRequest
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REFUSED = 'refused';
    /** A granted seat the driver took back — not the same as a refusal. */
    public const REVOKED = 'revoked';

    /**
     * @param list<string> $passengerNames
     */
    public function __construct(
        public readonly int $id,
        public readonly int $offerId,
        public readonly int $requesterAccountId,
        public readonly string $requesterName,
        public readonly array $passengerNames,
        public readonly int $passengerCount,
        public readonly string $phone,
        public readonly string $status,
        public readonly string $createdAt
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    /** Pending or accepted — a request that still holds or asks for a seat. */
    public function isActive(): bool
    {
        return $this->isPending() || $this->isAccepted();
    }
}
