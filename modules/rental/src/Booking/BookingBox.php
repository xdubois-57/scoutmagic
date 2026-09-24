<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * The boxes a booking's file is filed into (§6.15).
 *
 * Price, payments, documents, mail, change requests, the stay, the internal
 * comments and the history used to be eight cards on one page. They are
 * spread over the booking's four pages now (`BookingPage`, issue #462), each
 * box on the page that answers the question it would be opened for, and
 * each still carrying the figure that says whether it wants opening —
 * « 317,50 € dus » on a line nobody has to open answers the question the box
 * was going to be opened for.
 *
 * **The type exists for the link, not for the list.** The journey points at
 * the box where the next thing is done, and each box's own body is written
 * out by hand because no two are alike. What must not drift is the pairing
 * of a box's name with the page and anchor a journey line aims at — so all
 * three come from here. Which step is settled in which box is said by the
 * step itself (`MilestoneAction`), built in `BookingMilestones`.
 */
enum BookingBox: string
{
    case PRICE = 'price';
    case PAYMENT = 'payment';
    case DOCUMENTS = 'documents';
    case MAIL = 'mail';
    case CHANGES = 'changes';
    case STAY = 'stay';
    case COMMENTS = 'comments';
    case HISTORY = 'history';

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
     * The booking page this box is rendered on, or null for the one box
     * that is a page of its own.
     *
     * The stay has always had its own page, one level deeper in the
     * breadcrumb, and it does not become one of the booking's chips
     * (`BookingPage`): a chip would put it on the same level as the four.
     */
    public function page(): ?BookingPage
    {
        return match ($this) {
            self::PRICE, self::PAYMENT => BookingPage::FINANCES,
            self::DOCUMENTS => BookingPage::DOCUMENTS,
            self::MAIL => BookingPage::MAIL,
            self::CHANGES, self::COMMENTS, self::HISTORY => BookingPage::DASHBOARD,
            self::STAY => null,
        };
    }

    /**
     * Where a link into this box goes, from anywhere on the booking.
     *
     * The page's URL and the box's anchor together: the box may sit on
     * another page than the link, and `public/assets/js/collapse-anchor.js`
     * opens the box the fragment names once that page has loaded. The stay
     * is a page, so its link is that page and nothing more — `#dossier-stay`
     * would land on a line and leave the manager to click it a second time.
     */
    public function href(string $bookingUrl): string
    {
        $page = $this->page();

        return $page === null
            ? $bookingUrl . '/sejour'
            : $page->url($bookingUrl) . '#' . $this->anchor();
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
}
