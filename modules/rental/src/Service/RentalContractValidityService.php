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
 *   reçue »**: a new contract must go out. The hold is not shortened;
 * - **leaves a confirmed booking confirmed** — going back would free its
 *   dates — and its reopened steps put it back on « À traiter ».
 *
 * A cancelled, refused or expired booking voids nothing: it has stopped.
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
        if ($booking->status->isAbandoned()) {
            return false;
        }

        $documents = array_values(array_filter(
            $this->documentRepository->findForBooking($booking->id),
            static fn(RentalDocument $d): bool => !$d->isSuperseded()
        ));
        $contracts = array_values(array_filter(
            $documents,
            // A contract generated before fingerprints existed cannot be
            // judged, and is left alone rather than guessed about.
            static fn(RentalDocument $d): bool => $d->type === DocumentType::CONTRACT && $d->fingerprint !== null
        ));
        if ($contracts === []) {
            return false;
        }

        $current = ContractFingerprint::of(
            $this->documents->valuesFor($booking, $asset, $this->paymentService?->settingsFor($asset->id) ?? new PaymentSettings()),
            $booking->effectivePrice()
        );

        $stale = array_values(array_filter($contracts, static fn(RentalDocument $d): bool => $d->fingerprint !== $current));
        if ($stale === []) {
            return false;
        }

        // The copies signed from a contract that still holds stay: only
        // what was signed before it — from a contract now void — goes.
        $validSince = null;
        foreach ($contracts as $contract) {
            if ($contract->fingerprint === $current && ($validSince === null || $contract->id < $validSince)) {
                $validSince = $contract->id;
            }
        }

        $void = array_map(static fn(RentalDocument $d): int => $d->id, $stale);
        foreach ($documents as $document) {
            $signed = $document->type === DocumentType::SIGNED_COPY || $document->type === DocumentType::SIGNED_CONTRACT;
            if ($signed && ($validSince === null || $document->id < $validSince)) {
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
        }

        return true;
    }
}
