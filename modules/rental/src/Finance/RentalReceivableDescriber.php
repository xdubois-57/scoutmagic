<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Finance;

use Core\ScoutYear\ScoutYearResolver;
use Modules\Finance\Api\ReceivableDestination;
use Modules\Finance\Api\ReceivableSourceDescriberInterface;
use Modules\Finance\Api\ReceivableSourceDestinationInterface;
use Modules\Finance\Api\ReceivableViewer;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalPaymentService;

/**
 * What « Contrôle des créances » calls this module's expectations.
 *
 * A booking's group used to be headed « Location #45 » — the row's own
 * primary key, above lines already reading « LOC-D4E5F6 — Jean
 * Dupont ». Finance could not do better: it does not know what a booking
 * is, and by §7.5 it never will. So this says it instead.
 *
 * A booking and its security deposit share a `source_reference_id`, so
 * this heading is what tells the treasurer the two lines below belong to
 * the same letting.
 *
 * It also says where the letting's money is managed — the booking's
 * « Finances » page — for whoever manages that asset (issue #836). A
 * treasurer who sees the receivable through the finance account is not
 * thereby a manager of the asset, and that page answers anybody else with
 * a 404; no link is better than a link to one.
 */
class RentalReceivableDescriber implements ReceivableSourceDescriberInterface, ReceivableSourceDestinationInterface
{
    public function __construct(
        private RentalBookingRepository $bookings,
        private RentalAssetRepository $assets,
        private RentalAuthorizationService $authorization,
        private ScoutYearResolver $scoutYears
    ) {
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

    public function destinationFor(int $sourceReferenceId, ReceivableViewer $viewer): ?ReceivableDestination
    {
        $booking = $this->bookings->findById($sourceReferenceId);
        $asset = $booking !== null ? $this->assets->findById($booking->assetId) : null;
        if ($booking === null || $asset === null) {
            return null;
        }

        // RentalManagementController::manageableAsset()'s own test, with the
        // same year, so the link is offered exactly when the page opens.
        if (!$this->authorization->canManageAsset($viewer->email, $this->scoutYears->getAuthorizationYear()->id, $asset)) {
            return null;
        }

        return new ReceivableDestination(
            'Ouvrir la réservation',
            '/mes-locations/' . rawurlencode($asset->slug) . '/reservations/' . $booking->id . '/finances'
        );
    }
}
