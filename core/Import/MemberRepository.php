<?php

declare(strict_types=1);

namespace Core\Import;

class MemberRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{id: int, desk_id: string}|null
     */
    public function findByDeskId(string $deskId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, desk_id FROM members WHERE desk_id = ?');
        $stmt->execute([$deskId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'desk_id' => (string) $row['desk_id'],
        ];
    }

    /**
     * Find or create a member by desk_id. Returns the member ID.
     */
    public function upsertByDeskId(string $deskId): int
    {
        $existing = $this->findByDeskId($deskId);
        if ($existing !== null) {
            return $existing['id'];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id, created_at) VALUES (?, ?)');
        $stmt->execute([$deskId, $now]);
        return (int) $this->pdo->lastInsertId();
    }
}
