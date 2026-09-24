<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Service\DateInput;

/**
 * The steps of a booking ticked by hand (issue #462, D5) — only those the
 * site cannot derive; see `Booking\MilestoneEvidence`.
 *
 * One row per step and booking: marking twice keeps the first tick, and
 * « Remettre à faire » deletes the row rather than flagging it, so what is
 * stored is exactly what is ticked.
 */
class RentalMilestoneMarkRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, array{marked_at: \DateTimeImmutable, marked_by_member_id: ?int}> keyed by milestone
     */
    public function findForBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT milestone_key, marked_at, marked_by_member_id
             FROM rental_booking_milestone_marks WHERE booking_id = ?'
        );
        $stmt->execute([$bookingId]);

        $marks = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $at = DateInput::fromStorage((string) $row['marked_at']);
            if ($at === null) {
                continue;
            }
            $marks[(string) $row['milestone_key']] = [
                'marked_at' => $at,
                'marked_by_member_id' => $row['marked_by_member_id'] !== null ? (int) $row['marked_by_member_id'] : null,
            ];
        }

        return $marks;
    }

    /**
     * Ticks a step. False when it already was: the first tick is the one
     * that counts, and a second press is not a new fact.
     */
    public function mark(int $bookingId, string $milestoneKey, ?int $memberId, \DateTimeImmutable $at): bool
    {
        // Read, then write — `INSERT IGNORE` is not portable to the SQLite
        // test database (the precedent TransactionRepository::insertOrSkip()
        // set). The unique index is the backstop for two presses racing
        // past the read: the loser's insert fails and counts as « already ».
        $exists = $this->pdo->prepare(
            'SELECT 1 FROM rental_booking_milestone_marks WHERE booking_id = ? AND milestone_key = ?'
        );
        $exists->execute([$bookingId, $milestoneKey]);
        if ($exists->fetchColumn() !== false) {
            return false;
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO rental_booking_milestone_marks
                    (booking_id, milestone_key, marked_by_member_id, marked_at)
                 VALUES (?, ?, ?, ?)'
            )->execute([$bookingId, $milestoneKey, $memberId, $at->format('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            // Only the unique index losing a race means « already ticked »;
            // anything else is a real failure and must surface as one.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * Unticks a step. False when it was not ticked.
     */
    public function unmark(int $bookingId, string $milestoneKey): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rental_booking_milestone_marks WHERE booking_id = ? AND milestone_key = ?'
        );
        $stmt->execute([$bookingId, $milestoneKey]);

        return $stmt->rowCount() === 1;
    }
}
