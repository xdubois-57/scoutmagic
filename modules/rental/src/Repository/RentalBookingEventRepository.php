<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

/**
 * What happened to one booking (§6.15) — the per-booking history a manager
 * reads, as distinct from the site-wide audit journal.
 *
 * The two are not redundant. `Core\Journal` answers "what happened on this
 * site", is admin-facing, and is purged on its own retention schedule; this
 * answers "what happened to this rental" and lives exactly as long as the
 * booking does. A manager needs the second without being handed the first.
 *
 * **Nothing written here is personal data.** A summary is about the booking
 * — a status, a date range, a total — never about the renter. That is why
 * this column is plain text while `rental_booking_comments.body` is an
 * encrypted BLOB: they are protected differently because they carry
 * different things, and the interface must keep it that way.
 */
class RentalBookingEventRepository
{
    public const STATUS_CHANGED = 'status_changed';
    public const HOLD_PLACED = 'hold_placed';
    public const HOLD_CLEARED = 'hold_cleared';
    public const PRICE_CHANGED = 'price_changed';
    public const DATES_CHANGED = 'dates_changed';
    public const CHANGE_REQUESTED = 'change_requested';
    public const CHANGE_DECIDED = 'change_decided';
    public const COMMENT_ADDED = 'comment_added';

    public function __construct(private \PDO $pdo)
    {
    }

    public function record(
        int $bookingId,
        string $eventType,
        ?string $fromValue = null,
        ?string $toValue = null,
        ?string $summary = null,
        ?int $actorMemberId = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_booking_events
                (booking_id, event_type, from_value, to_value, summary, actor_member_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $bookingId,
            $eventType,
            self::truncate($fromValue, 120),
            self::truncate($toValue, 120),
            self::truncate($summary, 255),
            $actorMemberId,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array{id: int, event_type: string, from_value: ?string, to_value: ?string, summary: ?string, actor_member_id: ?int, created_at: \DateTimeImmutable}>
     */
    public function findForBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_booking_events WHERE booking_id = ? ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$bookingId]);

        return array_map(
            static fn(array $row) => [
                'id' => (int) $row['id'],
                'event_type' => (string) $row['event_type'],
                'from_value' => $row['from_value'] !== null ? (string) $row['from_value'] : null,
                'to_value' => $row['to_value'] !== null ? (string) $row['to_value'] : null,
                'summary' => $row['summary'] !== null ? (string) $row['summary'] : null,
                'actor_member_id' => $row['actor_member_id'] !== null ? (int) $row['actor_member_id'] : null,
                'created_at' => new \DateTimeImmutable((string) $row['created_at']),
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Truncation rather than a database error on a long value: a history
     * entry is a convenience, and losing the tail of a summary is a far
     * better outcome than failing the status change it was recording.
     */
    private static function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
