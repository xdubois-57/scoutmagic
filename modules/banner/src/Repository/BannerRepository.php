<?php

declare(strict_types=1);

namespace Modules\Banner\Repository;

class BannerRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return Banner[]
     */
    public function findAllOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM banners ORDER BY sort_order ASC, id ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return Banner[]
     */
    public function findActiveOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM banners WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findById(int $id): ?Banner
    {
        $stmt = $this->pdo->prepare('SELECT * FROM banners WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Appends a new banner at the end of the current order.
     */
    public function create(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(sort_order), -1) FROM banners');
        $nextOrder = ((int) ($stmt !== false ? $stmt->fetchColumn() : -1)) + 1;

        $stmt = $this->pdo->prepare('INSERT INTO banners (is_active, sort_order) VALUES (1, ?)');
        $stmt->execute([$nextOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE banners SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM banners WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Persists a new order — $orderedIds is the complete list of banner
     * ids in their new display order (index = new sort_order).
     *
     * @param int[] $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE banners SET sort_order = ? WHERE id = ?');
        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute([$position, $id]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Banner
    {
        return new Banner(
            id: (int) $row['id'],
            isActive: (bool) $row['is_active'],
            sortOrder: (int) $row['sort_order']
        );
    }
}
