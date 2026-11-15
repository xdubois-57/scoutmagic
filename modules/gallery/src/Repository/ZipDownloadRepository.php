<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Repository;

/**
 * gallery_zip_downloads — the three operations of every rate-limit table
 * in this tree (Modules\Groups\Repository\RateLimitRepository): record one,
 * count since an instant, delete before an instant.
 *
 * Every instant is passed in by the caller, already formatted on the
 * application clock ('Y-m-d H:i:s', Core\Config\AppClock): nothing here
 * reads the server's NOW() or leans on a column default, so the row and
 * the window it is compared with can never come from two different clocks.
 */
class ZipDownloadRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function record(int $userAccountId, string $at): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO gallery_zip_downloads (user_account_id, created_at) VALUES (?, ?)'
        );
        $stmt->execute([$userAccountId, $at]);
    }

    /**
     * Number of ZIPs this account started at or after $sinceDatetime.
     */
    public function countSince(int $userAccountId, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM gallery_zip_downloads WHERE user_account_id = ? AND created_at >= ?'
        );
        $stmt->execute([$userAccountId, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes every row older than $beforeDatetime — Task\
     * PurgeZipDownloadsHandler's daily sweep. Returns how many went.
     */
    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM gallery_zip_downloads WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
