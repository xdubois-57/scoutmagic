<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

/**
 * A rolling log of "no existing category fit" names Service\
 * AiCategorizationService has suggested — see schema.sql's comment on
 * finance_ai_category_suggestions. create() prunes down to the 10 most
 * recent on every insert, so the table never grows unbounded and callers
 * never need to prune themselves.
 */
class AiCategorySuggestionRepository
{
    private const MAX_RECENT = 10;

    public function __construct(private \PDO $pdo)
    {
    }

    public function create(string $suggestedName): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO finance_ai_category_suggestions (suggested_name) VALUES (?)');
        $stmt->execute([$suggestedName]);
        $this->pruneBeyondMostRecent();
    }

    /**
     * @return string[]
     */
    public function findRecent(int $limit = self::MAX_RECENT): array
    {
        $stmt = $this->pdo->prepare('SELECT suggested_name FROM finance_ai_category_suggestions ORDER BY created_at DESC, id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function pruneBeyondMostRecent(): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM finance_ai_category_suggestions ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET ' . self::MAX_RECENT
        );
        $stmt->execute();
        $cutoffId = $stmt->fetchColumn();
        if ($cutoffId === false) {
            return;
        }

        $this->pdo->prepare('DELETE FROM finance_ai_category_suggestions WHERE id <= ?')->execute([$cutoffId]);
    }
}
