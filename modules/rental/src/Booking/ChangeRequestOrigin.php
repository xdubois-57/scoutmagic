<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * Who is asking for a change (§6.16) — and, by the same token, who may not
 * decide it.
 *
 * The asymmetry is the point: a renter's request is decided by a manager, a
 * manager's proposal is decided by the renter from their tracking page.
 * Nobody ever decides their own, which is what makes "une demande ne
 * modifie jamais silencieusement la réservation" structurally true rather
 * than a rule somebody has to remember.
 */
enum ChangeRequestOrigin: string
{
    case RENTER = 'renter';
    case MANAGER = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::RENTER => 'Demande du locataire',
            self::MANAGER => 'Proposition du gestionnaire',
        };
    }

    /** Who decides one of these. */
    public function decidedBy(): self
    {
        return $this === self::RENTER ? self::MANAGER : self::RENTER;
    }
}
