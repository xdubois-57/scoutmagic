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
use Modules\Rental\Booking\BookingModification;
use Modules\Rental\Booking\ModificationProgress;
use Modules\Rental\Booking\ModificationStatus;
use Modules\Rental\Booking\ModificationTerms;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Pricing\PricingRequest;
use Modules\Rental\Pricing\QuoteEditor;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use Modules\Rental\Support;

/**
 * Changing a booking's dates, participants, units or price (#927, chantier
 * §2.3) — the one door, for a manager only: the renter changes nothing
 * from their page and contacts the unit instead (§2.2).
 *
 * **Two roads, decided by one fact: has a contract ever gone out?**
 *
 * - **Not yet**: the change is applied straight to the booking, under the
 *   asset's lock, and kept in the history (`applyDirectly()`). No e-mail:
 *   the renter has signed nothing. A contract generated and not sent is
 *   left « à régénérer » by `RentalContractValidityService`.
 * - **Yes**: only through a *project* — a snapshot of the new terms that
 *   changes nothing on the booking (`saveProject()`). Saving one whose
 *   dates change checks them and holds them, without a deadline, for as
 *   long as it is active (`RentalModificationRepository` is an occupancy
 *   provider). Its replacement contract goes out from it
 *   (`sendReplacementContract()`), its terms frozen from then on, and
 *   **its countersignature applies it** (`applyOnCountersignature()`,
 *   called by `RentalSignedContractService` in the same transaction as the
 *   signed contract): dates, participants, units and price move onto the
 *   booking, the receivable takes its new amount, the contract before is
 *   « Remplacé ». The confirmation status never changes. Or it is
 *   cancelled (`cancel()`), touching nothing of the booking.
 *
 * **The price is never typed line by line.** The tariff's lines for the
 * new dates and participants, read only, and one « Remise » at most
 * (`termsFor()`, `Pricing\QuoteEditor`).
 *
 * Lock order: the asset first (`RentalOccupancyGuard::onAsset()`), the
 * booking's row inside it, never the other way round.
 */
class RentalModificationService
{
    /** The longest discount label, as the column holds it. */
    public const MAX_DISCOUNT_LABEL_LENGTH = 100;

    /** What a manager is told when a second project is attempted. */
    public const ALREADY_IN_PROGRESS = 'Une modification est déjà en cours pour cette réservation : '
        . 'terminez-la ou annulez-la avant d\'en créer une autre.';

    private QuoteEditor $quoteEditor;

    public function __construct(
        private RentalBookingRepository $bookings,
        private RentalModificationRepository $modifications,
        private RentalDocumentRepository $documentRepository,
        private RentalOccupancyGuard $occupancy,
        private RentalAvailabilityService $availability,
        private RentalPricingService $pricing,
        private BookingAudit $audit,
        private JournalService $journal,
        /** Generates the replacement contract. Null offers no project beyond its draft. */
        private ?RentalDocumentService $documents = null,
        /** The receivable's amount, and the communication. Null without Finances. */
        private ?RentalPaymentService $payments = null,
        /** The replacement contract's e-mail, and the cancellation's. */
        private ?RentalBookingMailService $mail = null,
        /**
         * Reads the renter's tracking token, so an e-mail can link back to
         * their page. Null leaves the e-mails without the link.
         *
         * @var (\Closure(int): ?string)|null
         */
        private ?\Closure $trackingToken = null
    ) {
        $this->quoteEditor = new QuoteEditor();
    }

    // ── Reading ─────────────────────────────────────────────────────────

    /**
     * Whether a contract of this booking has ever been sent — the line
     * between changing it directly and changing it through a project.
     */
    public function contractHasGoneOut(int $bookingId): bool
    {
        return $this->documentRepository->hasEverSentDocumentOfType($bookingId, DocumentType::CONTRACT);
    }

    public function active(int $bookingId): ?BookingModification
    {
        return $this->modifications->findActiveFor($bookingId);
    }

    /** The modification in progress with its paperwork, or null. */
    public function progress(int $bookingId): ?ModificationProgress
    {
        $active = $this->active($bookingId);

        return $active === null ? null : $this->progressOf($active);
    }

