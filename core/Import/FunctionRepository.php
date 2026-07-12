<?php

declare(strict_types=1);

namespace Core\Import;

class FunctionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{id: int, desk_code: string, label: string, role: string, confirmed: bool}|null
     */
    public function findByDeskCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM functions WHERE desk_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(string $deskCode, string $label, string $role, bool $confirmed): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$deskCode, $label, $role, $confirmed ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array{id: int, desk_code: string, label: string, role: string, confirmed: bool}>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM functions ORDER BY label');
        if ($stmt === false) {
            return [];
        }
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function updateRole(int $id, string $role, bool $confirmed): void
    {
        $stmt = $this->pdo->prepare('UPDATE functions SET role = ?, confirmed = ? WHERE id = ?');
        $stmt->execute([$role, $confirmed ? 1 : 0, $id]);
    }

    /**
     * @return array<int, array{id: int, desk_code: string, label: string, role: string, confirmed: bool}>
     */
    public function findUnconfirmed(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM functions WHERE confirmed = 0 ORDER BY label');
        $stmt->execute();
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, desk_code: string, label: string, role: string, confirmed: bool}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'desk_code' => (string) $row['desk_code'],
            'label' => (string) $row['label'],
            'role' => (string) $row['role'],
            'confirmed' => (bool) $row['confirmed'],
        ];
    }
}
