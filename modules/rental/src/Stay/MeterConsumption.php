<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

use Modules\Rental\Pricing\RentalFee;

/**
 * What one meter consumed over one stay, and what that costs (§6.22).
 *
 * Pure: no database, no clock. Given two readings and a fee, it answers
 * three questions and nothing else.
 *
 * **A meter that rolled over is reported, never guessed at.** An index that
 * went backwards means a replaced meter, a transposed digit, or a reading
 * taken at the wrong end — and every one of those is a thing for a human to
 * look at, not a thing to paper over by taking an absolute value. Silently
 * "fixing" it is how a renter gets billed for a typo.
 */
final class MeterConsumption
{
    private function __construct(
        public readonly RentalMeter $meter,
        public readonly ?MeterReading $arrival,
        public readonly ?MeterReading $departure,
        public readonly ?int $consumptionMilli,
        public readonly ?int $unitPriceCents,
        public readonly int $amountCents,
        /** A French sentence when something needs a human, else null. */
        public readonly ?string $warning
    ) {
    }

    public static function of(
        RentalMeter $meter,
        ?MeterReading $arrival,
        ?MeterReading $departure,
        ?RentalFee $fee
    ): self {
        $unitPrice = $fee !== null && $fee->nature === RentalFee::NATURE_METER ? $fee->amountCents : null;

        if ($arrival === null || $departure === null) {
            return new self(
                $meter,
                $arrival,
                $departure,
                null,
                $unitPrice,
                0,
                $arrival === null && $departure === null
                    ? null
                    : 'Il manque le relevé de ' . ($arrival === null ? "l'entrée" : 'la sortie') . '.'
            );
        }

        $consumption = $departure->valueMilli - $arrival->valueMilli;

        if ($consumption < 0) {
            return new self(
                $meter,
                $arrival,
                $departure,
                $consumption,
                $unitPrice,
                0,
                'Le relevé de sortie est inférieur à celui d\'entrée. Vérifiez les valeurs : '
                . 'aucune consommation n\'est facturée tant que ce point n\'est pas tranché.'
            );
        }

        if ($unitPrice === null) {
            return new self($meter, $arrival, $departure, $consumption, null, 0, null);
        }

        // The one rounding in the whole chain, and it happens here, at the
        // end: the subtraction above is exact because both values are
        // integers.
        $amount = (int) round($consumption * $unitPrice / MeterReading::SCALE);

        return new self($meter, $arrival, $departure, $consumption, $unitPrice, $amount, null);
    }

    public function isComplete(): bool
    {
        return $this->arrival !== null && $this->departure !== null && $this->warning === null;
    }

    public function isBillable(): bool
    {
        return $this->isComplete() && $this->unitPriceCents !== null;
    }

    /** `342,500 kWh`. */
    public function formattedConsumption(): string
    {
        if ($this->consumptionMilli === null) {
            return '—';
        }

        $unit = $this->meter->displayUnit();

        return MeterReading::format($this->consumptionMilli) . ($unit !== '' ? ' ' . $unit : '');
    }

    /** The settlement line this becomes, or null when there is nothing to bill. */
    public function settlementLabel(): string
    {
        return $this->meter->label . ' — ' . $this->formattedConsumption();
    }
}
