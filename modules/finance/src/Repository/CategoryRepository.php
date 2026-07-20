<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

class CategoryRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return Category[]
     */
    public function findAllOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM finance_categories ORDER BY sort_order ASC, id ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return Category[]
     */
    public function findActiveOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM finance_categories WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findById(int $id): ?Category
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_categories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(string $name): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(sort_order), -1) FROM finance_categories');
        $nextOrder = ((int) ($stmt !== false ? $stmt->fetchColumn() : -1)) + 1;

        $stmt = $this->pdo->prepare('INSERT INTO finance_categories (name, sort_order) VALUES (?, ?)');
        $stmt->execute([$name, $nextOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateName(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_categories SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_categories SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_categories WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Whether any transaction (any status) currently references this
     * category — used to decide whether deletion is even offered, same
     * "used elsewhere, deactivate instead" pattern as Core\Badge.
     */
    public function isReferencedByTransactions(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM finance_transactions WHERE category_id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Category
    {
        return new Category(
            id: (int) $row['id'],
            name: (string) $row['name'],
            isActive: (bool) $row['is_active'],
            sortOrder: (int) $row['sort_order']
        );
    }
}
