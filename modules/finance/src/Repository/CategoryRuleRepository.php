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

    public function create(
        int $categoryId,
        int $priority,
        ?string $keywordPattern,
        ?string $counterpartyAccountPattern,
        ?string $amountRange,
        bool $isSystem = false,
        bool $isDefault = false
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_category_rules (category_id, priority, keyword_pattern, counterparty_account_pattern, amount_range, is_system, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$categoryId, $priority, $keywordPattern, $counterpartyAccountPattern, $amountRange, $isSystem ? 1 : 0, $isDefault ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        int $categoryId,
        ?string $keywordPattern,
        ?string $counterpartyAccountPattern,
        ?string $amountRange
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE finance_category_rules SET category_id = ?, keyword_pattern = ?, counterparty_account_pattern = ?, amount_range = ? WHERE id = ?'
        );
        $stmt->execute([$categoryId, $keywordPattern, $counterpartyAccountPattern, $amountRange, $id]);
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
     * Used by Service\FinanceService::deleteCategory() — a rule with no
     * category left to assign is meaningless, so it's deleted along with
     * the category rather than left dangling (done explicitly here rather
     * than relying on the schema's ON DELETE CASCADE, so behavior doesn't
     * depend on the underlying database engine actually enforcing it).
     */
    public function deleteAllForCategory(int $categoryId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_category_rules WHERE category_id = ?');
        $stmt->execute([$categoryId]);
    }

    /**
     * The one system rule Service\AccountTransferCategoryService keeps in
     * sync for a given account's auto-generated category, if it's created
     * one yet.
     */
    public function findSystemRuleForCategory(int $categoryId): ?CategoryRule
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_category_rules WHERE category_id = ? AND is_system = 1 LIMIT 1');
        $stmt->execute([$categoryId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
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
     * Used by Service\FinanceService::resetDefaultCategoryRules() — wipes
     * every rule it seeded (is_default = 1) so it can recreate them fresh
     * from DEFAULT_CATEGORY_RULE_PATTERNS, undoing any admin edits/
     * deletions to that specific set. Never touches an admin's own custom
     * rules or a Service\AccountTransferCategoryService system rule.
     */
    public function deleteAllDefault(): void
    {
        $this->pdo->exec('DELETE FROM finance_category_rules WHERE is_default = 1');
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
            keywordPattern: $row['keyword_pattern'] !== null ? (string) $row['keyword_pattern'] : null,
            counterpartyAccountPattern: $row['counterparty_account_pattern'] !== null ? (string) $row['counterparty_account_pattern'] : null,
            amountRange: $row['amount_range'] !== null ? (string) $row['amount_range'] : null,
            isActive: (bool) $row['is_active'],
            isSystem: (bool) $row['is_system'],
            isDefault: (bool) $row['is_default']
        );
    }
}
