<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;

/**
 * Internal comments on a booking (§6.4, §6.6).
 *
 * **The renter's tracking page does not read this table at all.** That is a
 * stronger guarantee than a template remembering to hide the comments: the
 * data never reaches the renderer, so there is nothing to forget.
 *
 * Encrypted at rest even though managers write it. A comment about a
 * booking is, in practice, a comment about the people making it, so it gets
 * the same protection as the renter's own fields rather than a weaker one.
 */
class RentalBookingCommentRepository
{
    private const CTX_BODY = 'rental_booking_comments.body';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function create(int $bookingId, ?int $authorMemberId, string $body): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_booking_comments (booking_id, author_member_id, body_encrypted, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $bookingId,
            $authorMemberId,
            $this->encryption->encrypt($body, self::CTX_BODY),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array{id: int, author_member_id: ?int, body: string, created_at: \DateTimeImmutable}>
     */
    public function findForBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_booking_comments WHERE booking_id = ? ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$bookingId]);

        return array_map(
            fn(array $row) => [
                'id' => (int) $row['id'],
                'author_member_id' => $row['author_member_id'] !== null ? (int) $row['author_member_id'] : null,
                'body' => $this->encryption->decrypt((string) $row['body_encrypted'], self::CTX_BODY),
                'created_at' => DateInput::requireFromStorage((string) $row['created_at'], 'created_at'),
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function countForBooking(int $bookingId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rental_booking_comments WHERE booking_id = ?');
        $stmt->execute([$bookingId]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rental_booking_comments WHERE id = ?');
        $stmt->execute([$id]);
    }
}
