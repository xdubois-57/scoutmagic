<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * registration_confirmation_sends — the three operations of every
 * rate-limit table in this tree (Modules\Gallery\Repository\
 * ZipDownloadRepository): record one, count since an instant, delete
 * before an instant.
 *
 * Every instant is passed in by the caller, already formatted on the
 * application clock ('Y-m-d H:i:s', Core\Config\AppClock): nothing here
 * reads the server's NOW() or leans on a column default, so the row and
 * the window it is compared with can never come from two different clocks.
 */
class ConfirmationSendRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function record(int $requestId, string $at): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_confirmation_sends (registration_request_id, created_at) VALUES (?, ?)'
        );
        $stmt->execute([$requestId, $at]);
    }

    /**
     * Number of confirmation e-mails sent for this request at or after
     * $sinceDatetime.
     */
    public function countSince(int $requestId, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM registration_confirmation_sends WHERE registration_request_id = ? AND created_at >= ?'
        );
        $stmt->execute([$requestId, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes every row older than $beforeDatetime — Task\
     * PurgeConfirmationSendsHandler's daily sweep. Returns how many went.
     */
    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM registration_confirmation_sends WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
