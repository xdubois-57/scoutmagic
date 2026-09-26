<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Repository;

/**
 * `social_cards`: which composed image a token opens, and until when.
 *
 * Keyed by the token's SHA-256, never the token: a copy of the table, a
 * backup, a support archive, opens no card.
 */
class CardRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(
        string $tokenHash,
        string $fileName,
        bool $blurred,
        \DateTimeImmutable $now,
        \DateTimeImmutable $expiresAt
    ): int {
        $this->pdo->prepare(
            'INSERT INTO social_cards (token_hash, file_name, blurred, created_at, expires_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $tokenHash,
            $fileName,
            $blurred ? 1 : 0,
            $now->format('Y-m-d H:i:s'),
            $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The card a token opens, if it has not expired.
     *
     * @return array{id: int, file_name: string}|null
     */
    public function findLive(string $tokenHash, \DateTimeImmutable $now): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, file_name FROM social_cards WHERE token_hash = ? AND expires_at > ?'
        );
        $stmt->execute([$tokenHash, $now->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? ['id' => (int) $row['id'], 'file_name' => (string) $row['file_name']] : null;
    }

    public function recordServed(int $id): void
    {
        $this->pdo->prepare('UPDATE social_cards SET served_count = served_count + 1 WHERE id = ?')->execute([$id]);
    }

    /**
     * @return list<array{id: int, file_name: string}>
     */
    public function findExpired(\DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare('SELECT id, file_name FROM social_cards WHERE expires_at <= ?');
        $stmt->execute([$now->format('Y-m-d H:i:s')]);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'file_name' => (string) $row['file_name']],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM social_cards WHERE id = ?')->execute([$id]);
    }
}
