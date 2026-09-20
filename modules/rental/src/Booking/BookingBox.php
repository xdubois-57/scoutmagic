<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * The boxes a booking's file is filed into — the second half of §6.15's
 * "one page, read top to bottom".
 *
 * Price, payments, documents, mail, change requests, the stay, the internal
 * comments and the history used to be eight cards unfolded one under the
 * other: everything at once, which is another way of saying nothing first.
 * They are a folded list now, each line carrying the figure that says
 * whether it wants opening — « 317,50 € dus » on a line nobody has to open
 * answers the question the box was going to be opened for.
 *
 * **The type exists for the link, not for the list.** The journey above the
 * boxes points at the box where the next thing is done, and the template
 * writes out each box's own body by hand because no two are alike. What
 * must not drift is the pairing of a box's name with the anchor a journey
 * line aims at — so both come from here, and `forMilestone()` is the one
 * place that says which milestone is settled in which box.
 */
enum BookingBox: string
{
    case PRICE = 'prix';
    case PAYMENT = 'paiements';
    case DOCUMENTS = 'documents';
    case MAIL = 'courrier';
    case CHANGES = 'demandes';
    case STAY = 'sejour';
    case COMMENTS = 'commentaires';
    case HISTORY = 'historique';

    public function label(): string
    {
        return match ($this) {
            self::PRICE => 'Prix',
            self::PAYMENT => 'Paiements',
            self::DOCUMENTS => 'Documents',
            self::MAIL => 'Courrier',
            self::CHANGES => 'Demandes et propositions',
            self::STAY => 'Séjour',
            self::COMMENTS => 'Commentaires internes',
            self::HISTORY => 'Historique',
        };
    }

    /**
     * The id of the box's card, which is what a journey line links to.
     *
     * `public/assets/js/collapse-anchor.js` opens the folded panel a
     * fragment points at — either the panel itself or the card holding it —
     * so a link to this id both unfolds the box and scrolls to it, and a
     * link that arrives from outside the page works the same way.
     */
    public function anchor(): string
    {
        return 'dossier-' . $this->value;
    }

    /**
     * The id of the folded panel inside that card.
     *
     * `<card>-body` is not a shape invented here: it is the convention
     * `core/View/templates/config/maintenance.html.twig` writes for every
     * one of its boxes, and the one `tests/e2e/support/collapsible-card.js`
     * already knows — so a scenario unfolds a box of this page with the
     * helper the rest of the suite uses, and learns nothing new.
     */
    public function bodyAnchor(): string
    {
        return $this->anchor() . '-body';
    }

    /**
     * Where a milestone is actually settled, or null when it is settled on
     * the journey itself.
     *
     * The four that answer null are the ones whose button is right there in
     * their phase: the dates are held by the phase's own form, and
     * confirming or closing is a status transition, which `BookingPhase::
     * ofTransition()` puts in the phase rather than in a box. Sending them
     * to a box would be sending them away from the button.
     */
    public static function forMilestone(string $milestoneKey): ?self
    {
        return match ($milestoneKey) {
            BookingMilestones::CONTRACT_SENT,
            BookingMilestones::CONTRACT_ACCEPTED => self::DOCUMENTS,
            BookingMilestones::DEPOSIT_RECEIVED,
            BookingMilestones::BALANCE_RECEIVED,
            BookingMilestones::SECURITY_DEPOSIT_RECEIVED,
            BookingMilestones::SECURITY_DEPOSIT_RETURNED => self::PAYMENT,
            BookingMilestones::ARRIVAL_INVENTORY,
            BookingMilestones::METER_READINGS,
            BookingMilestones::DEPARTURE_INVENTORY,
            BookingMilestones::FINAL_SETTLEMENT => self::STAY,
            default => null,
        };
    }
}
