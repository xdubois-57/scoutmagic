<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * A damage or an incident during a stay (§6.23).
 *
 * `$description` arrives decrypted — the repository is the only place it is
 * ever a ciphertext, like every other field written about a renter's group.
 */
final class Incident
{
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly string $description,
        public readonly ?int $proposedAmountCents,
        public readonly IncidentDecision $decision,
        public readonly ?int $decidedAmountCents,
        public readonly ?\DateTimeImmutable $decidedAt,
        public readonly ?int $decidedByMemberId,
        public readonly ?int $fileId,
        public readonly ?int $createdByMemberId,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }

    /**
     * The amount that actually applies: what was decided, falling back to
     * what was proposed.
     *
     * The fallback matters because a manager who picks "charge" without
     * retyping the figure means "the amount I proposed" — making them type
     * it twice is how the two end up different.
     */
    public function effectiveAmountCents(): int
    {
        return $this->decidedAmountCents ?? $this->proposedAmountCents ?? 0;
    }

    public function isDecided(): bool
    {
        return $this->decision !== IncidentDecision::PENDING;
    }
}