    /**
     * The projects in progress of several bookings with their paperwork,
     * keyed by booking id — one query for the projects, then one per
     * project found, for « À traiter » (§8.54ter).
     *
     * @param int[] $bookingIds
     * @return array<int, ModificationProgress>
     */
    public function progressForBookings(array $bookingIds): array
    {
        $progress = [];
        foreach ($this->modifications->findActiveForBookings($bookingIds) as $bookingId => $modification) {
            $one = $this->progressOf($modification);
            if ($one !== null) {
                $progress[$bookingId] = $one;
            }
        }

        return $progress;
    }

    public function progressOf(BookingModification $modification): ?ModificationProgress
    {
        return ModificationProgress::of(
            $modification,
            $this->documentRepository->findForModification($modification->id)
        );
    }

    /**
     * Every modification of the booking, newest first.
     *
     * @return list<BookingModification>
     */
    public function history(int $bookingId): array
    {
        return $this->modifications->findForBooking($bookingId);
    }

    /** One modification, only when it belongs to this booking. */
    public function find(RentalBooking $booking, int $modificationId): ?BookingModification
    {
        $modification = $this->modifications->findById($modificationId);

        return $modification !== null && $modification->bookingId === $booking->id ? $modification : null;
    }

    /**
     * The documents of a modification — its replacement contract, and
     * what was signed from it.
     *
     * @return RentalDocument[]
     */
    public function documentsOf(BookingModification $modification): array
    {
        return $this->documentRepository->findForModification($modification->id);
    }

    // ── The terms ───────────────────────────────────────────────────────

    /**
     * The terms a form asks for, priced: the tariff's lines for these
     * dates and participants, and the discount — the one place the price
     * of a modification is computed.
     *
     * @throws RentalException in French, naming the field
     */
    public function termsFor(
        RentalBooking $booking,
        RentalAsset $asset,
        ?string $arrivalDate,
        ?string $departureDate,
        ?int $persons,
        ?int $units,
        ?string $discountLabel,
        ?string $discountAmount
    ): ModificationTerms {
        $arrival = DateInput::iso(trim((string) $arrivalDate));
        $departure = DateInput::iso(trim((string) $departureDate));
        if ($arrival === null) {
            throw new RentalException("La date d'arrivée n'est pas une date valide.");
        }
        if ($departure === null) {
            throw new RentalException("La date de départ n'est pas une date valide.");
        }
        if ($departure < $arrival) {
            throw new RentalException("La date de départ doit suivre la date d'arrivée.");
        }
        if ($persons !== null && $persons < 1) {
            throw new RentalException('Le nombre de participants doit valoir au moins 1.');
        }

        $units = max(1, $units ?? $booking->units);
        if ($units > max(1, $asset->quantity)) {
            throw new RentalException('Ce bien compte ' . max(1, $asset->quantity) . ' unités au plus.');
        }

        $label = Support::optionalString($discountLabel);
        if ($label !== null && mb_strlen($label) > self::MAX_DISCOUNT_LABEL_LENGTH) {
            throw new RentalException(
                'Le libellé de la remise tient en ' . self::MAX_DISCOUNT_LABEL_LENGTH . ' caractères au plus.'
            );
        }

        $discount = null;
        if (Support::optionalString($discountAmount) !== null) {
            $discount = RentalPricingService::parseAmountToCents((string) $discountAmount);
            if ($discount === null) {
                throw new RentalException("Le montant de la remise n'est pas un montant valide.");
            }
            // The amount deducted, whatever its sign: a phone's decimal
            // keypad has no minus key, so « 50,00 » and « -50,00 » both mean
            // fifty euros off — the same rule as the settlement's « Geste
            // commercial ». Zero is no discount.
            $discount = $discount === 0 ? null : -abs($discount);
        }

        $tariff = $this->pricing->quoteForAsset(
            $asset->id,
            new PricingRequest(
                arrivalDate: $arrival->format('Y-m-d'),
                departureDate: $departure->format('Y-m-d'),
                persons: $persons ?? 0,
                units: $units,
                rooms: 1,
                renterCategoryId: $booking->renterCategoryId
            )
        );
        $price = $this->quoteEditor->withDiscount($tariff, $label, $discount);
        if ($price->totalCents < 0) {
            throw new RentalException('La remise dépasse le prix de la location.');
        }

        return new ModificationTerms(
            $arrival->format('Y-m-d'),
            $departure->format('Y-m-d'),
            $units,
            $persons,
            $price,
            $discount !== null ? ($label ?? QuoteEditor::DEFAULT_DISCOUNT_LABEL) : null,
            $discount
        );
    }

