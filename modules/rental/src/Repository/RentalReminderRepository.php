<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Modules\Rental\Reminder\ReminderKind;

/**
 * What has already been said, and when (§6.29).
 *
 * A reminder run asks the same questions every morning — "is the deposit
 * still unpaid?" — and without this table the answer would be yes every
 * morning for a month. A unit that gets the same notification thirty times
 * stops reading the channel, which is worse than not reminding at all.
 *
 * Nothing here is personal data: a reminder key, an id and a date.
 */
class RentalReminderRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Claim the right to send this reminder, once.
     *
     * **A compare-and-set, not a check followed by a write.** Two scheduler
     * ticks overlapping — which shared hosting makes entirely possible when
     * one run is slow — would otherwise both read "not sent" and both send.
     * The unique index refuses the second insert, and the caller learns it
     * lost by getting `false`.
     */
    public function claim(string $subjectType, int $subjectId, ReminderKind $kind, \DateTimeImmutable $on): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rental_reminders_sent (subject_type, subject_id, reminder_key, sent_on, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $subjectType,
                $subjectId,
                $kind->value,
                $on->format('Y-m-d'),
                $on->format('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException) {
            // The unique index did its job: somebody already sent this one.
            return false;
        }

        return true;
    }

    public function hasBeenSent(string $subjectType, int $subjectId, ReminderKind $kind): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM rental_reminders_sent
              WHERE subject_type = ? AND subject_id = ? AND reminder_key = ? LIMIT 1'
        );
        $stmt->execute([$subjectType, $subjectId, $kind->value]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Forget everything said about one subject.
     *
     * Called when a booking is deleted, so a later booking that happens to
     * reuse the id starts with a clean slate rather than inheriting a
     * stranger's silence.
     */
    public function forgetSubject(string $subjectType, int $subjectId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_reminders_sent WHERE subject_type = ? AND subject_id = ?');
        $stmt->execute([$subjectType, $subjectId]);
    }

    /**
     * Drop the record of one reminder, so it can legitimately fire again.
     *
     * The case this exists for: a manager pushes a due date back a month.
     * The "deposit missing" reminder they already got is about a deadline
     * that no longer exists, and staying quiet through the new one would be
     * the wrong kind of restraint.
     */
    public function forget(string $subjectType, int $subjectId, ReminderKind $kind): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rental_reminders_sent WHERE subject_type = ? AND subject_id = ? AND reminder_key = ?'
        );
        $stmt->execute([$subjectType, $subjectId, $kind->value]);
    }

    /**
     * Housekeeping: reminders about stays long past.
     */
    public function deleteSentBefore(string $date): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_reminders_sent WHERE sent_on < ?');
        $stmt->execute([$date]);

        return $stmt->rowCount();
    }
}
