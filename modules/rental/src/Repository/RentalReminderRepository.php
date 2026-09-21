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
     *
     * `$repeatAfterDays` is how the four repeating reminders say so — the
     * three unpaid-money ones weekly, the contract a second time as the
     * stay nears (`Reminder\ReminderSchedule::repeatAfterDaysFor()`). Null,
     * and it is said once and never again.
     */
    public function claim(
        string $subjectType,
        int $subjectId,
        ReminderKind $kind,
        \DateTimeImmutable $on,
        ?int $repeatAfterDays = null
    ): bool {
        // A reminder that may be said again carries its row forward rather
        // than adding a second one, and that is a deliberate departure from
        // the chantier, which proposed loosening the unique index onto
        // `sent_on`.
        //
        // **The index could not have been loosened.** `SchemaComparator`
        // matches an index by NAME only, never by its columns, and nothing
        // in this repository ever drops one — `drops.sql` is for columns,
        // and says so. A redefined `idx_rental_reminder_once` would
        // therefore have stayed exactly as it is on every installed site
        // (AGENTS.md § Schema), still refusing the second insert, while
        // passing on a fresh install. The failure would have been invisible
        // precisely where it mattered.
        //
        // Carrying the row forward is also the better shape: the unique
        // index keeps doing the one job it was written for — two overlapping
        // scheduler ticks cannot both send — and the cadence lives in a
        // WHERE clause instead of in the absence of a constraint.
        if ($repeatAfterDays !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE rental_reminders_sent SET sent_on = ?, created_at = ?
                 WHERE subject_type = ? AND subject_id = ? AND reminder_key = ? AND sent_on <= ?'
            );
            $stmt->execute([
                $on->format('Y-m-d'),
                $on->format('Y-m-d H:i:s'),
                $subjectType,
                $subjectId,
                $kind->value,
                $on->modify('-' . max(1, $repeatAfterDays) . ' days')->format('Y-m-d'),
            ]);

            // One statement, so this is a compare-and-set like the insert
            // below: a row updated is a send claimed, and zero rows means
            // either nothing was sent yet (the insert takes over) or it is
            // too soon.
            if ($stmt->rowCount() > 0) {
                return true;
            }
        }

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
