<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * Why a booking is on « À traiter » (§22.5).
 *
 * The list used to be filtered on the STATUS alone, which meant a confirmed
 * booking carrying a change request the renter was waiting on appeared
 * nowhere — and that is exactly a thing to deal with. So the list answers a
 * wider question now, and every row says which of these put it there: a
 * list that has grown without saying why reads as a list that has broken.
 */
enum AttentionReason: string
{
    /** The booking's own status asks for a decision. */
    case STATUS = 'status';

    /** The renter asked for something and is waiting on the unit. */
    case RENTER_REQUEST = 'renter_request';

    /**
     * The unit proposed something and the renter has not answered.
     *
     * It waits on somebody too, and the unit is the one who has to know it
     * is still waiting — a proposal nobody followed up is how a booking
     * goes quiet for three weeks.
     */
    case UNIT_PROPOSAL = 'unit_proposal';

    public function label(): string
    {
        return match ($this) {
            self::STATUS => 'Une décision est attendue de vous',
            self::RENTER_REQUEST => 'Le locataire a demandé une modification',
            self::UNIT_PROPOSAL => "Votre proposition attend la réponse du locataire",
        };
    }
}