    // ── Saving ──────────────────────────────────────────────────────────

    /**
     * What « Enregistrer » does: applies the terms at once while no
     * contract has gone out, saves them as a project after (a new one, or
     * the one in preparation). Returns the modification written.
     *
     * @throws RentalException
     */
    public function save(
        RentalBooking $booking,
        RentalAsset $asset,
        ?int $modificationId,
        ModificationTerms $terms,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): BookingModification {
        return $this->contractHasGoneOut($booking->id)
            ? $this->saveProject($booking, $asset, $modificationId, $terms, $actorMemberId, $now)
            : $this->applyDirectly($booking, $asset, $terms, $actorMemberId, $now);
    }

    /**
     * Before the first contract is sent: the terms go straight onto the
     * booking, under the asset's lock, checked like any other writer of an
     * occupation (chantier §2.1), and are kept in the history. No e-mail.
     *
     * @throws RentalException
     */
    public function applyDirectly(
        RentalBooking $booking,
        RentalAsset $asset,
        ModificationTerms $terms,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): BookingModification {
        $id = $this->occupancy->onAsset($asset->id, function () use (
            $booking,
            $asset,
            $terms,
            $actorMemberId,
            $now
        ): int {
            $current = $this->occupancy->fresh($booking);
            $this->assertModifiable($current);
            if ($this->contractHasGoneOut($current->id)) {
                throw new RentalException(
                    'Un contrat a été envoyé entre-temps : la réservation ne change plus que par un contrat '
                    . 'de remplacement. Rechargez la page.'
                );
            }
            if ($terms->sameAs($current)) {
                throw new RentalException('Rien ne change : modifiez les dates, les participants ou le prix.');
            }
            $this->assertTermsFit($current, $asset, $terms, $now);

            $this->bookings->setStay(
                $current->id,
                $terms->arrivalDate,
                $terms->departureDate,
                $terms->units,
                $terms->persons
            );
            $this->bookings->setAgreedPrice($current->id, $terms->price);
            $id = $this->modifications->recordDirect($current, $terms, $actorMemberId, $now);

            $modification = $this->modifications->findById($id);
            $this->recordApplied($current, $modification, 'Appliquée directement', $actorMemberId);
            // Before the first send the amount follows the booking (§2.5).
            $this->moveReceivable($current, $asset, $terms->price->totalCents, false, $actorMemberId);

            return $id;
        });

        return $this->modifications->findById($id)
            ?? throw new RentalException("La modification n'a pas pu être enregistrée.");
    }

    /**
     * After the first contract is sent: the terms are saved as a project
     * — created, or rewritten while it is still in preparation — and the
     * booking is not touched. New dates are checked against every other
     * occupation and held from this save on.
     *
     * @throws RentalException
     */
    public function saveProject(
        RentalBooking $booking,
        RentalAsset $asset,
        ?int $modificationId,
        ModificationTerms $terms,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): BookingModification {
        $id = $this->occupancy->onAsset($asset->id, function () use (
            $booking,
            $asset,
            $modificationId,
            $terms,
            $actorMemberId,
            $now
        ): int {
            $current = $this->occupancy->fresh($booking);
            $this->assertModifiable($current);
            $active = $this->modifications->findActiveFor($current->id);

            if ($modificationId === null && $active !== null) {
                throw new RentalException(self::ALREADY_IN_PROGRESS);
            }
            if ($modificationId !== null && ($active === null || $active->id !== $modificationId)) {
                throw new RentalException("Cette modification n'est plus en cours. Rechargez la page.");
            }
            if ($active !== null && !$active->isEditable()) {
                throw new RentalException(
                    'Le contrat de remplacement est parti : ces conditions ne se modifient plus. Pour corriger, '
                    . 'annulez cette modification et créez-en une nouvelle.'
                );
            }
            if ($terms->sameAs($current)) {
                throw new RentalException('Rien ne change : modifiez les dates, les participants ou le prix.');
            }
            $this->assertTermsFit($current, $asset, $terms, $now);

            if ($active !== null) {
                if (!$this->modifications->updateDraft($active->id, $terms, $now)) {
                    throw new RentalException("Cette modification n'est plus en préparation. Rechargez la page.");
                }

                return $active->id;
            }

            try {
                $id = $this->modifications->createDraft($current, $terms, $actorMemberId, $now);
            } catch (\PDOException $e) {
                // The unique key on the project in progress: another
                // manager created one between the check above and here.
                if ((string) $e->getCode() === '23000') {
                    throw new RentalException(self::ALREADY_IN_PROGRESS, 0, $e);
                }
                throw $e;
            }

            $created = $this->modifications->findById($id);
            $this->audit->record(
                $current->id,
                BookingAudit::MODIFICATION,
                null,
                'En préparation',
                'Modification créée : ' . ($created?->summary() ?? ''),
                $actorMemberId
            );

            return $id;
        });

        return $this->modifications->findById($id)
            ?? throw new RentalException("La modification n'a pas pu être enregistrée.");
    }

