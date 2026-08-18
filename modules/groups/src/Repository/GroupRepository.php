<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

use Modules\Groups\Support\Timestamps;

class GroupRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(string $name, ?int $scoutYearId, ?int $sectionId, int $createdByMemberId): int
    {
        $now = Timestamps::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_groups (name, scout_year_id, section_id, last_activity_at, created_by_member_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $scoutYearId, $sectionId, $now, $createdByMemberId, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?DiscussionGroup
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discussion_groups WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Every group, most recently active first. A unit has tens of groups,
     * not thousands, and readability has to be decided per group against
     * the caller's own membership (Service\GroupAccessService) — which is
     * not expressible as a WHERE clause, since derived membership lives in
     * the core member tables. Callers filter the result; see
     * Service\GroupListService, which batches the membership data it needs
     * so that filtering costs no query per group.
     *
     * @return DiscussionGroup[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM discussion_groups ORDER BY last_activity_at DESC, id DESC');

        return array_map([$this, 'hydrate'], $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Bumped whenever something happens in the group — see
     * Service\GroupActivityService, the single writer of this column.
     */
    public function touchActivity(int $id, string $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET last_activity_at = ? WHERE id = ?');
        $stmt->execute([$at, $id]);
    }

    public function setClosed(int $id, ?string $closedAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_groups SET closed_at = ? WHERE id = ?');
        $stmt->execute([$closedAt, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM discussion_groups WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DiscussionGroup
    {
        return new DiscussionGroup(
            (int) $row['id'],
            (string) $row['name'],
            $row['scout_year_id'] !== null ? (int) $row['scout_year_id'] : null,
            $row['section_id'] !== null ? (int) $row['section_id'] : null,
            $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
            (string) $row['last_activity_at'],
            (int) $row['created_by_member_id'],
            (string) $row['created_at']
        );
    }
}
