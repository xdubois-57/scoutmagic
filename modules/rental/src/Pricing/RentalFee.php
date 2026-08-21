<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

/**
 * One fee added on top of the base rate. Exactly three natures (§6.10) —
 * a fixed amount, an amount per person, or a **meter reading**.
 *
 * The three are not variations of each other, and the third is the reason
 * this is an enum rather than a boolean: a meter fee **never enters the
 * quote**. Its amount is not merely unknown, it is unknowable before the
 * stay — it comes from a real reading taken afterwards and lands on the
 * final settlement instead. Quoting an estimate for it would invent a number
 * the renter would then read as a commitment.
 *
 * Fees are an ordered list. The order is presentational only: they are
 * summed, and no fee ever modifies, caps or cancels another (§6.10).
 */
final class RentalFee
{
    public const NATURE_FIXED = 'fixed';
    public const NATURE_PER_PERSON = 'per_person';
    public const NATURE_METER = 'meter';

    /**
     * @param string $nature One of the NATURE_* constants.
     * @param int $amountCents For FIXED, the amount. For PER_PERSON, the amount per person. For METER, the price of one unit read (one kWh, one m³).
     * @param string|null $meterUnit For METER only — the unit shown next to the reading ("kWh", "m³"). Null otherwise.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly string $nature,
        public readonly int $amountCents,
        public readonly ?string $meterUnit = null,
        public readonly int $sortOrder = 0
    ) {
    }

    /**
     * Whether this fee can be priced up front. False for a meter fee, which
     * is quoted as "billed on actual consumption" and settled later.
     */
    public function isQuotable(): bool
    {
        return $this->nature !== self::NATURE_METER;
    }

    /**
     * The fee's contribution to a quote, in cents.
     *
     * $persons is the **actual** expected head count, never the count raised
     * by a billable minimum. A minimum is a floor on what the asset is worth
     * renting for; a per-person fee is a charge on the people who are really
     * there. A tourist tax billed on 25 people when 18 slept there would be
     * wrong, and in Belgium would be wrong in a way a municipality cares
     * about.
     */
    public function amountForCents(int $persons): int
    {
        return match ($this->nature) {
            self::NATURE_FIXED => $this->amountCents,
            self::NATURE_PER_PERSON => $this->amountCents * max(0, $persons),
            default => 0,
        };
    }

    public function natureLabel(): string
    {
        return match ($this->nature) {
            self::NATURE_FIXED => 'Montant fixe',
            self::NATURE_PER_PERSON => 'Montant par personne',
            self::NATURE_METER => 'Relevé de compteur',
            default => $this->nature,
        };
    }

    /**
     * @return string[] The three natures, as the configuration selector offers them.
     */
    public static function natures(): array
    {
        return [self::NATURE_FIXED, self::NATURE_PER_PERSON, self::NATURE_METER];
    }
}
