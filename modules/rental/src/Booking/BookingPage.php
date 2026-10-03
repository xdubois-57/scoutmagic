<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * The pages a booking's file is spread over (issue #462, IT-01) — four at
 * first, « Modifications » since #708 (IT-20).
 *
 * The file used to be one long screen: the journey, then eight folded
 * boxes under it. Everything was there, and a manager found neither where
 * the booking stood nor what to do about it. The pages split it by the
 * question a manager arrives with — where is it, what does it cost, which
 * papers, which mail — and the booking's own rail of chips replaces the
 * asset's while one of them is open: the breadcrumb, which the controller
 * fills with the asset and its bookings list, is the way back.
 *
 * **« Séjour » is not one of them**, and not by omission. It already has a
 * page, one level deeper in the breadcrumb; a chip beside these four would
 * put it on their level. `BookingBox::STAY` therefore belongs to no page
 * here and links to its own.
 *
 * The enum owns the pairing of a page with its boxes, so a link into a box
 * (`BookingBox::href()`) and the page that renders it are built from the
 * same place and cannot aim at a page the box is not on.
 */
enum BookingPage: string
{
    case DASHBOARD = 'dashboard';
    /**
     * The renter's change requests and the unit's proposals (#708, IT-20) —
     * right after the dashboard, because it is the page somebody waiting
     * for an answer sends a manager to.
     */
    case CHANGES = 'changes';
    case FINANCES = 'finances';
    case DOCUMENTS = 'documents';
    /**
     * The inventories, the meters and the incidents (#708, IT-17) — right
     * after Documents, in the order things happen.
     */
    case INVENTORY = 'inventory';
    case MAIL = 'mail';

    public function label(): string
    {
        return match ($this) {
            self::DASHBOARD => 'Tableau de bord',
            // Not « Demandes et propositions »: « demande » would collide
            // with « la demande de location ». The renter makes a « demande
            // de modification »; their word is the one taken.
            self::CHANGES => 'Modifications',
            self::FINANCES => 'Finances',
            self::DOCUMENTS => 'Documents',
            self::INVENTORY => 'État des lieux',
            self::MAIL => 'Courrier',
        };
    }

    /**
     * What follows the booking's own URL. The dashboard IS the booking's
     * URL, so every link that already pointed at a booking — a
     * notification, a reminder, the bookings list — still lands on it.
     */
    public function pathSuffix(): string
    {
        return match ($this) {
            self::DASHBOARD => '',
            self::CHANGES => '/modifications',
            self::FINANCES => '/finances',
            self::DOCUMENTS => '/documents',
            self::INVENTORY => '/etat-des-lieux',
            self::MAIL => '/courrier',
        };
    }

    public function url(string $bookingUrl): string
    {
        return $bookingUrl . $this->pathSuffix();
    }

    /**
     * The boxes this page renders, in the order it renders them.
     *
     * @return list<BookingBox>
     */
    public function boxes(): array
    {
        return array_values(array_filter(
            BookingBox::cases(),
            fn(BookingBox $box): bool => $box->page() === $this
        ));
    }
}
