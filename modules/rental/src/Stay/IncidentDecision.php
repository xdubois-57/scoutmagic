<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * What a manager decided about a damage (§6.23).
 *
 * **The financial decision stays human.** Nothing in this module turns a
 * damage into a charge on its own. An automatic scale would be wrong about
 * exactly the case that matters — the group that broke something and
 * immediately said so — and §6.17 already refuses one for cancellations
 * for the same reason.
 *
 * `PENDING` is a real state: an assessment in progress must never reach the
 * renter's page (§6.26), and this is the flag that keeps it off.
 */
enum IncidentDecision: string
{
    case PENDING = 'pending';
    /** Added to the final settlement, so the renter pays it. */
    case CHARGE = 'charge';
    /** Withheld from the security deposit instead of being invoiced. */
    case WITHHOLD = 'withhold';
    case WAIVE = 'waive';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => "En cours d'évaluation",
            self::CHARGE => 'À ajouter au décompte',
            self::WITHHOLD => 'À retenir sur la caution',
            self::WAIVE => 'Ne pas facturer',
        };
    }

    /** Whether this incident's amount enters the final settlement's total. */
    public function entersSettlement(): bool
    {
        return $this === self::CHARGE;
    }

    /** Whether it reduces what is given back from the security deposit. */
    public function reducesSecurityDeposit(): bool
    {
        return $this === self::WITHHOLD;
    }

    /**
     * Whether this incident may be shown to the renter.
     *
     * A damage still being assessed must not appear on their page: they
     * would read a proposed figure as a demand, and the whole point of
     * `PENDING` is that nobody has decided yet (§6.26).
     */
    public function isVisibleToRenter(): bool
    {
        return $this !== self::PENDING;
    }

    /** @return self[] The ones a manager may actually pick. */
    public static function decidable(): array
    {
        return [self::CHARGE, self::WITHHOLD, self::WAIVE];
    }
}
