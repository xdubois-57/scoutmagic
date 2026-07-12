<?php

declare(strict_types=1);

namespace Core\Journal;

class JournalRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function insert(
        string $category,
        string $type,
        string $level,
        string $description,
        ?string $contextJson,
        ?int $userId
    ): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO event_log (logged_at, user_account_id, category, event_type, level, description, context)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$now, $userId, $category, $type, $level, $description, $contextJson]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(
        ?string $category = null,
        ?string $level = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $where = [];
        $params = [];
        $this->buildFilters($category, $level, $search, $dateFrom, $dateTo, $where, $params);

        $sql = 'SELECT * FROM event_log'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY logged_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function count(
        ?string $category = null,
        ?string $level = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): int {
        $where = [];
        $params = [];
        $this->buildFilters($category, $level, $search, $dateFrom, $dateTo, $where, $params);

        $sql = 'SELECT COUNT(*) FROM event_log'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function deleteOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('DELETE FROM event_log WHERE logged_at < ?');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    /**
     * @return array<int, string>
     */
    public function getDistinctCategories(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT category FROM event_log ORDER BY category');
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * @param array<int, string> $where
     * @param array<int, mixed> $params
     */
    private function buildFilters(
        ?string $category,
        ?string $level,
        ?string $search,
        ?string $dateFrom,
        ?string $dateTo,
        array &$where,
        array &$params
    ): void {
        if ($category !== null && $category !== '') {
            $where[] = 'category = ?';
            $params[] = $category;
        }
        if ($level !== null && $level !== '') {
            $where[] = 'level = ?';
            $params[] = $level;
        }
        if ($search !== null && $search !== '') {
            $where[] = 'description LIKE ?';
            $params[] = '%' . $search . '%';
        }
        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'logged_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'logged_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
    }
}
