<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

/**
 * How far each person has read each booking's mail (#720,
 * `rental_booking_mail_reads`): a position in inbound_mail's associations,
 * per booking and account. Ids only — nothing personal lives here.
 */
class RentalMailReadRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Records that this account has read the booking's mail up to
     * `$position`. Written as select-then-write so it behaves the same on
     * MySQL and on the SQLite test database; never moves backwards, so an
     * older page left open and reloaded cannot bring a badge back.
     */
    public function markRead(int $bookingId, int $userAccountId, int $position): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $current = $this->readUpTo($userAccountId, [$bookingId]);

        if (!array_key_exists($bookingId, $current)) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rental_booking_mail_reads (booking_id, user_account_id, read_up_to, updated_at)
                 VALUES (?, ?, ?, ?)'
            );
            try {
                $stmt->execute([$bookingId, $userAccountId, $position, $now]);

                return;
            } catch (\PDOException $e) {
                // The same person on two tabs: the other one wrote the row
                // first, and moving it forward below is what was asked.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE rental_booking_mail_reads SET read_up_to = ?, updated_at = ?
              WHERE booking_id = ? AND user_account_id = ? AND read_up_to < ?'
        );
        $stmt->execute([$position, $now, $bookingId, $userAccountId, $position]);
    }

    /**
     * The position each of these bookings was read up to by this account;
     * a booking never opened is absent.
     *
     * @param int[] $bookingIds
     * @return array<int, int> booking id => position
     */
    public function readUpTo(int $userAccountId, array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT booking_id, read_up_to FROM rental_booking_mail_reads
              WHERE user_account_id = ? AND booking_id IN (' . $placeholders . ')'
        );
        $stmt->execute([$userAccountId, ...array_map('intval', $bookingIds)]);

        $positions = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $positions[(int) $row['booking_id']] = (int) $row['read_up_to'];
        }

        return $positions;
    }
}
