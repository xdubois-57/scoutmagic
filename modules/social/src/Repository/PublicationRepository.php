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
    private const CLAIM_FENCE = ' WHERE source_kind = ? AND source_id = ? AND destination = ?'
        . ' AND status = ? AND attempted_at = ?';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string, Publication> keyed by destination
     */
    public function forSource(string $kind, int $sourceId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE source_kind = ? AND source_id = ?');
        $stmt->execute([$kind, $sourceId]);

        $publications = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $publications[(string) $row['destination']] = self::hydrate($row);
        }

        return $publications;
    }

    /**
     * The publications of the most recently tried contents — « Ce qui est
     * parti » — grouped by content, newest content first; each group keyed
     * by destination.
     *
     * @return list<array{kind: string, id: int, publications: array<string, Publication>}>
     */
    public function recentSources(int $limit): array
    {
        $sources = $this->pdo->prepare(
            'SELECT source_kind, source_id, MAX(attempted_at) AS last_attempt FROM social_publications'
            . ' GROUP BY source_kind, source_id ORDER BY last_attempt DESC, source_kind, source_id DESC'
            . ' LIMIT ?'
        );
        $sources->bindValue(1, max(1, $limit), \PDO::PARAM_INT);
        $sources->execute();
        $groups = [];
        foreach ($sources->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $kind = (string) $row['source_kind'];
            $id = (int) $row['source_id'];
            $groups[] = ['kind' => $kind, 'id' => $id, 'publications' => $this->forSource($kind, $id)];
        }

        return $groups;
    }

    private const SELECT = 'SELECT source_kind, source_id, destination, status, error_message, attempted_at,'
        . ' published_at, source_title, caption, remote_url, user_account_id, destination_label'
        . ' FROM social_publications';

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Publication
    {
        return new Publication(
            (string) $row['source_kind'],
            (int) $row['source_id'],
            (string) $row['destination'],
            (string) $row['status'],
            $row['error_message'] === null ? null : (string) $row['error_message'],
            DateInput::fromStorage((string) $row['attempted_at']) ?? new \DateTimeImmutable(),
            DateInput::fromStorage($row['published_at'] === null ? null : (string) $row['published_at']),
            (string) ($row['source_title'] ?? ''),
            (string) ($row['caption'] ?? ''),
            $row['remote_url'] === null ? null : (string) $row['remote_url'],
            $row['user_account_id'] === null ? null : (int) $row['user_account_id'],
            $row['destination_label'] === null ? null : (string) $row['destination_label']
        );
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
        \DateTimeImmutable $staleBefore,
        string $title = '',
        string $caption = '',
        ?string $destinationLabel = null
    ): bool {
        $stamp = $now->format('Y-m-d H:i:s');
        $title = mb_substr($title, 0, 200);

        try {
            $this->pdo->prepare(
                'INSERT INTO social_publications (source_kind, source_id, destination, status, attempted_at,'
                . ' user_account_id, source_title, caption, destination_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $kind,
                $sourceId,
                $destination,
                Publication::STATUS_PENDING,
                $stamp,
                $userId,
                $title,
                $caption,
                $destinationLabel === null ? null : mb_substr($destinationLabel, 0, 200),
            ]);

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
            'UPDATE social_publications SET status = ?, attempted_at = ?, error_message = NULL, user_account_id = ?,'
            . ' source_title = ?, caption = ?'
            . ' WHERE source_kind = ? AND source_id = ? AND destination = ?'
            . ' AND (status = ? OR (status = ? AND attempted_at <= ?))'
        );
        $stmt->execute([
            Publication::STATUS_PENDING,
            $stamp,
            $userId,
            $title,
            $caption,
            $kind,
            $sourceId,
            $destination,
            Publication::STATUS_FAILED,
            Publication::STATUS_PENDING,
            $staleBefore->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Both outcome writes are fenced on the claim that started the attempt
     * ($claimedAt, the `$now` given to claim()): a request that stalled
     * past the stale delay while a confirmed retry took the row over must
     * not overwrite the retry's result when it finally returns.
     */
    public function markPublished(
        string $kind,
        int $sourceId,
        string $destination,
        string $remoteId,
        \DateTimeImmutable $claimedAt,
        \DateTimeImmutable $now,
        ?string $remoteUrl = null
    ): void {
        $this->pdo->prepare(
            'UPDATE social_publications SET status = ?, remote_id = ?, remote_url = ?, published_at = ?,'
            . ' error_message = NULL' . self::CLAIM_FENCE
        )->execute([
            Publication::STATUS_PUBLISHED,
            mb_substr($remoteId, 0, 100),
            $remoteUrl === null ? null : mb_substr($remoteUrl, 0, 500),
            $now->format('Y-m-d H:i:s'),
            $kind,
            $sourceId,
            $destination,
            Publication::STATUS_PENDING,
            $claimedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function markFailed(
        string $kind,
        int $sourceId,
        string $destination,
        string $message,
        \DateTimeImmutable $claimedAt
    ): void {
        $this->pdo->prepare('UPDATE social_publications SET status = ?, error_message = ?' . self::CLAIM_FENCE)
            ->execute([
                Publication::STATUS_FAILED,
                mb_substr($message, 0, 500),
                $kind,
                $sourceId,
                $destination,
                Publication::STATUS_PENDING,
                $claimedAt->format('Y-m-d H:i:s'),
            ]);
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
