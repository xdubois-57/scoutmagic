<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * Custom mailing lists (mass_mail_lists) — identity/lifecycle only. What a
 * list designates lives in its blocks (Repository\ListBlockRepository),
 * and resolving them to people is Repository\MemberResolutionRepository's
 * and Repository\ContactRepository's job, not this one's.
 */
class MailingListRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return MailingList[]
     */
    public function findAllOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM mass_mail_lists ORDER BY name ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findById(int $id): ?MailingList
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_lists WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(string $name, string $description, ?int $createdBy): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO mass_mail_lists (name, description, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$name, $description, $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $description): void
    {
        $stmt = $this->pdo->prepare('UPDATE mass_mail_lists SET name = ?, description = ? WHERE id = ?');
        $stmt->execute([$name, $description, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE mass_mail_lists SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    /**
     * Deletes the list and, by cascade, its blocks with their criteria and
     * contacts. Each table is emptied explicitly as well, children first,
     * so the SQLite test database — which does not enforce the cascade —
     * ends in the same state as the production engine.
     */
    public function delete(int $id): void
    {
        $blocks = $this->pdo->prepare('SELECT id FROM mass_mail_list_blocks WHERE list_id = ?');
        $blocks->execute([$id]);
        foreach (array_map('intval', $blocks->fetchAll(\PDO::FETCH_COLUMN)) as $blockId) {
            $this->pdo->prepare('DELETE FROM mass_mail_block_functions WHERE block_id = ?')->execute([$blockId]);
            $this->pdo->prepare('DELETE FROM mass_mail_block_sections WHERE block_id = ?')->execute([$blockId]);
            $this->pdo->prepare('DELETE FROM mass_mail_block_badges WHERE block_id = ?')->execute([$blockId]);
            $this->pdo->prepare('DELETE FROM mass_mail_block_contacts WHERE block_id = ?')->execute([$blockId]);
        }
        $this->pdo->prepare('DELETE FROM mass_mail_list_blocks WHERE list_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM mass_mail_lists WHERE id = ?')->execute([$id]);
    }

    /**
     * Whether at least one email (any status) was created against this
     * list — Service\MailingListService::delete()'s "deactivate instead"
     * guard, same Core\Badge precedent as BadgeRepository::
     * badgeHasAnyAssignment().
     */
    public function isReferencedByAnyEmail(int $listId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM mass_mail_emails WHERE list_id = ? LIMIT 1');
        $stmt->execute([$listId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MailingList
    {
        return new MailingList(
            id: (int) $row['id'],
            name: (string) $row['name'],
            description: (string) $row['description'],
            isActive: (bool) $row['is_active'],
            createdAt: (string) $row['created_at'],
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null
        );
    }
}
