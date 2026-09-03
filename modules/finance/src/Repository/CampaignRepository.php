<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

/**
 * finance_campaigns. Nothing on a campaign itself is personal data — a
 * label, a season, an account, a state — so nothing here is encrypted;
 * the people are in finance_campaign_rows, which is where the encryption
 * lives.
 */
class CampaignRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param string[] $mergeColumns
     */
    public function create(
        string $label,
        int $scoutYearId,
        int $accountId,
        ?int $sourceFileId,
        string $sourceFilename,
        array $mergeColumns,
        ?int $createdBy
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_campaigns (label, scout_year_id, account_id, status, source_file_id, source_filename, merge_columns, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $label,
            $scoutYearId,
            $accountId,
            Campaign::STATUS_OPEN,
            $sourceFileId,
            $sourceFilename,
            json_encode(array_values($mergeColumns), JSON_UNESCAPED_UNICODE) ?: '[]',
            $createdBy,
            date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Campaign
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_campaigns WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return Campaign[] newest first
     */
    public function findByScoutYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_campaigns WHERE scout_year_id = ? ORDER BY id DESC');
        $stmt->execute([$scoutYearId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return Campaign[] newest first
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM finance_campaigns ORDER BY id DESC');

        return $stmt !== false ? array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)) : [];
    }

    /**
     * Scout year ids that carry at least one campaign — the year picker
     * on the list page, which offers only the years there is something to
     * see in.
     *
     * @return int[]
     */
    public function findDistinctScoutYearIds(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT scout_year_id FROM finance_campaigns');

        return $stmt !== false ? array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)) : [];
    }

    public function setStatus(int $id, string $status, ?string $closedAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_campaigns SET status = ?, closed_at = ? WHERE id = ?');
        $stmt->execute([$status, $closedAt, $id]);
    }

    public function markNotified(int $id, string $at, ?int $by): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_campaigns SET notified_at = ?, notified_by = ? WHERE id = ?');
        $stmt->execute([$at, $by, $id]);
    }

    /**
     * Forgets the source spreadsheet once its retention window has
     * closed. The campaign itself, its rows and its receivables all stay
     * — they are the financial history; the file was only ever there to
     * answer "where did this amount come from".
     */
    public function forgetSourceFile(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_campaigns SET source_file_id = NULL, merge_columns = NULL WHERE '
            . 'id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Campaign
    {
        $columns = [];
        if (isset($row['merge_columns']) && is_string($row['merge_columns']) && $row['merge_columns'] !== '') {
            $decoded = json_decode($row['merge_columns'], true);
            if (is_array($decoded)) {
                $columns = array_values(array_map('strval', $decoded));
            }
        }

        return new Campaign(
            id: (int) $row['id'],
            label: (string) $row['label'],
            scoutYearId: (int) $row['scout_year_id'],
            accountId: (int) $row['account_id'],
            status: (string) $row['status'],
            sourceFileId: isset($row['source_file_id']) ? (int) $row['source_file_id'] : null,
            sourceFilename: (string) ($row['source_filename'] ?? ''),
            mergeColumns: $columns,
            notifiedAt: isset($row['notified_at']) ? (string) $row['notified_at'] : null,
            notifiedBy: isset($row['notified_by']) ? (int) $row['notified_by'] : null,
            closedAt: isset($row['closed_at']) ? (string) $row['closed_at'] : null,
            createdBy: isset($row['created_by']) ? (int) $row['created_by'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
