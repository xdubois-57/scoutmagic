<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Repository;

use Core\Service\DateInput;

/**
 * `social_publications`, and the « once per destination » rule as a claim:
 * a destination is taken by the one request that manages to write its row
 * as `pending`, and by no other.
 */
class PublicationRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, Publication> keyed by destination
     */
    public function forSource(string $kind, int $sourceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT source_kind, source_id, destination, status, error_message, attempted_at, published_at'
            . ' FROM social_publications WHERE source_kind = ? AND source_id = ?'
        );
        $stmt->execute([$kind, $sourceId]);

        $publications = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $publications[(string) $row['destination']] = new Publication(
                (string) $row['source_kind'],
                (int) $row['source_id'],
                (string) $row['destination'],
                (string) $row['status'],
                $row['error_message'] === null ? null : (string) $row['error_message'],
                DateInput::fromStorage((string) $row['attempted_at']) ?? new \DateTimeImmutable(),
                DateInput::fromStorage($row['published_at'] === null ? null : (string) $row['published_at'])
            );
        }

        return $publications;
    }

    /**
     * Takes a destination for this request, or refuses.
     *
     * - No row yet: the INSERT takes it, and a concurrent INSERT loses on
     *   the unique key.
     * - A row that failed, or was left pending before `$staleBefore`: taken
     *   again only when `$retry` says the person confirmed it, by an UPDATE
     *   whose WHERE is the condition itself — two retries racing, one wins.
     * - A published row, or one pending right now: refused.
     */
    public function claim(
        string $kind,
        int $sourceId,
        string $destination,
        bool $retry,
        ?int $userId,
        \DateTimeImmutable $now,
        \DateTimeImmutable $staleBefore
    ): bool {
        $stamp = $now->format('Y-m-d H:i:s');

        try {
            $this->pdo->prepare(
                'INSERT INTO social_publications (source_kind, source_id, destination, status, attempted_at,'
                . ' user_account_id) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$kind, $sourceId, $destination, Publication::STATUS_PENDING, $stamp, $userId]);

            return true;
        } catch (\PDOException $e) {
            if (!self::isDuplicate($e)) {
                throw $e;
            }
        }

        if (!$retry) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE social_publications SET status = ?, attempted_at = ?, error_message = NULL, user_account_id = ?'
            . ' WHERE source_kind = ? AND source_id = ? AND destination = ?'
            . ' AND (status = ? OR (status = ? AND attempted_at <= ?))'
        );
        $stmt->execute([
            Publication::STATUS_PENDING,
            $stamp,
            $userId,
            $kind,
            $sourceId,
            $destination,
            Publication::STATUS_FAILED,
            Publication::STATUS_PENDING,
            $staleBefore->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markPublished(
        string $kind,
        int $sourceId,
        string $destination,
        string $remoteId,
        \DateTimeImmutable $now
    ): void {
        $this->pdo->prepare(
            'UPDATE social_publications SET status = ?, remote_id = ?, published_at = ?, error_message = NULL'
            . ' WHERE source_kind = ? AND source_id = ? AND destination = ?'
        )->execute([
            Publication::STATUS_PUBLISHED,
            mb_substr($remoteId, 0, 100),
            $now->format('Y-m-d H:i:s'),
            $kind,
            $sourceId,
            $destination,
        ]);
    }

    public function markFailed(string $kind, int $sourceId, string $destination, string $message): void
    {
        $this->pdo->prepare(
            'UPDATE social_publications SET status = ?, error_message = ?'
            . ' WHERE source_kind = ? AND source_id = ? AND destination = ?'
        )->execute([Publication::STATUS_FAILED, mb_substr($message, 0, 500), $kind, $sourceId, $destination]);
    }

    /**
     * A unique-key violation, told apart by driver code rather than by
     * SQLSTATE 23000, which also covers a NOT NULL this code forgot
     * (Core\Database\ConstraintViolation): 1062 on MariaDB/MySQL, 19 with
     * « UNIQUE » on SQLite.
     */
    private static function isDuplicate(\PDOException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $driverCode === 1062 || ($driverCode === 19 && str_contains($e->getMessage(), 'UNIQUE'));
    }
}
