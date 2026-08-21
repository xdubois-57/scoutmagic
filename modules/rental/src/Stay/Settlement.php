<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * A final settlement, as stored (§6.21).
 *
 * **A validated settlement is immutable.** Changing one afterwards produces
 * a new version beside it, exactly like a contract: v1 may already have
 * been sent, and "une modification après validation est historisée" is the
 * requirement, not a preference.
 */
final class Settlement
{
    /**
     * @param SettlementLine[] $lines
     */
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly int $version,
        public readonly ?int $finalPersons,
        public readonly array $lines,
        public readonly int $totalCents,
        public readonly int $alreadyPaidCents,
        /** Negative when the renter has overpaid — never floored at zero. */
        public readonly int $balanceCents,
        public readonly ?int $securityDepositWithheldCents,
        public readonly ?int $securityDepositReturnCents,
        public readonly bool $isValidated,
        public readonly ?\DateTimeImmutable $validatedAt,
        public readonly ?int $validatedByMemberId,
        public readonly ?int $createdByMemberId,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }

    /** Whether the renter has paid more than the settlement comes to. */
    public function isOverpaid(): bool
    {
        return $this->balanceCents < 0;
    }

    public function overpaidCents(): int
    {
        return max(0, -$this->balanceCents);
    }

    /**
     * @return SettlementLine[]
     */
    public function linesOfOrigin(string $origin): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn(SettlementLine $line) => $line->origin === $origin
        ));
    }
}
