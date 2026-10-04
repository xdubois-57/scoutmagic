<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\MilestoneEvidence;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\ContractFingerprint;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Repository\RentalMilestoneMarkRepository;
use Modules\Rental\Repository\RentalReminderRepository;
use Modules\Rental\Reminder\ReminderKind;

/**
 * A contract becomes void the moment the booking changes (#708, IT-20).
 *
 * Asked after anything that may have changed a booking — every manager's
 * gesture on it, a proposal the renter accepted — and answering from the
 * one rule `Document\ContractFingerprint` holds: what the contract says is
 * no longer what the booking says. Every generated contract counts, sent or
 * not, signed or not: one waiting for a signature would describe a booking
 * that no longer exists too.
 *
 * What it does about one:
 *
 * - **keeps it**, marked « Remplacé » with the date — and every copy signed
 *   from it, the renter's and the countersigned one: they sign a text that
 *   no longer holds, and are no longer downloadable;
 * - **reopens the contract's steps** — they read only documents still in
 *   force, and a step ticked by hand is unticked;
 * - **forgets the « copie signée attendue » reminder** already sent: it is
 *   said once per booking, and the new contract has a deadline of its own;
 * - **takes a booking that was « Contrat envoyé » back to « Demande
 *   reçue »**: a new contract must go out. The hold is not shortened, and a
 *   manager's option running on it becomes an automatic hold to the same
 *   date: the unit's answer still stands, so the option's lapse frees the
 *   dates as it would have under « Contrat envoyé » rather than expiring
 *   a booking that waits on the unit's new contract;
 * - **leaves a confirmed booking confirmed** — going back would free its
 *   dates — and its reopened steps put it back on « À traiter ».
 *
 * A cancelled, refused or expired booking voids nothing: it has stopped.
 * Nor does a closed one: its stay is over, its contract is what was
 * signed for it, and a renter correcting a billing address for the invoice
 * must not take the countersigned copy away from them.
 */
class RentalContractValidityService
{
    /** The steps a void contract reopens. */
    private const CONTRACT_STEPS = [
        BookingMilestones::CONTRACT_GENERATED,
        BookingMilestones::CONTRACT_SENT,
        BookingMilestones::SIGNED_COPY_RECEIVED,
        BookingMilestones::CONTRACT_COUNTERSIGNED,
    ];

    public function __construct(
        private RentalDocumentService $documents,
        private RentalDocumentRepository $documentRepository,
        private RentalBookingRepository $bookingRepository,
        private BookingAudit $bookingAudit,
        private ?RentalPaymentService $paymentService = null,
        private ?RentalMilestoneMarkRepository $marks = null,
        private ?RentalReminderRepository $reminders = null
    ) {
    }

    /**
     * Voids what the booking has outgrown. Returns whether anything was
     * voided now.
     */
    public function recheck(
        RentalBooking $booking,
        RentalAsset $asset,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): bool {
        if ($booking->status->isAbandoned() || $booking->status === BookingStatus::CLOSED) {
            return false;
        }

        $all = $this->documentRepository->findForBooking($booking->id);
        $documents = array_values(array_filter($all, static fn(RentalDocument $d): bool => !$d->isSuperseded()));
        $contracts = array_values(array_filter(
            $documents,
            // A contract generated before fingerprints existed cannot be
            // judged, and is left alone rather than guessed about.
            static fn(RentalDocument $d): bool => $d->type === DocumentType::CONTRACT && $d->fingerprint !== null
        ));
        if ($contracts === []) {
            return false;
        }

        $values = $this->documents->valuesFor(
            $booking,
            $asset,
            $this->paymentService?->settingsFor($asset->id) ?? new PaymentSettings()
        );
        $price = $booking->effectivePrice();

        // Each contract read against what it printed: a detail it left
        // blank, filled in since, is not a change (ContractFingerprint).
        $stale = array_values(array_filter(
            $contracts,
            fn(RentalDocument $d): bool => $d->fingerprint !== ContractFingerprint::against(
                $values,
                $this->documentRepository->findSnapshot($d->id) ?? $values,
                $price
            )
        ));
        if ($stale === []) {
            return false;
        }

        $void = array_map(static fn(RentalDocument $d): int => $d->id, $stale);
        foreach ($documents as $document) {
            $signed = $document->type === DocumentType::SIGNED_COPY
                || $document->type === DocumentType::SIGNED_CONTRACT;
            if ($signed && self::signedFromAVoidContract($document, $all, $void)) {
                $void[] = $document->id;
            }
        }
        $this->documentRepository->markSuperseded($void, $now);

        // The retired « Conditions et contrat acceptés » mark ticked the
        // whole agreement (MilestoneEvidence): left standing, it would tick
        // the void contract's steps straight back.
        foreach ([...self::CONTRACT_STEPS, MilestoneEvidence::LEGACY_CONTRACT_ACCEPTED] as $step) {
            $this->marks?->unmark($booking->id, $step);
        }

        // Said once per booking, never per contract: left claimed, the
        // renter would never hear of the replacement's deadline.
        $this->reminders?->forget(
            ReminderKind::SIGNED_COPY_DUE->subjectType(),
            $booking->id,
            ReminderKind::SIGNED_COPY_DUE
        );

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            DocumentType::CONTRACT->label(),
            'Remplacé',
            'Contrat devenu caduc : la réservation a changé',
            $actorMemberId
        );

        if ($booking->status === BookingStatus::CONTRACT_SENT
            && $this->bookingRepository->compareAndSetStatus(
                $booking->id,
                BookingStatus::CONTRACT_SENT,
                BookingStatus::RECEIVED,
                $now
            )
        ) {
            $this->bookingAudit->record(
                $booking->id,
                BookingAudit::STATUS_CHANGED,
                BookingStatus::CONTRACT_SENT->label(),
                BookingStatus::RECEIVED->label(),
                'Un nouveau contrat doit partir',
                $actorMemberId
            );

            // Under « Contrat envoyé » an option's lapse only freed the
            // dates (RentalBooking::lapseEndsTheBooking()); under « Demande
            // reçue » it would expire the booking — for a delay that is now
            // the unit's. Same date, kept from ending it.
            if ($booking->holdOrigin === HoldOrigin::MANAGER && $booking->holdIsActive($now)) {
                $this->bookingRepository->setHold($booking->id, $booking->holdUntil, HoldOrigin::AUTOMATIC);
            }
        }

        return true;
    }

    /**
     * Whether a signed copy was signed from a contract that no longer holds.
     *
     * A copy answers the contract filed last before it — the one the renter
     * had in hand. It goes when that contract is void (now or before), and
     * stays when it still holds — including a contract older than
     * fingerprints, which nothing here can judge. A copy filed before any
     * contract says nothing about one, and is left alone.
     *
     * @param RentalDocument[] $all every document of the booking, void ones included
     * @param int[] $voidNow the contracts voided by this pass
     */
    private static function signedFromAVoidContract(RentalDocument $copy, array $all, array $voidNow): bool
    {
        $answered = null;
        foreach ($all as $document) {
            if ($document->type === DocumentType::CONTRACT
                && $document->id < $copy->id
                && ($answered === null || $document->id > $answered->id)
            ) {
                $answered = $document;
            }
        }

        return $answered !== null && ($answered->isSuperseded() || in_array($answered->id, $voidNow, true));
    }
}
