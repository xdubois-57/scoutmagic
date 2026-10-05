<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Booking;

use Modules\Rental\Booking\BookingBox;
use Modules\Rental\Booking\BookingPage;
use PHPUnit\Framework\TestCase;

/**
 * The pairing of a booking's four pages with the boxes they render
 * (issue #462, IT-01), in isolation from any template.
 */
final class BookingPageTest extends TestCase
{
    private const BOOKING = '/mes-locations/le-chalet/reservations/42';

    /**
     * The split the roadmap fixed: the dashboard keeps what is not money,
     * papers or mail; Finances holds the price and the payments; each of
     * the other two pages holds one box.
     */
    public function testEachPageHoldsTheBoxesItWasGiven(): void
    {
        $this->assertSame([BookingBox::COMMENTS, BookingBox::HISTORY], BookingPage::DASHBOARD->boxes());
        // « Modifications » is a page of its own, not a box (#708, IT-20).
        $this->assertSame([], BookingPage::CHANGES->boxes());
        $this->assertSame([BookingBox::PRICE, BookingBox::PAYMENT], BookingPage::FINANCES->boxes());
        $this->assertSame([BookingBox::DOCUMENTS], BookingPage::DOCUMENTS->boxes());
        $this->assertSame([BookingBox::INVENTORY], BookingPage::INVENTORY->boxes());
        $this->assertSame([BookingBox::INVOICE], BookingPage::INVOICE->boxes());
        $this->assertSame([BookingBox::MAIL], BookingPage::MAIL->boxes());
    }

    /**
     * Every box is on exactly one page — the stay's own page is gone (#708,
     * IT-18), so there is no exception left.
     */
    public function testEveryBoxIsOnExactlyOnePage(): void
    {
        foreach (BookingBox::cases() as $box) {
            $pages = array_filter(
                BookingPage::cases(),
                static fn(BookingPage $page): bool => in_array($box, $page->boxes(), true)
            );

            $this->assertCount(1, $pages, $box->value);
        }
    }

    /**
     * The dashboard is the booking's own URL, so every link that already
     * pointed at a booking — a notification, a reminder, the bookings list
     * — still lands on it; the others are one segment below, each its own.
     */
    public function testTheDashboardIsTheBookingsOwnUrl(): void
    {
        $this->assertSame(self::BOOKING, BookingPage::DASHBOARD->url(self::BOOKING));

        $suffixes = array_map(static fn(BookingPage $page): string => $page->pathSuffix(), BookingPage::cases());
        $this->assertSame($suffixes, array_unique($suffixes));
        foreach (BookingPage::cases() as $page) {
            if ($page !== BookingPage::DASHBOARD) {
                $this->assertMatchesRegularExpression('#^/[a-z]+(-[a-z]+)*$#', $page->pathSuffix());
            }
        }
    }

    /** A link into a box names the page the box is on and the box's anchor. */
    public function testALinkIntoABoxNamesItsPageAndItsAnchor(): void
    {
        $this->assertSame(self::BOOKING . '/finances#dossier-payment', BookingBox::PAYMENT->href(self::BOOKING));
        $this->assertSame(self::BOOKING . '/documents#dossier-documents', BookingBox::DOCUMENTS->href(self::BOOKING));
        $this->assertSame(self::BOOKING . '#dossier-history', BookingBox::HISTORY->href(self::BOOKING));
        $this->assertSame(self::BOOKING . '/facture#dossier-invoice', BookingBox::INVOICE->href(self::BOOKING));
        $this->assertSame(
            self::BOOKING . '/etat-des-lieux#dossier-inventory',
            BookingBox::INVENTORY->href(self::BOOKING)
        );
    }

    public function testEachPageHasAFrenchLabel(): void
    {
        $this->assertSame(
            ['Tableau de bord', 'Modifications', 'Finances', 'Documents', 'État des lieux', 'Facture', 'Courrier'],
            array_map(static fn(BookingPage $page): string => $page->label(), BookingPage::cases())
        );
    }
}
