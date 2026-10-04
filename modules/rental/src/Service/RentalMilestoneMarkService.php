<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\MilestoneEvidence;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalMilestoneMarkRepository;

/**
 * A step completed by hand (issue #462; any step since #708, IT-14).
 *
 * A tick writes a real fact — who and when — and the booking's history
 * carries it like any other action, so a tick is as accountable as a
 * payment recorded or a document sent. Whether the step may be ticked on
 * THIS booking (still to do, or ticked by hand to reopen it, and in a
 * stretch the booking has reached) is the caller's to establish from the
 * journey; this service refuses anything that could never be ticked by
 * hand — a status, « Demande reçue », a state.
 */
class RentalMilestoneMarkService
{
    public function __construct(
        private RentalMilestoneMarkRepository $repository,
        private BookingAudit $bookingAudit
    ) {
    }

    /**
     * @return array<string, array{marked_at: \DateTimeImmutable, marked_by_member_id: ?int}>
     */
    public function marksFor(int $bookingId): array
    {
        return $this->repository->findForBooking($bookingId);
    }

    /**
     * Ticks or unticks one step, and says so in the history. Returns
     * whether anything changed: pressing twice is not a second fact.
     *
     * @throws RentalException for a step that is never ticked by hand
     */
    public function set(
        RentalBooking $booking,
        string $milestoneKey,
        string $label,
        bool $done,
        ?int $actorMemberId,
        \DateTimeImmutable $at
    ): bool {
        // Which step may be ticked is the journey's call, made by the
        // caller on the booking as its page shows it (#708, IT-14); what
        // is refused here is a key no journey ever produces.
        if (!array_key_exists($milestoneKey, BookingMilestones::ACTORS)
            || in_array($milestoneKey, BookingMilestones::NEVER_BY_HAND, true)
        ) {
            throw new RentalException('Cette étape ne se coche pas à la main.');
        }

        $changed = $done
            ? $this->repository->mark($booking->id, $milestoneKey, $actorMemberId, $at)
            : $this->repository->unmark($booking->id, $milestoneKey);

        if (!$done) {
            $changed = $this->reopenRetiredAgreementMark($booking->id, $milestoneKey) || $changed;
        }

        if ($changed) {
            $this->bookingAudit->record(
                $booking->id,
                BookingAudit::STEP_MARKED,
                $done ? 'À faire' : 'Fait',
                $done ? 'Fait' : 'À faire',
                $label,
                $actorMemberId
            );
        }

        return $changed;
    }

    /**
     * Reopening one of the two signatures a retired « Conditions et
     * contrat acceptés » mark stands for (MilestoneEvidence::collect()).
     * That mark ticks both, so it goes — and the other signature, which
     * nobody reopened, gets a mark of its own with the same author and
     * date, so it stays done. Whether the retired mark was there.
     */
    private function reopenRetiredAgreementMark(int $bookingId, string $milestoneKey): bool
    {
        $signatures = [BookingMilestones::SIGNED_COPY_RECEIVED, BookingMilestones::CONTRACT_COUNTERSIGNED];
        if (!in_array($milestoneKey, $signatures, true)) {
            return false;
        }

        $legacy = $this->repository->findForBooking($bookingId)[MilestoneEvidence::LEGACY_CONTRACT_ACCEPTED] ?? null;
        if ($legacy === null) {
            return false;
        }

        foreach ($signatures as $other) {
            if ($other !== $milestoneKey) {
                $this->repository->mark($bookingId, $other, $legacy['marked_by_member_id'], $legacy['marked_at']);
            }
        }

        return $this->repository->unmark($bookingId, MilestoneEvidence::LEGACY_CONTRACT_ACCEPTED);
    }
}
