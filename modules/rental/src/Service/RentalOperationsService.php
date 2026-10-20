<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Core\Service\DateInput;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\BookingTransition;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\PricingRequest;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use Modules\Rental\Support;

/**
 * Operating a booking day to day (§6.15–§6.18): moving it through its life,
 * its hold and its comments. Changing its dates, participants or price is
 * `RentalModificationService` (#927, chantier §2.3).
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
    /**
     * How long, at least, the dates stay held once the contract has gone
     * out (#708, IT-13) — the `contract_hold_min_days` setting's default.
     */
    public const DEFAULT_CONTRACT_HOLD_MIN_DAYS = 15;

    /**
     * How long each billing field may be, in characters.
     *
     * Generous on purpose: a Belgian invoice address fits in a fraction of
     * this, and the number exists to stop a paste from reaching a `BLOB`
     * that cannot hold it — not to tell anybody how to write an address.
     */
    private const BILLING_LIMITS = [
        'name' => 200,
        'address' => 500,
        'vat_number' => 32,
        'enterprise_number' => 32,
        'email' => 254,
        'reference' => 100,
    ];

    /** The French name of each, for the refusal. */
    private const BILLING_LABELS = [
        'name' => 'Nom ou raison sociale',
        'address' => 'Adresse',
        'vat_number' => 'Numéro de TVA',
        'enterprise_number' => "Numéro d'entreprise",
        'email' => 'Adresse email de facturation',
        'reference' => 'Référence à rappeler',
    ];

    /** The lock and the check every writer of an occupation shares (#927, chantier §2.1). */
    private RentalOccupancyGuard $occupancy;

    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private BookingAudit $bookingAudit,
        private RentalBookingCommentRepository $commentRepository,
        /**
         * A booking's modifications (#927, chantier §2.3): its end cancels
         * the one in progress, in the same transaction.
         */
        private RentalModificationRepository $modificationRepository,
        private RentalAvailabilityService $availabilityService,
        private RentalPricingService $pricingService,
        private JournalService $journal,
        /**
         * Optional, and null on an installation without the Finance module
         * (§6.19). Every call below goes through `?->`, so a rental settled
         * by hand behaves exactly as it did before payments existed.
         */
        private ?RentalPaymentService $paymentService = null,
        /**
         * Optional only so the constructor stays additive; in practice it
         * is always wired. Confirmation is when the inventory checklist is
         * copied into the booking (§6.23).
         */
        private ?RentalStayService $stayService = null
    ) {
        $this->occupancy = new RentalOccupancyGuard($bookingRepository, $availabilityService, $pricingService);
    }

    // ── Lifecycle (§6.15) ───────────────────────────────────────────────

    /**
     * Runs `$work` as one transaction under the asset's lock — for a
     * caller whose gesture writes through more than this service, such as
     * « Contrat envoyé » ticked or reopened by hand (#708, IT-14): the mark
     * and the status it carries land together or not at all.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function atomicallyOnAsset(int $assetId, callable $work): mixed
    {
        return $this->occupancy->onAsset($assetId, $work);
    }

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

        // One transaction under the asset's lock: the status and the end of
        // a modification in progress land together or not at all (#927,
        // chantier §2.3) — a project must never outlive its booking.
        $this->occupancy->onAsset($booking->assetId, function () use (
            $booking,
            $target,
            $actorMemberId,
            $now
        ): void {
            if (!$this->bookingRepository->compareAndSetStatus($booking->id, $booking->status, $target, $now)) {
                // Somebody else moved it between the page render and this
                // click. Saying so is far better than silently applying the
                // second decision on top of the first.
                throw new RentalException(
                    "Cette réservation a changé entre-temps. Rechargez la page avant d'agir."
                );
            }

            // A booking that no longer holds its dates must not keep a hold
            // pinning them: the status already released the period, and a
            // stale deadline would only reappear in the expiry sweep later.
            if (!$target->occupiesTheAsset()) {
                $this->bookingRepository->clearHold($booking->id);
            }

            // Closed, cancelled, refused or expired: its modification in
            // progress goes with it, and its new dates with it.
            if ($target->isFinal()) {
                $this->cancelModificationInProgress($booking, $actorMemberId, $now);
            }

            $this->recordStatusChange($booking, $target, $actorMemberId);
        });

        // Refused, cancelled or expired: nothing is owed any more, and what
        // came in is owed back — the receivable goes to 0 and stays, with
        // its communication (issue #927). Never throws.
        if ($target->isAbandoned()) {
            $this->paymentService?->abandonAmount($booking, $actorMemberId);
        }
    }

    /**
     * The contract went out to the renter (#708, IT-13).
     *
     * - **The status becomes « Contrat envoyé »** from any state still
     *   waiting for the unit's answer — received, information requested,
     *   proposal sent. Never backwards: a confirmed or final booking stays
     *   where it is, and a resend leaves « Contrat envoyé » as it was. No
     *   decision email: the one carrying the contract says it all.
     * - **The deposit falls due** `depositDueDays` after the FIRST send
     *   (`RentalPaymentService::contractSent()`); a later one moves nothing.
     * - **The dates stay held while the renter signs**: the hold is
     *   lengthened to last at least `$minHoldDays` from now, capped at the
     *   start of the stay. A longer hold is left alone; its origin does not
     *   change, and a new one, when none ran, is automatic.
     *
     * **Lengthening a hold is taking the dates again**, so it happens under
     * the asset's lock, after the same check as any other writer (#927,
     * chantier §2.1): a hold that lapsed may have let somebody else in.
     * The contract has already gone by then — `assertContractMayHold()` is
     * what the screens ask before sending — so the race this closes is the
     * last few milliseconds: the status still moves, the dates are not
     * taken over anybody, and the answer says so. Returns whether the dates
     * are held as the contract announced.
     */
    public function contractSent(
        RentalBooking $booking,
        RentalAsset $asset,
        ?int $actorMemberId,
        \DateTimeImmutable $now,
        int $minHoldDays = self::DEFAULT_CONTRACT_HOLD_MIN_DAYS
    ): bool {
        return $this->bookingRepository->withAssetLocked($asset->id, function () use (
            $booking,
            $asset,
            $actorMemberId,
            $now,
            $minHoldDays
        ): bool {
            $current = $this->bookingRepository->findById($booking->id) ?? $booking;

            // The first send starts the deposit's clock, whatever the status:
            // set once, never moved by a later send (issue #927).
            $this->paymentService?->contractSent(
                $current,
                $this->paymentService->settingsFor($current->assetId),
                $now
            );

            if ($current->status->isFinal() || $current->status->firmlyOccupiesTheAsset()) {
                return true;
            }

            $answering = [BookingStatus::RECEIVED, BookingStatus::INFO_REQUESTED, BookingStatus::PROPOSED];
            if (in_array($current->status, $answering, true)
                && $this->bookingRepository->compareAndSetStatus(
                    $current->id,
                    $current->status,
                    BookingStatus::CONTRACT_SENT,
                    $now
                )
            ) {
                $this->recordStatusChange($current, BookingStatus::CONTRACT_SENT, $actorMemberId);
            }

            $until = $this->contractHoldUntil($current, $now, $minHoldDays);
            if (self::lengthens($current, $until, $now) && !$this->datesAreFree($current, $asset, $now)) {
                $this->journal->log(
                    'rental',
                    'rental_contract_hold_refused',
                    'warning',
                    'Dates non bloquées à l\'envoi du contrat, déjà prises : ' . $current->reference,
                    ['booking_id' => $current->id, 'asset_id' => $asset->id]
                );

                return false;
            }

            $this->holdUntil($current, $until, $now, $actorMemberId);

            return true;
        });
    }

    /**
     * Refuses to send the contract when sending it would take dates that
     * are no longer this booking's (#927, chantier §2.1) — asked by every
     * screen before the e-mail leaves, so the renter is never handed a
     * contract for a week somebody else holds.
     *
     * Only when sending lengthens the hold: a hold still running is this
     * booking's own, and a firm one needs nothing.
     *
     * @throws RentalException
     */
    public function assertContractMayHold(
        RentalBooking $booking,
        RentalAsset $asset,
        \DateTimeImmutable $now,
        int $minHoldDays = self::DEFAULT_CONTRACT_HOLD_MIN_DAYS
    ): void {
        $until = $this->contractHoldUntil($booking, $now, $minHoldDays);
        if (self::lengthens($booking, $until, $now) && !$this->datesAreFree($booking, $asset, $now)) {
            throw new RentalException(self::DATES_TAKEN_FOR_CONTRACT);
        }
    }

    /** What a manager is told when the contract would hold dates already taken. */
    public const DATES_TAKEN_FOR_CONTRACT = 'Les dates de cette réservation ne sont plus bloquées et sont '
        . 'occupées par ailleurs : le contrat n\'est pas envoyé. Changez les dates ou refusez la demande.';

    /**
     * Whether `$until` would hold this booking's dates longer than they are
     * held now — from a lapsed hold, or past a running one.
     */
    private static function lengthens(RentalBooking $booking, ?\DateTimeImmutable $until, \DateTimeImmutable $now): bool
    {
        if ($until === null || $booking->holdsFirmly()) {
            return false;
        }

        return !$booking->holdIsActive($now) || ($booking->holdUntil !== null && $booking->holdUntil < $until);
    }

    /**
     * Whether this booking's own dates are free of every occupation but its
     * own at `$now` — the check every writer makes under the asset's lock
     * before it takes or keeps them (#927, chantier §2.1). No exception for
     * anybody: a competing request's running hold counts.
     */
    public function datesAreFree(RentalBooking $booking, RentalAsset $asset, \DateTimeImmutable $now): bool
    {
        return $this->occupancy->datesAreFree($booking, $asset, $now);
    }

    /**
     * Until when the dates stay held once the contract goes out now — the
     * rule `contractSent()` applies, also read by the confirmation that
     * announces it before anything leaves: `$minHoldDays` from now at
     * least, capped at the start of the stay, a longer running hold kept
     * as it is. Null when sending holds nothing: a final booking, one held
     * firmly (confirmed, or a signed copy not refused), or a stay starting
     * too soon with no hold running.
     */
    public function contractHoldUntil(
        RentalBooking $booking,
        \DateTimeImmutable $now,
        int $minHoldDays = self::DEFAULT_CONTRACT_HOLD_MIN_DAYS
    ): ?\DateTimeImmutable {
        if ($booking->status->isFinal() || $booking->holdsFirmly()) {
            return null;
        }

        $floor = RentalBookingService::capAtArrival(
            $now->modify('+' . max(0, $minHoldDays) . ' days'),
            $now,
            $booking->arrivalDate
        );
        $running = $booking->holdIsActive($now) ? $booking->holdUntil : null;

        return $running !== null && ($floor === null || $running >= $floor) ? $running : $floor;
    }

    /**
     * Sets the hold to `$until` when it lengthens it. Never shortens one,
     * never changes the origin of one that runs.
     */
    private function holdUntil(
        RentalBooking $booking,
        ?\DateTimeImmutable $until,
        \DateTimeImmutable $now,
        ?int $actorMemberId
    ): void {
        if ($until === null) {
            return;
        }

        $running = $booking->holdIsActive($now);
        if ($running && $booking->holdUntil !== null && $booking->holdUntil >= $until) {
            return;
        }

        $origin = $running && $booking->holdOrigin !== null ? $booking->holdOrigin : HoldOrigin::AUTOMATIC;
        $this->bookingRepository->setHold($booking->id, $until, $origin);
        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::HOLD_EXTENDED,
            $running ? $booking->holdUntil?->format('d/m/Y H:i') : null,
            $until->format('d/m/Y H:i'),
            'Blocage prolongé à l\'envoi du contrat',
            $actorMemberId
        );
    }

    /**
     * Confirms a booking, re-checking availability **inside the same
     * transaction** as the write (iteration 5's acceptance criterion).
     *
     * Two failures this closes, both of which are ordinary rather than
     * exotic on a shared hall:
     *
     * - **Double confirmation.** Two managers with the page open both
     *   confirm two different requests for the same week. The asset's lock
     *   plus the compare-and-set means the second one is told the dates
     *   went, rather than both landing and nobody noticing until August —
     *   and a competing request still holding its dates stops it the same
     *   way (#927, chantier §2.1).
     * - **Stock overrun.** Twelve tents, three bookings of five. The third
     *   is refused at the moment of confirmation, on the real remaining
     *   count, not on what the calendar said when the page was rendered.
     *
     * The booking excludes **itself** from the check: it already holds its
     * own dates, and counting them would make every confirmation fail.
     *
     * @throws RentalException
     *
     * `$milestones` is the booking's journey as its page shows it (#708,
     * IT-13). Given, the agreement must be complete: every applicable step
     * before « Réservation confirmée » done, by the site or ticked by hand.
     * Every screen passes it; only fixtures building data omit it.
     *
     * @param list<\Modules\Rental\Booking\BookingMilestone>|null $milestones
     */
    public function confirm(
        RentalBooking $booking,
        RentalAsset $asset,
        ?int $actorMemberId,
        \DateTimeImmutable $now,
        ?array $milestones = null
    ): void {
        if (!BookingTransition::isAllowed($booking->status, BookingStatus::CONFIRMED)) {
            throw new RentalException(BookingTransition::refusalReason($booking->status, BookingStatus::CONFIRMED));
        }

        $missing = $milestones !== null
            ? \Modules\Rental\Booking\BookingMilestones::missingBeforeConfirmation($milestones)
            : [];
        if ($missing !== []) {
            throw new RentalException(
                'La réservation ne peut être confirmée qu\'au bout de l\'accord. Il manque : '
                . implode(', ', array_map(static fn($m): string => '« ' . $m->label . ' »', $missing)) . '.'
            );
        }

        $this->bookingRepository->withAssetLocked($asset->id, function () use (
            $booking,
            $asset,
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

            // Every occupation counts, a competing request's running hold
            // included (#927, chantier §2.1): it was let in because these
            // dates were free when it asked, so they are its own until it
            // lapses. No manager passes over it.
            if (!$this->datesAreFree($current, $asset, $now)) {
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

            // The receivable is normally raised by the first contract
            // generation (issue #927); this only catches the booking that
            // reached confirmation without one. Guarded rather than left to
            // throw: a Finance hiccup must not undo a confirmation the
            // manager has already been told about.
            $this->raiseReceivables($current, $asset, $now, $actorMemberId);

            // The inventory checklist is copied into the booking here
            // (§6.23), so that editing the asset's checklist afterwards
            // cannot change an inventory somebody already signed off.
            // Idempotent: confirmation can be reached more than once, and
            // re-snapshotting would overwrite a completed inventory with
            // blanks.
            $this->stayService?->snapshotInventory($current, $asset->id);
        });
    }

    /**
     * Places a manager's option with a deadline (specifications.md §22.5).
     *
     * The same single hold mechanism the automatic block uses — only the
     * origin differs, and with it what happens when it lapses: an option is
     * a promise, so letting it run out makes the booking expired.
     *
     * **An option takes the dates, so it is checked like any other writer**
     * (#927, chantier §2.1): under the asset's lock, against every
     * occupation but the booking's own. A request whose hold lapsed while
     * another one took its week cannot be put back on top of it.
     *
     * @throws RentalException
     */
    public function placeOption(
        RentalBooking $booking,
        RentalAsset $asset,
        \DateTimeImmutable $until,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): void {
        if ($until <= $now) {
            throw new RentalException("L'échéance de l'option doit être dans le futur.");
        }

        $this->bookingRepository->withAssetLocked($asset->id, function () use (
            $booking,
            $asset,
            $until,
            $actorMemberId,
            $now
        ): void {
            $current = $this->bookingRepository->findById($booking->id) ?? $booking;
            if ($current->status->isFinal()) {
                throw new RentalException(
                    'Cette réservation est ' . mb_strtolower($current->status->label())
                    . ' : poser une option n\'aurait aucun effet.'
                );
            }

            if (!$this->datesAreFree($current, $asset, $now)) {
                throw new RentalException(
                    'Ces dates sont occupées par ailleurs : l\'option ne peut pas être posée.'
                );
            }

            $this->bookingRepository->setHold($current->id, $until, HoldOrigin::MANAGER);
            $this->bookingAudit->record(
                $current->id,
                BookingAudit::HOLD_PLACED,
                null,
                $until->format('d/m/Y H:i'),
                'Option posée',
                $actorMemberId
            );
        });
    }

    public function clearHold(RentalBooking $booking, ?int $actorMemberId): void
    {
        if ($booking->holdUntil === null) {
            return;
        }

        $this->bookingRepository->clearHold($booking->id);
        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::HOLD_CLEARED,
            $booking->holdUntil->format('d/m/Y H:i'),
            null,
            'Blocage levé',
            $actorMemberId
        );
    }

    // ── Price (§6.12) ───────────────────────────────────────────────────

    /**
     * The price in force: the agreed one if it exists, the estimate until
     * then, and a freshly computed quote if the booking never carried one.
     * Changing it is a modification (`RentalModificationService`).
     */
    public function workingQuote(RentalBooking $booking, RentalAsset $asset): PriceQuote
    {
        $existing = $booking->effectivePrice();
        if ($existing !== null) {
            return $existing;
        }

        return $this->quoteFor($booking, $asset);
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
        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::COMMENT_ADDED,
            null,
            null,
            'Commentaire interne ajouté',
            $authorMemberId
        );

        return $id;
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * A quote computed from the live tariff for this booking's own stay.
     */
    private function quoteFor(RentalBooking $booking, RentalAsset $asset): PriceQuote
    {
        return $this->pricingService->quoteForAsset(
            $asset->id,
            new PricingRequest(
                arrivalDate: $booking->arrivalDate,
                departureDate: $booking->departureDate,
                persons: $booking->estimatedPersons ?? 0,
                units: $booking->units,
                rooms: 1,
                renterCategoryId: $booking->renterCategoryId
            )
        );
    }

    private function recordStatusChange(RentalBooking $booking, BookingStatus $target, ?int $actorMemberId): void
    {
        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
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

    /**
     * Cancels the booking's modification in progress, when its own end
     * comes (#927, chantier §2.3) — inside the transaction of that end.
     * Silent towards the renter: the e-mail of the decision that ends the
     * booking says all there is to say.
     */
    private function cancelModificationInProgress(
        RentalBooking $booking,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): void {
        $cancelled = $this->modificationRepository->cancelActiveFor($booking->id, $actorMemberId, $now);
        if ($cancelled === null) {
            return;
        }

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::MODIFICATION,
            null,
            'Annulée',
            'Modification annulée avec la réservation : ' . $cancelled->summary(),
            $actorMemberId
        );
    }

    /**
     * Raises the booking's receivable at confirmation if no contract
     * generation raised it before (issue #927) — a booking confirmed with
     * payments switched on since, or one confirmed without a contract
     * generated by the site. Reuses the existing one otherwise, untouched.
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
            $this->paymentService->ensureReceivable(
                $booking,
                $this->paymentService->settingsFor($asset->id),
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

    // ── The billing identity (§22.6) ────────────────────────────────────

    /**
     * What this booking's invoice is to be made out to.
     *
     * Here rather than straight from the repository because a controller
     * does not talk to one (ARCHITECTURE.md § Layering), and because both
     * the manager's screen and the renter's tracking page now read and
     * write it — two call sites, one door, so the guard below cannot be
     * true of one of them and not the other.
     *
     * @return array<string, ?string>
     */
    public function billingIdentity(int $bookingId): array
    {
        return $this->bookingRepository->findBillingIdentity($bookingId);
    }

    /**
     * Records it, refusing a value no column could hold.
     *
     * **Every field but the country is an encrypted `BLOB`**, and AES-GCM
     * adds a nonce and a tag to what it is given — so a value near the
     * column's ceiling comes back over it, and MySQL either refuses the
     * whole update or truncates it into ciphertext that will never decrypt.
     * Neither answer reaches the renter as anything they can act on, which
     * is why the length is checked here and not discovered down there. The
     * ceiling is far above any real invoice address; it is a guard against
     * a paste, not a formatting rule.
     *
     * @param array<string, ?string> $identity
     * @throws RentalException
     */
    public function saveBillingIdentity(int $bookingId, array $identity): void
    {
        $this->assertBillingIdentityFits($identity);

        $this->bookingRepository->saveBillingIdentity($bookingId, $identity);
    }

    /**
     * What both coordinate forms post — the renter's tracking page and the
     * page « Facture » — in ONE call: the billing identity and, when the
     * form carried its fields, the group's responsible person on site
     * (issue #977).
     *
     * **Everything is checked before anything is written.** Writing the
     * billing identity first and refusing the contact afterwards left the
     * one saved under a flash saying nothing was, and skipped the contract
     * recheck its caller runs on success.
     *
     * @param array<string, ?string> $identity
     * @param array{name: ?string, phone: ?string}|null $onSiteContact null leaves the contact as it was
     * @throws RentalException
     */
    public function saveRenterCoordinates(int $bookingId, array $identity, ?array $onSiteContact): void
    {
        $this->assertBillingIdentityFits($identity);
        if ($onSiteContact !== null) {
            self::assertOnSiteContactFits($onSiteContact['name'], $onSiteContact['phone']);
        }

        $this->bookingRepository->saveBillingIdentity($bookingId, $identity);
        if ($onSiteContact !== null) {
            $this->bookingRepository->saveOnSiteContact($bookingId, $onSiteContact['name'], $onSiteContact['phone']);
        }
    }

    /**
     * @param array<string, ?string> $identity
     * @throws RentalException
     */
    private function assertBillingIdentityFits(array $identity): void
    {
        foreach (self::BILLING_LIMITS as $field => $limit) {
            $value = $identity[$field] ?? null;
            if (is_string($value) && mb_strlen($value) > $limit) {
                throw new RentalException(sprintf(
                    'Le champ « %s » dépasse %d caractères.',
                    self::BILLING_LABELS[$field],
                    $limit
                ));
            }
        }
    }

    /** @throws RentalException */
    private static function assertOnSiteContactFits(?string $name, ?string $phone): void
    {
        if ($name !== null && mb_strlen($name) > 200) {
            throw new RentalException('Le champ « Responsable du groupe sur place » dépasse 200 caractères.');
        }
        if ($phone !== null && mb_strlen($phone) > 40) {
            throw new RentalException('Le champ « Téléphone du responsable » dépasse 40 caractères.');
        }
    }

    /**
     * The adult responsible for the group on site (issue #977), whom the
     * contract names beside the renter.
     *
     * @return array{name: ?string, phone: ?string}
     */
    public function onSiteContact(int $bookingId): array
    {
        return $this->bookingRepository->findOnSiteContact($bookingId);
    }

    /** `467,50 €` — for a history line a human reads, never for arithmetic. */
}
