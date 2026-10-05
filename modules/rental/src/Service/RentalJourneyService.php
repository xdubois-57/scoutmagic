<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Modules\Rental\Booking\BookingJourney;
use Modules\Rental\Booking\BookingMilestone;
use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\MilestoneEvidence;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Booking\RenterNextStep;
use Modules\Rental\Document\ConditionsVersion;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalBookingRepository;

/**
 * A booking's checklist, built from its own records (§6.15) — once, for
 * every reader: the manager's dashboard, « À traiter », and since #708
 * (IT-15) the renter's e-mails and tracking page.
 *
 * It used to live in the management controller, which was the only
 * reader. The renter's « Et maintenant ? » must say what the dashboard
 * says, so the derivation moved here rather than being written twice: two
 * readings of the same records are how a page and an e-mail come to
 * contradict each other.
 *
 * Every collaborator but the bookings is optional, as it was in the
 * controller: a site without Finances has no payments to read, and the
 * checklist greys those lines rather than failing.
 */
class RentalJourneyService
{
    public function __construct(
        private RentalBookingRepository $bookings,
        private ?RentalStayService $stay = null,
        private ?RentalMilestoneMarkService $marks = null,
        private ?RentalDocumentService $documents = null,
        private ?RentalPaymentService $payments = null
    ) {
    }

    /**
     * The payment status the checklist and the sentences read, or the
     * shape of « no payments here » without Finances.
     *
     * @return array<string, mixed>
     */
    public function payment(RentalBooking $booking, RentalAsset $asset): array
    {
        if ($this->payments === null) {
            return [
                'available' => false,
                'enabled' => false,
                'security_deposit' => ['amount_cents' => null],
            ];
        }

        return $this->payments->statusFor($booking, $this->payments->settingsFor($asset->id));
    }

    /**
     * @param RentalDocument[]|null $documents read here when not given
     * @param array<string, mixed>|null $payment read here when not given
     * @param array<string, array{at: \DateTimeImmutable, by: ?string}>|null $marks
     *   the steps ticked by hand, with who ticked them — read here, without
     *   names, when not given
     * @return list<BookingMilestone>
     */
    public function milestones(
        RentalBooking $booking,
        RentalAsset $asset,
        \DateTimeImmutable $now,
        ?array $documents = null,
        ?array $payment = null,
        ?array $marks = null,
        ?ConditionsVersion $acceptedConditions = null
    ): array {
        if ($marks === null) {
            $marks = [];
            foreach ($this->marks?->marksFor($booking->id) ?? [] as $key => $mark) {
                $marks[(string) $key] = ['at' => $mark['marked_at'], 'by' => null];
            }
        }

        $evidence = MilestoneEvidence::collect(
            $booking,
            $documents ?? $this->documents?->forBooking($booking->id),
            $payment ?? $this->payment($booking, $asset),
            // Null, not [], when the stay module is unavailable: the
            // checklist reads the difference between "nothing validated
            // yet" and "inventories do not exist here".
            $this->stay?->inventoryValidations($booking->id),
            $this->stay?->latestSettlement($booking->id),
            // An asset with neither items to check nor meters to read has
            // nothing an inventory could walk, so its walk-throughs are
            // ticked by hand (#708, IT-17).
            $this->stay === null || $this->stay->keepsInventoryFor($booking),
            $marks,
            $now,
            $acceptedConditions
        );

        return BookingMilestones::for(
            $booking,
            $now,
            $evidence->done,
            $evidence->details,
            $evidence->offsite,
            $evidence->manual
        );
    }

    /**
     * What the renter has to do next (#708, IT-15), from the booking as it
     * is stored NOW: a caller about to send an e-mail may hold the object
     * it had before the transition it just made.
     */
    public function renterNextStep(RentalBooking $booking, RentalAsset $asset, \DateTimeImmutable $now): RenterNextStep
    {
        $booking = $this->bookings->findById($booking->id) ?? $booking;
        $payment = $this->payment($booking, $asset);

        return RenterNextStep::of(
            $booking,
            BookingJourney::of($this->milestones($booking, $asset, $now, null, $payment), $booking->status),
            $now,
            $payment
        );
    }
}
