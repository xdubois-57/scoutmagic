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
        $stmt = $this->pdo->prepare(
            'INSERT INTO age_branches (desk_code, label) VALUES (?, ?)'
        );
        $stmt->execute([$deskCode, $label]);
        return (int) $this->pdo->lastInsertId();
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