    // ── The replacement contract ────────────────────────────────────────

    /**
     * « Générer et envoyer le contrat de remplacement »: a complete
     * contract generated from the project's snapshot, with the booking's
     * own communication, filed as the project's, and mailed to the renter.
     * From here the project's terms are frozen. Returns whether the e-mail
     * left — the project is sent either way, and « Renvoyer » is there for
     * a failed send.
     *
     * @throws RentalException
     */
    public function sendReplacementContract(
        RentalBooking $booking,
        RentalAsset $asset,
        int $modificationId,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): bool {
        if ($this->documents === null) {
            throw new RentalException('Les documents ne sont pas disponibles.');
        }
        $settings = $this->payments?->settingsFor($asset->id) ?? new PaymentSettings();

        [$contract, $terms] = $this->occupancy->onAsset($asset->id, function () use (
            $booking,
            $asset,
            $modificationId,
            $settings,
            $actorMemberId,
            $now
        ): array {
            $current = $this->occupancy->fresh($booking);
            $this->assertModifiable($current);
            $modification = $this->find($current, $modificationId);
            if ($modification === null || $modification->status !== ModificationStatus::DRAFT) {
                throw new RentalException("Cette modification n'est plus en préparation. Rechargez la page.");
            }

            // The same receivable, the same communication (chantier §4):
            // never a new one for a replacement contract.
            $communication = $this->payments?->communicationForContract($current, $settings, $actorMemberId);
            $terms = $this->termsBooking($current, $modification);
            $contract = $this->documents?->generate(
                $terms,
                $asset,
                DocumentType::CONTRACT,
                $settings,
                $communication,
                $actorMemberId
            ) ?? throw new RentalException('Les documents ne sont pas disponibles.');
            $this->documentRepository->setModification($contract->id, $modification->id);

            if (!$this->modifications->markSent($modification->id, $contract->id, $actorMemberId, $now)) {
                throw new RentalException('Ce contrat de remplacement a déjà été envoyé.');
            }
            $this->audit->record(
                $current->id,
                BookingAudit::MODIFICATION,
                'En préparation',
                'Contrat de remplacement envoyé',
                $modification->summary(),
                $actorMemberId
            );

            return [$contract, $terms];
        });

        return $this->mailContract($terms, $asset, $contract, false, $now);
    }

    /**
     * « Renvoyer le contrat de remplacement »: the same contract, again.
     * Returns whether the e-mail left.
     *
     * @throws RentalException
     */
    public function resendReplacementContract(
        RentalBooking $booking,
        RentalAsset $asset,
        int $modificationId,
        \DateTimeImmutable $now
    ): bool {
        $modification = $this->find($booking, $modificationId);
        if ($modification === null || $modification->status !== ModificationStatus::SENT
            || $modification->contractDocumentId === null
        ) {
            throw new RentalException("Cette modification n'a pas de contrat de remplacement à renvoyer.");
        }
        $contract = $this->documentRepository->findById($modification->contractDocumentId)
            ?? throw new RentalException('Le contrat de remplacement est introuvable.');

        return $this->mailContract($this->termsBooking($booking, $modification), $asset, $contract, true, $now);
    }

    // ── Ending it ───────────────────────────────────────────────────────

