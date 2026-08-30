<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Repository;

use Core\Config\AppClock;
use Core\Database\Connection;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\BatchStatus;

/**
 * `attestation_batches` — the deposited files themselves. Their lines live
 * in BatchLineRepository.
 *
 * Timestamps are written from PHP and bound as parameters rather than left
 * to the column default: the test suite runs on SQLite, whose
 * CURRENT_TIMESTAMP is UTC while everything else here is Europe/Brussels
 * (docs/module-development.md § Timestamps). The DEFAULT stays in
 * schema.sql as a safety net for hand-written SQL; nothing relies on it.
 */
class BatchRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * A batch as deposited: split, counted, and waiting for the human
     * check. It is created in `draft` — there is no way to insert one that
     * is already published, because publishing is what creates the
     * documents and that is a second step by design.
     */
    public function create(
        int $scoutYearId,
        AttestationCategory $category,
        string $label,
        int $pageCount,
        int $pagesPerDocument,
        int $documentCount,
        ?int $createdBy
    ): int {
        $stmt = $this->connection->getPdo()->prepare(
            'INSERT INTO attestation_batches
                 (scout_year_id, category, label, status, page_count, pages_per_document,
                  document_count, discarded_count, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([
            $scoutYearId,
            $category->value,
            $label,
            BatchStatus::Draft->value,
            $pageCount,
            $pagesPerDocument,
            $documentCount,
            AppClock::now()->format('Y-m-d H:i:s'),
            $createdBy,
        ]);

        return (int) $this->connection->getPdo()->lastInsertId();
    }

    public function findById(int $id): ?Batch
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT * FROM attestation_batches WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::mapRow($row);
    }

    /**
     * Most recently deposited first — which is what the page is for: the
     * batch somebody is working on is the one they just deposited.
     *
     * @return list<Batch>
     */
    public function findRecent(int $limit = 20): array
    {
        $stmt = $this->connection->getPdo()->prepare(
            'SELECT * FROM attestation_batches ORDER BY created_at DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(self::mapRow(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Record what the reader kept and what they left aside.
     *
     * `discarded_count` is the answer to « pourquoi 43 attestations pour 55
     * membres ? » six months later. A counter, never a list of names: the
     * discarded lines are deleted, and keeping who they were would be
     * keeping personal data for a question that does not need it.
     */
    public function recordSelection(int $batchId, int $documentCount, int $discardedCount): void
    {
        $stmt = $this->connection->getPdo()->prepare(
            'UPDATE attestation_batches SET document_count = ?, discarded_count = ? WHERE id = ?'
        );
        $stmt->execute([$documentCount, $discardedCount, $batchId]);
    }

    /**
     * The documents are on the members' pages. From here the batch is
     * read-only: `owner_member_id` makes every certificate unreadable by
     * the staff who published it, so there is nothing left to check and
     * nothing left to change — only to take back in full (see the reset).
     */
    public function markPublished(int $batchId): void
    {
        $stmt = $this->connection->getPdo()->prepare(
            'UPDATE attestation_batches SET status = ?, published_at = ? WHERE id = ?'
        );
        $stmt->execute([BatchStatus::Published->value, AppClock::now()->format('Y-m-d H:i:s'), $batchId]);
    }

    /**
     * The send was asked for. Stamped when the chef d'unité presses, not
     * when the last message leaves: the screen has to stop saying
     * « familles non prévenues » the moment the gesture is made, or
     * somebody presses again.
     */
    public function markDistributionStarted(int $batchId): void
    {
        $stmt = $this->connection->getPdo()->prepare(
            'UPDATE attestation_batches SET distribution_started_at = ? WHERE id = ? AND distribution_started_at IS NULL'
        );
        $stmt->execute([AppClock::now()->format('Y-m-d H:i:s'), $batchId]);
    }

    /** Every line settled and the one notification sent. */
    public function markNotified(int $batchId): void
    {
        $stmt = $this->connection->getPdo()->prepare(
            'UPDATE attestation_batches SET notified_at = ? WHERE id = ? AND notified_at IS NULL'
        );
        $stmt->execute([AppClock::now()->format('Y-m-d H:i:s'), $batchId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function mapRow(array $row): Batch
    {
        return new Batch(
            id: (int) $row['id'],
            scoutYearId: (int) $row['scout_year_id'],
            // A stored value naming no known category would be a row this
            // code never wrote; reading it as Other keeps the page
            // rendering rather than fataling on somebody else's data.
            category: AttestationCategory::tryFromValue((string) $row['category']) ?? AttestationCategory::Other,
            label: (string) $row['label'],
            status: BatchStatus::tryFromValue((string) $row['status']) ?? BatchStatus::Draft,
            pageCount: (int) $row['page_count'],
            pagesPerDocument: (int) $row['pages_per_document'],
            documentCount: (int) $row['document_count'],
            discardedCount: (int) $row['discarded_count'],
            createdAt: (string) $row['created_at'],
            publishedAt: $row['published_at'] !== null ? (string) $row['published_at'] : null,
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null,
            distributionStartedAt: ($row['distribution_started_at'] ?? null) !== null
                ? (string) $row['distribution_started_at']
                : null,
            notifiedAt: ($row['notified_at'] ?? null) !== null ? (string) $row['notified_at'] : null
        );
    }
}
