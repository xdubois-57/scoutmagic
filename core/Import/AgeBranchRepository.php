<?php

declare(strict_types=1);

namespace Core\Import;

class AgeBranchRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{id: int, desk_code: string, label: string, sort_order: int}|null
     */
    public function findByDeskCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM age_branches WHERE desk_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'desk_code' => (string) $row['desk_code'],
            'label' => (string) $row['label'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    public function create(string $deskCode, string $label): int
    {
        $sortOrder = self::canonicalSortOrder($label);
        $stmt = $this->pdo->prepare(
            'INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)'
        );
        $stmt->execute([$deskCode, $label, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Return canonical sort order for known Les Scouts branches.
     * Order: Baladins → Louveteaux → Éclaireurs → Pionniers → Staff d'U → Route → Iama.
     * Unknown branches get sort_order 99 (displayed last).
     */
    public static function canonicalSortOrder(string $label): int
    {
        $normalized = mb_strtolower(trim($label));
        return match (true) {
            str_contains($normalized, 'baladin') => 10,
            str_contains($normalized, 'louveteau') => 20,
            str_contains($normalized, 'eclaireur'), str_contains($normalized, 'éclaireur') => 30,
            str_contains($normalized, 'pionnier') => 40,
            str_contains($normalized, 'staff'), str_contains($normalized, 'unité') => 50,
            str_contains($normalized, 'route'), str_contains($normalized, 'routier') => 60,
            str_contains($normalized, 'iama') => 70,
            default => 99,
        };
    }

    public function updateSortOrder(int $id, int $sortOrder): void
    {
        $stmt = $this->pdo->prepare('UPDATE age_branches SET sort_order = ? WHERE id = ?');
        $stmt->execute([$sortOrder, $id]);
    }

    /**
     * @return array<int, array{id: int, desk_code: string, label: string, sort_order: int}>
     */
    public function findAllOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM age_branches ORDER BY sort_order, label');
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => [
            'id' => (int) $row['id'],
            'desk_code' => (string) $row['desk_code'],
            'label' => (string) $row['label'],
            'sort_order' => (int) $row['sort_order'],
        ], $rows);
    }
}
