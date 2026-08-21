<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\BookingTransition;
use Modules\Rental\Booking\ChangeRequest;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\ChangeRequestStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\PricingRequest;
use Modules\Rental\Pricing\QuoteEditor;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingEventRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;

/**
 * Operating a booking day to day (§6.12, §6.15–§6.18): moving it through its
 * life, negotiating its price, and deciding the changes either side asks
 * for.
 *
 * Separate from `RentalBookingService`, which is about a booking coming into
 * existence and about answering the availability calculator. This one is
 * about what a manager does with it afterwards, and it is the larger half of
 * the module's rules.
 *
 * **Nothing here reads `$_POST` or `$_SESSION`** (ARCHITECTURE.md §13): the
 * acting member's id is always an argument. **Nothing here decides who may
 * act** either — that is `RentalAuthorizationService`, called by the
 * controller before anything reaches this class. Two responsibilities, two
 * classes, so neither can be half-applied.
 *
 * **No renter identity ever reaches the journal or the booking history**
 * (SECURITY.md §5). Both are written by reference and id.
 */
class RentalOperationsService
{
    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private RentalBookingEventRepository $eventRepository,
        private RentalBookingCommentRepository $commentRepository,
        private RentalChangeRequestRepository $changeRequestRepository,
        private RentalAvailabilityService $availabilityService,
        private RentalPricingService $pricingService,
        private QuoteEditor $quoteEditor,
        private JournalService $journal,
        /**
         * Optional, and null on an installation without the Finance module
         * (§6.19). Every call below goes through `?->`, so a rental settled
         * by hand behaves exactly as it did before payments existed.
         */
        private ?RentalPaymentService $paymentService = null
    ) {
    }

    // ── Lifecycle (§6.15) ───────────────────────────────────────────────

    /**
     * Moves a booking to $target.
     *
     * Confirmation is special and goes through `confirm()` instead, because
     * it is the one transition that has to re-check availability inside the
     * same transaction as the write.
     *
     * @throws RentalException
     */
    public function changeStatus(
        RentalBooking $booking,
        BookingStatus $target,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): void {
        if ($target === BookingStatus::CONFIRMED) {
            throw new RentalException(
                'Une confirmation passe par le contrôle de disponibilité. Utilisez le bouton « Confirmer ».'
            );
        }

        if (!BookingTransition::isAllowed($booking->status, $target)) {
            throw new RentalException(BookingTransition::refusalReason($booking->status, $target));
        }

        if (!$this->bookingRepository->compareAndSetStatus($booking->id, $booking->status, $target, $now)) {
            // Somebody else moved it between the page render and this
            // click. Saying so is far better than silently applying the
            // second decision on top of the first.
            throw new RentalException(
                "Cette réservation a changé entre-temps. Rechargez la page avant d'agir."
            );
        }

        // A booking that no longer holds its dates must not keep a hold
        // pinning them: the status already released the period, and a stale
        // deadline would only reappear in the expiry sweep later.
        if (!$target->occupiesTheAsset()) {
            $this->bookingRepository->clearHold($booking->id);
        }

        $this->recordStatusChange($booking, $target, $actorMemberId);
    }

    /**
     * Confirms a booking, re-checking availability **inside the same
     * transaction** as the write (iteration 5's acceptance criterion).
     *
     * Two failures this closes, both of which are ordinary rather than
     * exotic on a shared hall:
     *
     * - **Double confirmation.** Two managers with the page open both
     *   confirm two different requests for the same week. The row lock plus
     *   the compare-and-set means the second one is told the dates went,
     *   rather than both landing and nobody noticing until August.
     * - **Stock overrun.** Twelve tents, three bookings of five. The third
     *   is refused at the moment of confirmation, on the real remaining
     *   count, not on what the calendar said when the page was rendered.
     *
     * The booking excludes **itself** from the check: it already holds its
     * own dates, and counting them would make every confirmation fail.
     *
     * @throws RentalException
     */
    public function confirm(
        RentalBooking $booking,
        RentalAsset $asset,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): void {
        if (!BookingTransition::isAllowed($booking->status, BookingStatus::CONFIRMED)) {
            throw new RentalException(BookingTransition::refusalReason($booking->status, BookingStatus::CONFIRMED));
        }

        $billingUnit = $this->pricingService->loadSettings($asset->id)->billingUnit;

        $this->bookingRepository->withAssetLocked($asset->id, function () use (
            $booking,
            $asset,
            $billingUnit,
            $actorMemberId,
            $now
        ): void {
            // Re-read inside the lock: the copy the controller loaded is
            // from before the lock was taken and may already be stale.
            $current = $this->bookingRepository->findById($booking->id);
            if ($current === null) {
                throw new RentalException("Cette réservation n'existe plus.");
            }

            if (!BookingTransition::isAllowed($current->status, BookingStatus::CONFIRMED)) {
                throw new RentalException(
                    BookingTransition::refusalReason($current->status, BookingStatus::CONFIRMED)
                );
            }

            // Firm occupation only: a competing request's temporary hold is
            // not a commitment, and arbitrating between two requests for the
            // same week is exactly what confirming one IS.
            $free = $this->availabilityService->isRangeFree(
                $asset,
                $billingUnit,
                new \DateTimeImmutable($current->arrivalDate),
                new \DateTimeImmutable($current->departureDate),
                $current->units,
                $current->reference,
                firmOnly: true
            );

            if (!$free) {
                throw new RentalException(
                    'Ces dates ne sont plus disponibles en quantité suffisante. '
                    . 'La réservation ne peut pas être confirmée.'
                );
            }

            if (!$this->bookingRepository->compareAndSetStatus(
                $current->id,
                $current->status,
                BookingStatus::CONFIRMED,
                $now
            )) {
                throw new RentalException(
                    "Cette réservation a changé entre-temps. Rechargez la page avant d'agir."
                );
            }

            // A confirmed booking holds its dates by its status; the
            // deadline has done its job and would only expire noisily later.
            $this->bookingRepository->clearHold($current->id);
            $this->recordStatusChange($current, BookingStatus::CONFIRMED, $actorMemberId);

            // Confirmation is when the unit actually expects to be paid, so
            // it is when the receivables are raised (§6.19, §6.20). Guarded
            // rather than left to throw: a Finance hiccup must not undo a
            // confirmation the manager has already been told about, and the
            // configuration screen is where a missing account is reported.
            $this->raiseReceivables($current, $asset, $now, $actorMemberId);
        });
    }

    /**
     * Places a manager's option with a deadline (§6.14).
     *
     * The same single hold mechanism the automatic block uses — only the
     * origin differs, and with it what happens when it lapses: an option is
     * a promise, so letting it run out makes the booking expired.
     *
     * @throws RentalException
     */
    public function placeOption(
        RentalBooking $booking,
        \DateTimeImmutable $until,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): void {
        if ($booking->status->isFinal()) {
            throw new RentalException(
                'Cette réservation est ' . mb_strtolower($booking->status->label())
                . ' : poser une option n\'aurait aucun effet.'
            );
        }

        if ($until <= $now) {
            throw new RentalException("L'échéance de l'option doit être dans le futur.");
        }

        $this->bookingRepository->setHold($booking->id, $until, HoldOrigin::MANAGER);
        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::HOLD_PLACED,
            null,
            $until->format('d/m/Y H:i'),
            'Option posée',
            $actorMemberId
        );
    }

    public function clearHold(RentalBooking $booking, ?int $actorMemberId): void
    {
        if ($booking->holdUntil === null) {
            return;
        }

        $this->bookingRepository->clearHold($booking->id);
        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::HOLD_CLEARED,
            $booking->holdUntil->format('d/m/Y H:i'),
            null,
            'Blocage levé',
            $actorMemberId
        );
    }

    // ── Price (§6.12) ───────────────────────────────────────────────────

    /**
     * The quote a manager is editing: the agreed one if it exists, a copy of
     * the estimate the first time, and a freshly computed quote if the
     * booking never carried one.
     */
    public function workingQuote(RentalBooking $booking, RentalAsset $asset): PriceQuote
    {
        $existing = $booking->effectivePrice();
        if ($existing !== null) {
            return $existing;
        }

        return $this->quoteFor($booking, $asset);
    }

    /**
     * @throws RentalException
     */
    public function editPriceLine(
        RentalBooking $booking,
        RentalAsset $asset,
        int $index,
        ?string $label,
        ?int $quantity,
        ?int $amountCents,
        ?int $actorMemberId
    ): void {
        $this->storeQuote(
            $booking,
            $this->quoteEditor->editLine($this->workingQuote($booking, $asset), $index, $label, $quantity, $amountCents),
            $actorMemberId
        );
    }

    /**
     * @throws RentalException
     */
    public function addPriceLine(
        RentalBooking $booking,
        RentalAsset $asset,
        string $label,
        int $quantity,
        int $amountCents,
        ?int $actorMemberId
    ): void {
        $this->storeQuote(
            $booking,
            $this->quoteEditor->addLine($this->workingQuote($booking, $asset), $label, $quantity, $amountCents),
            $actorMemberId
        );
    }

    /**
     * @throws RentalException
     */
    public function removePriceLine(
        RentalBooking $booking,
        RentalAsset $asset,
        int $index,
        ?int $actorMemberId
    ): void {
        $this->storeQuote(
            $booking,
            $this->quoteEditor->removeLine($this->workingQuote($booking, $asset), $index),
            $actorMemberId
        );
    }

    /**
     * Recomputes the automatic lines from the live tariff, **keeping every
     * hand-edited line** (§6.12).
     *
     * Never automatic. A re-quote is a decision with a visible consequence —
     * the renter sees the new total immediately — so it happens because a
     * manager asked for it, not as a side effect of some other edit.
     */
    public function recalculate(RentalBooking $booking, RentalAsset $asset, ?int $actorMemberId): void
    {
        $this->storeQuote(
            $booking,
            $this->quoteEditor->merge($this->quoteFor($booking, $asset), $this->workingQuote($booking, $asset)),
            $actorMemberId
        );
    }

    // ── Internal comments (§6.4, §6.6) ──────────────────────────────────

    /**
     * @throws RentalException
     */
    public function addComment(RentalBooking $booking, ?int $authorMemberId, string $body): int
    {
        $body = trim($body);
        if ($body === '') {
            throw new RentalException('Un commentaire vide ne sert à rien.');
        }

        $id = $this->commentRepository->create($booking->id, $authorMemberId, $body);

        // The history records that a comment exists, never a word of it —
        // the comment itself is encrypted precisely because it is not
        // something to scatter into a plain-text column (SECURITY.md §5).
        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::COMMENT_ADDED,
            null,
            null,
            'Commentaire interne ajouté',
            $authorMemberId
        );

        return $id;
    }

    // ── Change requests and proposals (§6.16) ───────────────────────────

    /**
     * Records a request from either side. **Changes nothing** — that is the
     * whole rule: a renter's request never modifies the booking silently,
     * and a manager's proposal is symmetric.
     *
     * @throws RentalException
     */
    public function requestChange(
        RentalBooking $booking,
        ChangeRequestOrigin $origin,
        ChangeRequestKind $kind,
        ?string $arrivalDate,
        ?string $departureDate,
        ?int $units,
        ?int $persons,
        ?PriceQuote $price,
        ?string $message,
        ?int $actorMemberId = null
    ): int {
        if ($booking->status->isFinal()) {
            throw new RentalException(
                'Cette réservation est ' . mb_strtolower($booking->status->label())
                . ' : elle ne peut plus être modifiée.'
            );
        }

        if ($kind === ChangeRequestKind::DATES && ($arrivalDate === null || $departureDate === null)) {
            throw new RentalException('Une demande de changement de dates doit préciser les deux dates.');
        }

        $id = $this->changeRequestRepository->create(
            $booking->id,
            $origin,
            $kind,
            $arrivalDate,
            $departureDate,
            $units,
            $persons,
            $price,
            $message
        );

        $request = $this->changeRequestRepository->findById($id);
        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::CHANGE_REQUESTED,
            $origin->value,
            $kind->value,
            $request?->summary(),
            $actorMemberId
        );

        return $id;
    }

    /**
     * Accepts a pending change request and **applies** it.
     *
     * Availability is re-checked for a date change, in the same transaction
     * as the write and with the booking excluded from its own count. A
     * cancellation is applied as a status change and follows the same
     * transition table as any other — §6.17 is explicit that there is no
     * automatic scale, so nothing here computes a refund.
     *
     * @throws RentalException
     */
    public function acceptChange(
        ChangeRequest $request,
        RentalBooking $booking,
        RentalAsset $asset,
        ChangeRequestOrigin $decidingSide,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): void {
        if (!$request->isDecidableBy($decidingSide)) {
            throw new RentalException(
                $request->isPending()
                    ? "Cette demande ne peut être décidée que par l'autre partie."
                    : 'Cette demande a déjà été traitée.'
            );
        }

        if ($request->kind === ChangeRequestKind::CANCELLATION) {
            if (!$this->changeRequestRepository->decide($request->id, ChangeRequestStatus::ACCEPTED, $actorMemberId)) {
                throw new RentalException('Cette demande a déjà été traitée.');
            }

            $this->recordChangeDecision($request, ChangeRequestStatus::ACCEPTED, $actorMemberId);
            $this->changeStatus($booking, BookingStatus::CANCELLED, $actorMemberId, $now);

            return;
        }

        $billingUnit = $this->pricingService->loadSettings($asset->id)->billingUnit;

        $this->bookingRepository->withAssetLocked($asset->id, function () use (
            $request,
            $booking,
            $asset,
            $billingUnit,
            $actorMemberId
        ): void {
            $current = $this->bookingRepository->findById($booking->id);
            if ($current === null) {
                throw new RentalException("Cette réservation n'existe plus.");
            }

            $arrival = $request->proposedArrivalDate ?? $current->arrivalDate;
            $departure = $request->proposedDepartureDate ?? $current->departureDate;
            $units = $request->proposedUnits ?? $current->units;
            $persons = $request->proposedPersons ?? $current->estimatedPersons;

            if ($request->kind->affectsAvailability()) {
                $free = $this->availabilityService->isRangeFree(
                    $asset,
                    $billingUnit,
                    new \DateTimeImmutable($arrival),
                    new \DateTimeImmutable($departure),
                    $units,
                    $current->reference,
                    // Same reasoning as confirmation: moving a booking onto
                    // dates another *request* is merely holding is a
                    // decision a manager is entitled to make.
                    firmOnly: true
                );

                if (!$free) {
                    throw new RentalException(
                        'Ces nouvelles dates ne sont pas disponibles. La demande ne peut pas être acceptée.'
                    );
                }
            }

            if (!$this->changeRequestRepository->decide(
                $request->id,
                ChangeRequestStatus::ACCEPTED,
                $actorMemberId
            )) {
                throw new RentalException('Cette demande a déjà été traitée.');
            }

            $this->bookingRepository->setStay($current->id, $arrival, $departure, $units, $persons);

            if ($request->proposedPrice !== null) {
                $this->bookingRepository->setAgreedPrice($current->id, $request->proposedPrice);
            }

            $this->eventRepository->record(
                $current->id,
                RentalBookingEventRepository::DATES_CHANGED,
                $current->arrivalDate . ' → ' . $current->departureDate,
                $arrival . ' → ' . $departure,
                null,
                $actorMemberId
            );
            $this->recordChangeDecision($request, ChangeRequestStatus::ACCEPTED, $actorMemberId);
        });
    }

    /**
     * @throws RentalException
     */
    public function refuseChange(
        ChangeRequest $request,
        ChangeRequestOrigin $decidingSide,
        ?int $actorMemberId
    ): void {
        if (!$request->isDecidableBy($decidingSide)) {
            throw new RentalException(
                $request->isPending()
                    ? "Cette demande ne peut être décidée que par l'autre partie."
                    : 'Cette demande a déjà été traitée.'
            );
        }

        if (!$this->changeRequestRepository->decide($request->id, ChangeRequestStatus::REFUSED, $actorMemberId)) {
            throw new RentalException('Cette demande a déjà été traitée.');
        }

        $this->recordChangeDecision($request, ChangeRequestStatus::REFUSED, $actorMemberId);
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * A quote computed from the live tariff for this booking's own stay.
     */
    private function quoteFor(RentalBooking $booking, RentalAsset $asset): PriceQuote
    {
        return $this->pricingService->quoteForAsset($asset->id, new PricingRequest(
            arrivalDate: $booking->arrivalDate,
            departureDate: $booking->departureDate,
            persons: $booking->estimatedPersons ?? 0,
            units: $booking->units,
            rooms: 1,
            renterCategoryId: $booking->renterCategoryId
        ));
    }

    /**
     * Persists an edited quote and journals the movement.
     *
     * The old and the new total are recorded (§6.12), because "the price
     * changed" is not what anybody needs six months later — "it went from
     * 467,50 € to 400,00 €" is.
     */
    private function storeQuote(RentalBooking $booking, PriceQuote $quote, ?int $actorMemberId): void
    {
        $before = $booking->effectiveTotalCents();
        $this->bookingRepository->setAgreedPrice($booking->id, $quote);
        $this->syncReceivableAmount($booking, $quote->totalCents, $actorMemberId);

        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::PRICE_CHANGED,
            $before !== null ? self::euros($before) : null,
            self::euros($quote->totalCents),
            null,
            $actorMemberId
        );

        $this->journal->log(
            'rental',
            'rental_booking_price_changed',
            'info',
            'Prix modifié pour ' . $booking->reference,
            [
                'booking_id' => $booking->id,
                'from_cents' => $before,
                'to_cents' => $quote->totalCents,
            ]
        );
    }

    private function recordStatusChange(RentalBooking $booking, BookingStatus $target, ?int $actorMemberId): void
    {
        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::STATUS_CHANGED,
            $booking->status->label(),
            $target->label(),
            null,
            $actorMemberId
        );

        // Reference and ids only — a renter's identity never reaches the
        // journal (SECURITY.md §5).
        $this->journal->log(
            'rental',
            'rental_booking_status_changed',
            'info',
            $booking->reference . ' : ' . $booking->status->value . ' → ' . $target->value,
            ['booking_id' => $booking->id, 'asset_id' => $booking->assetId]
        );
    }

    private function recordChangeDecision(
        ChangeRequest $request,
        ChangeRequestStatus $status,
        ?int $actorMemberId
    ): void {
        $this->eventRepository->record(
            $request->bookingId,
            RentalBookingEventRepository::CHANGE_DECIDED,
            $request->kind->value,
            $status->value,
            $request->summary(),
            $actorMemberId
        );
    }

    /**
     * Raises the booking's receivables, if there are any to raise.
     *
     * Deliberately swallows a Finance failure into a journal entry: the
     * confirmation itself has committed, the manager has been told it
     * worked, and undoing it because a receivable could not be created
     * would be a worse lie than a rental with no receivable yet — which the
     * payment panel shows plainly and a manager can retry.
     */
    private function raiseReceivables(
        RentalBooking $booking,
        RentalAsset $asset,
        \DateTimeImmutable $now,
        ?int $actorMemberId
    ): void {
        if ($this->paymentService === null) {
            return;
        }

        try {
            $this->paymentService->ensureReceivables(
                $booking,
                $this->paymentService->settingsFor($asset->id),
                $now,
                $actorMemberId
            );
        } catch (\Throwable $e) {
            $this->journal->log(
                'rental',
                'rental_receivable_failed',
                'warning',
                'Créance non créée pour ' . $booking->reference . ' : ' . $e->getMessage(),
                ['booking_id' => $booking->id, 'asset_id' => $booking->assetId]
            );
        }
    }

    /**
     * Pushes a new total onto an existing receivable (§6.12).
     *
     * A refusal — "this would drop below what has already come in" — is
     * journaled rather than thrown: the price change itself is legitimate
     * and has been recorded, and what is left is a real situation a manager
     * has to look at, not a reason to reject their edit.
     */
    private function syncReceivableAmount(RentalBooking $booking, int $totalCents, ?int $actorMemberId): void
    {
        if ($this->paymentService === null) {
            return;
        }

        try {
            $this->paymentService->updateRentalAmount(
                $booking,
                $this->paymentService->settingsFor($booking->assetId),
                $totalCents,
                false,
                $actorMemberId
            );
        } catch (\Throwable $e) {
            $this->journal->log(
                'rental',
                'rental_receivable_not_updated',
                'warning',
                'Créance non mise à jour pour ' . $booking->reference . ' : ' . $e->getMessage(),
                ['booking_id' => $booking->id, 'to_cents' => $totalCents]
            );
        }
    }

    /** `467,50 €` — for a history line a human reads, never for arithmetic. */
    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