    /**
     * Cancels a project in progress: its new dates are released, the
     * booking, its contract in force and its receivable are not touched,
     * its history stays. **The renter is told only when a replacement
     * contract had gone out to them** — of a project they never saw, they
     * hear nothing. Returns whether such an e-mail left; null when none was
     * due.
     *
     * @throws RentalException
     */
    public function cancel(
        RentalBooking $booking,
        RentalAsset $asset,
        int $modificationId,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): ?bool {
        $wasSent = $this->occupancy->onAsset($asset->id, function () use (
            $booking,
            $modificationId,
            $actorMemberId,
            $now
        ): bool {
            $modification = $this->find($booking, $modificationId);
            if ($modification === null || !$modification->isActive()
                || !$this->modifications->markCancelled($modification->id, $actorMemberId, $now)
            ) {
                throw new RentalException("Cette modification n'est plus en cours.");
            }

            // Its replacement contract, and any copy of it, count for
            // nothing any more: the renter may hold one, and it binds
            // nobody.
            foreach ($this->documentRepository->findForModification($modification->id) as $document) {
                $this->documentRepository->markSuperseded([$document->id], $now);
            }

            $this->audit->record(
                $booking->id,
                BookingAudit::MODIFICATION,
                $modification->status->label(),
                'Annulée',
                $modification->summary(),
                $actorMemberId
            );

            return $modification->status === ModificationStatus::SENT;
        });

        if (!$wasSent) {
            return null;
        }

        return $this->mail?->sendModificationCancelled($booking, $asset, $this->tokenOf($booking)) ?? false;
    }

