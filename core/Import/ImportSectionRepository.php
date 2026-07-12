<?php

declare(strict_types=1);

namespace Core\Import;

class ImportSectionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{id: int, desk_code: string, age_branch_id: int, name: ?string}|null
     */
    public function findByDeskCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sections WHERE desk_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'desk_code' => (string) $row['desk_code'],
            'age_branch_id' => (int) $row['age_branch_id'],
            'name' => $row['name'] !== null ? (string) $row['name'] : null,
        ];
    }

    public function create(string $deskCode, int $branchId, ?string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateInfo(int $id, ?string $name, ?string $email): void
    {
        $stmt = $this->pdo->prepare('UPDATE sections SET name = ?, email = ? WHERE id = ?');
        $stmt->execute([$name, $email, $id]);
    }
}
