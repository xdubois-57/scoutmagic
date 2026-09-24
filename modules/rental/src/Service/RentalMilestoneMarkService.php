<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalMilestoneMarkRepository;

/**
 * « Marquer comme fait » on a step the site cannot derive (issue #462, D5).
 *
 * A tick writes a real fact — who and when — and the booking's history
 * carries it like any other action, so a tick is as accountable as a
 * payment recorded or a document sent. Whether the step may be ticked on
 * THIS booking (it must be one the site cannot derive, and in a stretch
 * the booking has reached) is the caller's to establish from the journey;
 * this service refuses anything that could never be ticked by hand.
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
        if (!in_array($milestoneKey, BookingMilestones::MARKABLE, true)) {
            throw new RentalException('Cette étape se coche toute seule : elle ne se marque pas à la main.');
        }

        $changed = $done
            ? $this->repository->mark($booking->id, $milestoneKey, $actorMemberId, $at)
            : $this->repository->unmark($booking->id, $milestoneKey);

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
}
