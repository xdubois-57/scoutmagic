<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Repository;

class CommentRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $boardId, string $columnKey, string $body): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO retro_comments (board_id, column_key, body) VALUES (?, ?, ?)');
        $stmt->execute([$boardId, $columnKey, $body]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Comment
    {
        $stmt = $this->pdo->prepare('SELECT * FROM retro_comments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Ordered oldest-first — Service\CommentService decides display order
     * (by votes once results are revealed) since that depends on
     * board-level state this repository has no notion of.
     *
     * @return Comment[]
     */
    public function findByBoardId(int $boardId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM retro_comments WHERE board_id = ? ORDER BY created_at, id');
        $stmt->execute([$boardId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function setHidden(int $id, bool $hidden): void
    {
        $stmt = $this->pdo->prepare('UPDATE retro_comments SET hidden = ? WHERE id = ?');
        $stmt->execute([$hidden ? 1 : 0, $id]);
    }

    public function setVotes(int $id, int $votes): void
    {
        $stmt = $this->pdo->prepare('UPDATE retro_comments SET votes = ? WHERE id = ?');
        $stmt->execute([$votes, $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Comment
    {
        return new Comment(
            id: (int) $row['id'],
            boardId: (int) $row['board_id'],
            columnKey: (string) $row['column_key'],
            body: (string) $row['body'],
            votes: (int) $row['votes'],
            hidden: (bool) $row['hidden'],
            createdAt: (string) $row['created_at']
        );
    }
}
