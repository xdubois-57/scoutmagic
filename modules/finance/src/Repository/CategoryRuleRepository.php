<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

class CategoryRuleRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return CategoryRule[]
     */
    public function findAllOrderedByPriority(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM finance_category_rules ORDER BY priority ASC, id ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return CategoryRule[]
     */
    public function findActiveOrderedByPriority(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM finance_category_rules WHERE is_active = 1 ORDER BY priority ASC, id ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findById(int $id): ?CategoryRule
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_category_rules WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(int $categoryId, int $priority, string $conditionType, string $conditionValue): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_category_rules (category_id, priority, condition_type, condition_value) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$categoryId, $priority, $conditionType, $conditionValue]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, int $categoryId, string $conditionType, string $conditionValue): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE finance_category_rules SET category_id = ?, condition_type = ?, condition_value = ? WHERE id = ?'
        );
        $stmt->execute([$categoryId, $conditionType, $conditionValue, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_category_rules SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_category_rules WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Persists a new priority order — $orderedIds is the complete list of
     * rule ids in their new evaluation order (index = new priority).
     *
     * @param int[] $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_category_rules SET priority = ? WHERE id = ?');
        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute([$position, $id]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CategoryRule
    {
        return new CategoryRule(
            id: (int) $row['id'],
            categoryId: (int) $row['category_id'],
            priority: (int) $row['priority'],
            conditionType: (string) $row['condition_type'],
            conditionValue: (string) $row['condition_value'],
            isActive: (bool) $row['is_active']
        );
    }
}
