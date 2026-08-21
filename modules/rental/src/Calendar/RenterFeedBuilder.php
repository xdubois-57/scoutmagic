<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Calendar;

use Modules\Calendar\Api\VirtualEvent;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalAsset;

/**
 * The renter's own ICS feed — one booking, served by capability token
 * (§6.32).
 *
 * **Nothing is stored and `FileAccessGuard` is not involved**: this is not
 * a file, it is a document generated on request from the booking. The
 * token in the URL is the same tracking capability that opens their page,
 * verified the same way, and it grants exactly this one booking.
 *
 * Content is deliberately minimal: dates, the asset, the contacts they are
 * allowed to have, the emergency number and a link back to their page.
 * Never a price, never an internal comment, never another booking.
 *
 * **A cancelled booking produces a cancelled event, not an error** — a
 * renter who subscribed and then cancelled must have the entry disappear
 * from their phone, which only happens if the feed keeps saying it is off.
 *
 * Produces the same `VirtualEvent` shape the calendar provider does, so
 * both paths render through one ICS generator rather than two that drift.
 */
class RenterFeedBuilder
{
    public function __construct(private string $baseUrl = '')
    {
    }

    /**
     * @param array<int, array{manager: mixed, display_name: string, email: ?string, phone: ?string}> $renterContacts
     */
    public function build(
        RentalBooking $booking,
        RentalAsset $asset,
        string $trackingToken,
        array $renterContacts = []
    ): VirtualEvent {
        return new VirtualEvent(
            // The same UID the calendar provider would give this booking
            // on THIS feed, on purpose: if a renter somehow ends up with
            // both, one booking is one event (§6.32). The provider keys its
            // UIDs by calendar because an asset publishes onto several and
            // the registry keys events by UID alone — here the calendar is
            // 0, the same value used just below, so the two agree.
            uid: RentalVirtualEventProvider::bookingUid($booking->id, 0),
            // No calendar to belong to — this feed IS the calendar. Zero
            // rather than a made-up id, so nothing can mistake it for a
            // real one.
            calendarId: 0,
            title: $asset->name,
            startDate: $booking->arrivalDate,
            endDate: $booking->departureDate,
            startTime: self::timeOrNull($asset->arrivalTime),
            endTime: self::timeOrNull($asset->departureTime),
            location: $asset->name,
            description: $this->description($booking, $asset, $renterContacts),
            url: $this->trackingUrl($booking, $trackingToken),
            isCancelled: !$booking->status->occupiesTheAsset(),
            sequence: $booking->status->isFinal() ? 1 : 0
        );
    }

    public function calendarName(RentalBooking $booking): string
    {
        return 'Location ' . $booking->reference;
    }

    /**
     * @param array<int, array{manager: mixed, display_name: string, email: ?string, phone: ?string}> $renterContacts
     */
    private function description(RentalBooking $booking, RentalAsset $asset, array $renterContacts): string
    {
        $lines = [$booking->reference, $booking->status->label()];

        foreach ($renterContacts as $contact) {
            $line = 'Contact : ' . $contact['display_name'];
            if (($contact['phone'] ?? null) !== null) {
                $line .= ' — ' . $contact['phone'];
            }
            if (($contact['email'] ?? null) !== null) {
                $line .= ' — ' . $contact['email'];
            }
            $lines[] = $line;
        }

        // Shown here and on their own page, nowhere else (§6.6).
        if ($asset->emergencyPhone !== null && $asset->emergencyPhone !== '') {
            $lines[] = 'Urgence : ' . $asset->emergencyPhone;
        }

        return implode("\n", $lines);
    }

    private function trackingUrl(RentalBooking $booking, string $trackingToken): ?string
    {
        $base = rtrim($this->baseUrl, '/');

        return $base === ''
            ? null
            : $base . '/locations/suivi/' . $booking->id . '/' . $trackingToken;
    }

    private static function timeOrNull(?string $time): ?string
    {
        return $time !== null && trim($time) !== '' ? $time : null;
    }
}
