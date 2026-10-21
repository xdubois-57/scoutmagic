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
        public readonly \DateTimeImmutable $createdAt,
        /**
         * The kept amount as recorded (#980). Null on a version recorded
         * before it, when a damage « withheld from the security deposit »
         * sat beside the total instead of in it.
         */
        public readonly ?int $recordedKeptCents = null,
        /**
         * The fingerprint of the incidents it was computed from
         * (`IncidentBasis`, #980). Null on a version recorded before it.
         */
        public readonly ?string $incidentBasisHash = null,
        /**
         * What the unit gave up recovering on this version (#980), its
         * « Montant non recouvré » line as a positive figure; null on
         * every other version.
         */
        public readonly ?int $unrecoveredCents = null,
        /** Why, decrypted — written by a manager, never shown to the renter. */
        public readonly ?string $unrecoveredReason = null,
        public readonly ?\DateTimeImmutable $unrecoveredAt = null
    ) {
    }

    /** Whether this version closed the booking with a balance written off. */
    public function isUnrecovered(): bool
    {
        return $this->unrecoveredCents !== null && $this->unrecoveredCents > 0;
    }

    /**
     * Whether this version was computed from incidents other than those
     * giving $currentHash — a decision taken since, whose figures it does
     * not carry (#980, chantier §2.4). Such a version does not validate.
     *
     * One recorded before the fingerprint existed has nothing to compare
     * and is not outdated by it: #980 lot A lets such a version validate
     * at the kept amount it was recorded with. A damage still assessed
     * holds it back all the same.
     */
    public function isOutdatedFor(string $currentHash): bool
    {
        return $this->incidentBasisHash !== null && !hash_equals($this->incidentBasisHash, $currentHash);
    }

    /**
     * What the unit keeps once this settlement holds (issue #927): what the
     * stay comes to — the agreed rental, consumptions and charged damage,
     * less any goodwill gesture. The booking's receivable is brought to
     * this figure when the settlement is validated.
     *
     * A version recorded before #980 kept its withholding on top of the
     * total, and still does: a validated settlement is immutable, and the
     * meaning of its figures with it.
     */
    public function keptCents(): int
    {
        if ($this->recordedKeptCents !== null) {
            return max(0, $this->recordedKeptCents);
        }

        return max(0, $this->totalCents + ($this->securityDepositWithheldCents ?? 0));
    }

    /** The « Geste commercial » line, when this settlement grants one. */
    public function gesture(): ?SettlementLine
    {
        return $this->linesOfOrigin(SettlementLine::ORIGIN_GESTURE)[0] ?? null;
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
