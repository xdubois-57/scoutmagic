<?php

declare(strict_types=1);

namespace Core\Import;

class ImportJournalRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $scoutYearId, int $userId, int $lineCount, int $memberCount, int $newFunctions): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, user_account_id, line_count, member_count, new_functions_count, imported_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$scoutYearId, $userId, $lineCount, $memberCount, $newFunctions, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByYear(int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM import_journal WHERE scout_year_id = ? ORDER BY imported_at DESC'
        );
        $stmt->execute([$scoutYearId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
