<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

use Modules\Groups\Support\Timestamps;

/**
 * discussion_group_sections — the sections whose members are derived
 * members of a group. Returns plain section ids: nothing here needs a
 * section's name or colour, which Core\Member\SectionService owns.
 */
class GroupSectionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Idempotent: re-inviting a section already linked to the group is a
     * no-op rather than a unique-constraint error.
     */
    public function add(int $groupId, int $sectionId): void
    {
        if (in_array($sectionId, $this->findSectionIds($groupId), true)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_sections (group_id, section_id, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$groupId, $sectionId, Timestamps::now()]);
    }

    /**
     * @return int[]
     */
    public function findSectionIds(int $groupId): array
    {
        $stmt = $this->pdo->prepare('SELECT section_id FROM discussion_group_sections WHERE group_id = ? ORDER BY section_id');
        $stmt->execute([$groupId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * One query for a whole page of groups — the group list would
     * otherwise issue one findSectionIds() per group.
     *
     * @param int[] $groupIds
     * @return array<int, int[]> group id => section ids
     */
    public function findSectionIdsByGroupIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT group_id, section_id FROM discussion_group_sections WHERE group_id IN ({$placeholders}) ORDER BY section_id"
        );
        $stmt->execute(array_map('intval', $groupIds));

        $byGroup = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byGroup[(int) $row['group_id']][] = (int) $row['section_id'];
        }

        return $byGroup;
    }

    public function remove(int $groupId, int $sectionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM discussion_group_sections WHERE group_id = ? AND section_id = ?');
        $stmt->execute([$groupId, $sectionId]);
    }
}
