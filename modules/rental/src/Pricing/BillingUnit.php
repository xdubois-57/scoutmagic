<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

/**
 * What an asset's unit price is the price *of*. Exactly six values, closed
 * on purpose (module spec §6.10): the engine is deliberately narrow, and
 * every "just one more billing shape" is what turns a pricing engine into a
 * rules engine nobody can predict.
 *
 * This one choice decides two separate things, which is why it is an enum
 * with behaviour rather than a bare string:
 *
 * 1. **The quantity formula** — `quantityFor()` below.
 * 2. **The availability model** — `isNightBased()`. The spec is explicit
 *    that the nights-vs-full-days question is *derived* from the billing
 *    unit and never configured separately (§6.8): a hall billed per night
 *    frees its departure day for the next arrival, a trailer billed per day
 *    does not come back until the evening. Letting an operator set those
 *    two independently is how you get a calendar that contradicts the
 *    invoice.
 */
enum BillingUnit: string
{
    /** One price for the whole stay, however long. */
    case FLAT_STAY = 'flat_stay';

    case PER_NIGHT = 'per_night';
    case PER_DAY = 'per_day';
    case PER_PERSON_NIGHT = 'per_person_night';
    case PER_PERSON_DAY = 'per_person_day';
    case PER_ROOM_DAY = 'per_room_day';

    /**
     * User-facing French label for the selector.
     */
    public function label(): string
    {
        return match ($this) {
            self::FLAT_STAY => 'Forfait par séjour',
            self::PER_NIGHT => 'Par nuit',
            self::PER_DAY => 'Par jour',
            self::PER_PERSON_NIGHT => 'Par personne et par nuit',
            self::PER_PERSON_DAY => 'Par personne et par jour',
            self::PER_ROOM_DAY => 'Par pièce et par jour',
        };
    }

    /**
     * The explanatory sentence shown under the selector, describing the
     * **consequence** of the choice rather than restating the label
     * (§6.8 requires it, and requires it to update as the choice changes).
     * A manager picking a billing unit is choosing calendar behaviour
     * without realising it; this is where they find out.
     */
    public function availabilityExplanation(): string
    {
        return $this->isNightBased()
            ? $this->label() . " — le jour de départ reste disponible pour une autre location, "
                . "comme dans un gîte : une location du 17 au 20 occupe les nuits du 17, 18 et 19."
            : $this->label() . " — le jour de retour est occupé, le bien n'étant rendu qu'en fin de "
                . "journée : une location du 17 au 24 occupe aussi le 24.";
    }

    /**
     * Whether availability is counted in nights (half-open interval: the
     * departure day is free again) rather than in full days (closed
     * interval: the departure day is still taken).
     */
    public function isNightBased(): bool
    {
        return match ($this) {
            self::FLAT_STAY, self::PER_NIGHT, self::PER_PERSON_NIGHT => true,
            self::PER_DAY, self::PER_PERSON_DAY, self::PER_ROOM_DAY => false,
        };
    }

    /**
     * Whether the number of people affects the quantity. Drives both the
     * quantity formula and whether a minimum expressed *in people* can
     * apply at all.
     */
    public function isPersonBased(): bool
    {
        return $this === self::PER_PERSON_NIGHT || $this === self::PER_PERSON_DAY;
    }

    /**
     * Whether booking several units of a countable stock (three of eight
     * tents) multiplies the price.
     *
     * True for the time-and-flat units — three tents for five nights is
     * three times one tent for five nights. **False for the person-based
     * units**: charging per person *and* per tent would bill the same
     * people once per tent they sleep in. Also false for per-room, where
     * the room count already is the multiplier.
     */
    public function multipliesByUnits(): bool
    {
        return match ($this) {
            self::FLAT_STAY, self::PER_NIGHT, self::PER_DAY => true,
            self::PER_PERSON_NIGHT, self::PER_PERSON_DAY, self::PER_ROOM_DAY => false,
        };
    }

    /**
     * How many time units a stay of $nights nights covers, for this billing
     * unit: nights for a night-based unit, days (one more, the interval
     * being closed) for a day-based one, and 1 for a flat stay, which is
     * priced once however long it runs.
     */
    public function timeUnitsFor(int $nights): int
    {
        return match ($this) {
            self::FLAT_STAY => 1,
            self::PER_NIGHT, self::PER_PERSON_NIGHT => $nights,
            self::PER_DAY, self::PER_PERSON_DAY, self::PER_ROOM_DAY => $nights + 1,
        };
    }

    /**
     * The billable quantity: **quantity × unit price** is the whole of the
     * base calculation (§6.10). No tiers, no progressive rate, no
     * base-plus-supplement — those are explicitly out of scope and must not
     * be reintroduced here.
     *
     * @param int $persons Already raised to the minimum by the caller when one applies, so this method never has to know about minimums.
     */
    public function quantityFor(int $nights, int $persons, int $units, int $rooms): int
    {
        $timeUnits = $this->timeUnitsFor($nights);

        return match ($this) {
            self::FLAT_STAY => $timeUnits * max(1, $units),
            self::PER_NIGHT, self::PER_DAY => $timeUnits * max(1, $units),
            self::PER_PERSON_NIGHT, self::PER_PERSON_DAY => $timeUnits * max(0, $persons),
            self::PER_ROOM_DAY => $timeUnits * max(1, $rooms),
        };
    }

    /**
     * Singular/plural noun for the time unit, for a line label
     * ("25 pers. (minimum) × 3 nuits").
     */
    public function timeUnitNoun(int $count): string
    {
        if ($this === self::FLAT_STAY) {
            return 'séjour';
        }

        return $this->isNightBased()
            ? ($count > 1 ? 'nuits' : 'nuit')
            : ($count > 1 ? 'jours' : 'jour');
    }

    /**
     * @return self[] In the order the spec lists them, which is also the order the selector shows.
     */
    public static function all(): array
    {
        return [
            self::FLAT_STAY,
            self::PER_NIGHT,
            self::PER_DAY,
            self::PER_PERSON_NIGHT,
            self::PER_PERSON_DAY,
            self::PER_ROOM_DAY,
        ];
    }
}
