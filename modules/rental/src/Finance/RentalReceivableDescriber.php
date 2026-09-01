<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Finance;

use Modules\Finance\Api\ReceivableSourceDescriberInterface;
use Modules\Rental\Service\RentalPaymentService;

/**
 * What « Paiements attendus » calls this module's expectations.
 *
 * A booking's group used to be headed « Location #45 » — the row's own
 * primary key, above lines already reading « LOC-2027-0012 — Jean
 * Dupont ». Finance could not do better: it does not know what a booking
 * is, and by §7.5 it never will. So this says it instead.
 *
 * A booking and its security deposit share a `source_reference_id`, so
 * this heading is what tells the treasurer the two lines below belong to
 * the same letting.
 */
class RentalReceivableDescriber implements ReceivableSourceDescriberInterface
{
    public function __construct(private \Modules\Rental\Repository\RentalBookingRepository $bookings)
    {
    }

    public function sourceModule(): string
    {
        // The same string RentalPaymentService passes to createReceivable():
        // taken from there rather than re-typed, since two spellings of it
        // would silently describe nothing.
        return RentalPaymentService::SOURCE_MODULE;
    }

    public function sourceLabel(): string
    {
        return 'Locations';
    }

    public function describeInstance(int $sourceReferenceId): ?string
    {
        $booking = $this->bookings->findById($sourceReferenceId);
        if ($booking === null) {
            // Deleted since. Finance falls back to the id, which is honest
            // where an invented name would not be.
            return null;
        }

        $renter = trim($booking->renterName);

        return $renter === '' ? $booking->reference : $booking->reference . ' — ' . $renter;
    }
}
