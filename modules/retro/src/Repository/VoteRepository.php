<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Repository;

class VoteRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findByCommentAndVoter(int $commentId, string $voterHash): ?Vote
    {
        $stmt = $this->pdo->prepare('SELECT * FROM retro_votes WHERE comment_id = ? AND voter_hash = ?');
        $stmt->execute([$commentId, $voterHash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(int $commentId, int $boardId, string $voterHash, string $voterBoardHash, int $weight): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO retro_votes (comment_id, board_id, voter_hash, voter_board_hash, weight) VALUES (?, ?, ?, ?, '
                . '?)'
        );
        $stmt->execute([$commentId, $boardId, $voterHash, $voterBoardHash, $weight]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateWeight(int $id, int $weight): void
    {
        $stmt = $this->pdo->prepare('UPDATE retro_votes SET weight = ? WHERE id = ?');
        $stmt->execute([$weight, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM retro_votes WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function sumWeightForComment(int $commentId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(weight), 0) FROM retro_votes WHERE comment_id = ?');
        $stmt->execute([$commentId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Given a set of candidate voter_hash values (one per comment being
     * displayed, precomputed by the caller since voter_hash is
     * comment-scoped — see Service\VoteService::voterHash()), returns just
     * the subset that actually have a matching vote row. Used to mark
     * "you already voted on this" per comment in a single query instead of
     * one lookup per comment.
     *
     * @param string[] $voterHashes
     * @return string[] the subset of $voterHashes with an existing vote row
     */
    public function findExistingHashes(int $boardId, array $voterHashes): array
    {
        if ($voterHashes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($voterHashes), '?'));
        $stmt = $this->pdo->prepare("SELECT voter_hash FROM retro_votes WHERE board_id = ? AND voter_hash IN ({$placeholders})");
        $stmt->execute([$boardId, ...$voterHashes]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Total points a voter has currently allocated across an entire board
     * — budget mode's "remaining points" is vote_budget minus this. Keyed
     * by voter_board_hash (comment-independent), not voter_hash (which
     * differs per comment by design — see schema.sql's column comment).
     */
    public function sumWeightForVoterOnBoard(int $boardId, string $voterBoardHash): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(weight), 0) FROM retro_votes WHERE board_id = ? AND '
            . 'voter_board_hash = ?');
        $stmt->execute([$boardId, $voterBoardHash]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Vote
    {
        return new Vote(
            id: (int) $row['id'],
            commentId: (int) $row['comment_id'],
            boardId: (int) $row['board_id'],
            voterHash: (string) $row['voter_hash'],
            voterBoardHash: (string) $row['voter_board_hash'],
            weight: (int) $row['weight']
        );
    }
}