    /**
     * The countersignature of a replacement contract finalises its project
     * (chantier §2.3) — **to be called inside the asset's lock and the
     * booking's, in the transaction that files the contract signed by both
     * parties**, by `RentalSignedContractService::countersign()`. Dates,
     * participants, units, price and discount move onto the booking (same
     * id), the contract before and what was signed from it are
     * « Remplacé », the receivable takes its new amount (rental + security
     * deposit, an overpayment allowed). The confirmation status does not
     * change. Applied once: a second call finds it applied and refuses.
     *
     * @throws RentalException
     */
    public function applyOnCountersignature(
        BookingModification $modification,
        RentalBooking $booking,
        RentalAsset $asset,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): void {
        if ($modification->bookingId !== $booking->id
            || !$this->modifications->markApplied($modification->id, $actorMemberId, $now)
        ) {
            throw new RentalException('Cette modification a déjà été appliquée, ou elle a été annulée.');
        }

        $this->bookings->setStay(
            $booking->id,
            $modification->arrivalDate,
            $modification->departureDate,
            $modification->units,
            $modification->persons
        );
        if ($modification->price !== null) {
            $this->bookings->setAgreedPrice($booking->id, $modification->price);
        }

        // The contract in force until now, and every copy signed from it:
        // on file, « Remplacé », counting for nothing. The old dates that
        // the new ones do not cover are no longer the booking's, so they
        // are free again by themselves.
        $this->documentRepository->supersedeAllButModification($booking->id, $modification->id, $now);

        $this->recordApplied($booking, $this->modifications->findById($modification->id), 'Appliquée', $actorMemberId);

        if ($modification->totalCents !== null) {
            $this->moveReceivable($booking, $asset, $modification->totalCents, true, $actorMemberId);
        }
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * The booking as the project would make it — what the replacement
     * contract and its e-mail are made of.
     */
    private function termsBooking(RentalBooking $booking, BookingModification $modification): RentalBooking
    {
        return $booking->withTerms(
            $modification->arrivalDate,
            $modification->departureDate,
            $modification->units,
            $modification->persons,
            $modification->price
        );
    }

    /**
     * @throws RentalException
     */
    private function assertModifiable(RentalBooking $booking): void
    {
        if ($booking->status->isFinal()) {
            throw new RentalException(
                'Cette réservation est ' . mb_strtolower($booking->status->label())
                . ' : elle ne peut plus être modifiée.'
            );
        }
    }

    /**
     * What binds every change: the dates free of every other occupation —
     * a competing request's running hold included, no exception for a
     * manager — when they move, and the group within the asset's capacity
     * when it changes. The rules shaping what a visitor may ask (notice,
     * horizon, arrival weekdays) bind no manager.
     *
     * @throws RentalException
     */
    private function assertTermsFit(
        RentalBooking $booking,
        RentalAsset $asset,
        ModificationTerms $terms,
        \DateTimeImmutable $now
    ): void {
        if ($terms->persons !== $booking->estimatedPersons && $terms->persons !== null) {
            $errors = $this->availability->validatePersons($asset, $terms->persons);
            if ($errors !== []) {
                throw new RentalException(implode(' ', $errors));
            }
        }

        if ($terms->movesTheOccupation($booking) && !$this->occupancy->rangeIsFree(
            $asset,
            $booking->reference,
            $terms->arrivalDate,
            $terms->departureDate,
            $terms->units,
            $now
        )) {
            throw new RentalException(
                'Ces dates sont occupées par une autre location, même non confirmée, ou par un blocage : '
                . 'choisissez-en d\'autres.'
            );
        }
    }

    /** The two history lines of a modification applied. */
    private function recordApplied(
        RentalBooking $before,
        ?BookingModification $modification,
        string $how,
        ?int $actorMemberId
    ): void {
        if ($modification === null) {
            return;
        }

        if ($modification->arrivalDate !== $before->arrivalDate
            || $modification->departureDate !== $before->departureDate
        ) {
            $this->audit->record(
                $before->id,
                BookingAudit::DATES_CHANGED,
                $before->arrivalDate . ' → ' . $before->departureDate,
                $modification->arrivalDate . ' → ' . $modification->departureDate,
                null,
                $actorMemberId
            );
        }
        if ($modification->totalCents !== $before->effectiveTotalCents()) {
            $previous = $before->effectiveTotalCents();
            $this->audit->record(
                $before->id,
                BookingAudit::PRICE_CHANGED,
                $previous !== null ? Support::euros($previous) : null,
                $modification->totalCents !== null ? Support::euros($modification->totalCents) : null,
                null,
                $actorMemberId
            );
        }
        $this->audit->record(
            $before->id,
            BookingAudit::MODIFICATION,
            null,
            $how,
            $modification->summary(),
            $actorMemberId
        );

        // Reference and ids only (SECURITY.md §5).
        $this->journal->log(
            'rental',
            'rental_booking_modified',
            'info',
            'Modification appliquée à ' . $before->reference,
            ['booking_id' => $before->id, 'modification_id' => $modification->id]
        );
    }

    /**
     * Moves the receivable to the new rental price (the security deposit
     * is added by the payment service). **Never throws**: the booking has
     * changed and that is true; a Finance refusal or failure is journaled
     * and written into the booking's history, where a manager sees the
     * amount did not follow (chantier §2.5).
     */
    private function moveReceivable(
        RentalBooking $booking,
        RentalAsset $asset,
        int $rentalTotalCents,
        bool $allowOverpayment,
        ?int $actorMemberId
    ): void {
        if ($this->payments === null) {
            return;
        }

        try {
            $this->payments->updateRentalAmount(
                $booking,
                $this->payments->settingsFor($asset->id),
                $rentalTotalCents,
                $allowOverpayment,
                $actorMemberId
            );
        } catch (\Throwable $e) {
            $this->journal->log(
                'rental',
                'rental_receivable_not_updated',
                'warning',
                'Créance non mise à jour pour ' . $booking->reference,
                ['booking_id' => $booking->id, 'to_cents' => $rentalTotalCents, 'error' => $e::class]
            );
            $this->audit->record(
                $booking->id,
                BookingAudit::PRICE_CHANGED,
                null,
                Support::euros($rentalTotalCents),
                'Créance non mise à jour dans les Finances : vérifiez son montant',
                $actorMemberId
            );
        }
    }

    /**
     * Mails a replacement contract and records that it went out. Returns
     * whether it did; a failure is journaled and the sent e-mail log marks
     * it « Non envoyé ».
     */
    private function mailContract(
        RentalBooking $terms,
        RentalAsset $asset,
        RentalDocument $contract,
        bool $isResend,
        \DateTimeImmutable $now
    ): bool {
        $path = $this->documents?->absolutePath($contract);
        if ($this->mail === null || $this->documents === null || $path === null) {
            return false;
        }

        try {
            $this->mail->sendContract(
                $terms,
                $asset,
                'Contrat de remplacement v' . $contract->version,
                $path,
                $contract->originalName ?? 'contrat.pdf',
                $isResend,
                $this->tokenOf($terms),
                null,
                $contract->id
            );
            $this->documents->markSent($contract->id, $now);

            return true;
        } catch (\Throwable) {
            $this->journal->log(
                'rental',
                'rental_replacement_contract_send_failed',
                'warning',
                "Le contrat de remplacement n'a pas pu être envoyé pour " . $terms->reference,
                ['booking_id' => $terms->id, 'document_id' => $contract->id]
            );

            return false;
        }
    }

    private function tokenOf(RentalBooking $booking): ?string
    {
        return $this->trackingToken !== null ? ($this->trackingToken)($booking->id) : null;
    }
}
