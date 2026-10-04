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

    /**
     * The step the booking's page puts forward is the unit's (#708, IT-12)
     * — a confirmed booking still has a contract to send, an inventory, a
     * settlement. The line names the step.
     */
    case UNIT_STEP = 'unit_step';

    /**
     * The step put forward is the renter's, and they are late: the day
     * the matching reminder would go out has come (#708, IT-12).
     */
    case RENTER_LATE = 'renter_late';

    public function label(): string
    {
        return match ($this) {
            self::STATUS => 'Une décision est attendue de vous',
            self::RENTER_REQUEST => 'Le locataire a demandé une modification',
            self::UNIT_PROPOSAL => "Votre proposition attend la réponse du locataire",
            self::UNIT_STEP => 'Une étape est à faire par vous',
            self::RENTER_LATE => 'Le locataire est en retard',
        };
    }
}
