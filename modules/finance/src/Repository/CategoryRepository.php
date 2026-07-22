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

    /**
     * $accountId is set only by Service\AccountTransferCategoryService,
     * for the one auto-generated "Virement <compte>" category it keeps in
     * sync with an active account — never for a default/custom category
     * an admin creates by hand. $isDefault is set only by
     * Service\FinanceService::ensureDefaultCategories()/
     * resetDefaultCategories(), for the config page's "Par défaut" badge.
     */
    public function create(string $name, string $description = '', ?int $accountId = null, bool $isDefault = false): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(sort_order), -1) FROM finance_categories');
        $nextOrder = ((int) ($stmt !== false ? $stmt->fetchColumn() : -1)) + 1;

        $stmt = $this->pdo->prepare('INSERT INTO finance_categories (name, description, sort_order, account_id, is_default) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $description, $nextOrder, $accountId, $isDefault ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $description): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_categories SET name = ?, description = ? WHERE id = ?');
        $stmt->execute([$name, $description, $id]);
    }

    /**
     * Used only by Service\FinanceService's one-time backfill for a
     * default category that already existed before description/is_default
     * were introduced — sets both at once rather than overloading
     * update(), which would otherwise need a $name an admin never asked
     * to change.
     */
    public function backfillDefaultMetadata(int $id, string $description): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_categories SET description = ?, is_default = 1 WHERE id = ?');
        $stmt->execute([$description, $id]);
    }

    /**
     * Finds the auto-generated "Virement <compte>" category for an
     * account, if Service\AccountTransferCategoryService has created one
     * yet — used to update-in-place (name change, IBAN change) rather
     * than ever matching by name, which is free text an admin can rename.
     */
    public function findByAccountId(int $accountId): ?Category
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_categories WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
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
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Category
    {
        return new Category(
            id: (int) $row['id'],
            name: (string) $row['name'],
            description: (string) $row['description'],
            isActive: (bool) $row['is_active'],
            sortOrder: (int) $row['sort_order'],
            accountId: $row['account_id'] !== null ? (int) $row['account_id'] : null,
            isDefault: (bool) $row['is_default']
        );
    }
}
