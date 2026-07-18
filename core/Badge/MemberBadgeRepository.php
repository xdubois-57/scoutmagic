<?php

declare(strict_types=1);

namespace Core\Badge;

class MemberBadgeRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Active badges assigned to a member_year, ordered by name. A badge that
     * has since been deactivated never shows here — the assignment row
     * itself is preserved, just not surfaced.
     *
     * @return Badge[]
     */
    public function getActiveBadgesForMemberYear(int $memberYearId): array
    {
        return $this->getActiveBadgesForMemberYears([$memberYearId])[$memberYearId] ?? [];
    }

    /**
     * Batch variant of getActiveBadgesForMemberYear() — one query for a
     * whole section's worth of staff instead of one per member.
     *
     * @param int[] $memberYearIds
     * @return array<int, Badge[]> keyed by member_year_id, only ids with at
     *                              least one active badge are present
     */
    public function getActiveBadgesForMemberYears(array $memberYearIds): array
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));
        if (count($memberYearIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT mb.member_year_id, b.*
             FROM member_badges mb
             JOIN badges b ON mb.badge_id = b.id
             WHERE mb.member_year_id IN ({$placeholders}) AND b.is_active = 1
             ORDER BY b.name"
        );
        $stmt->execute($memberYearIds);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberYearId = (int) $row['member_year_id'];
            $result[$memberYearId][] = new Badge(
                id: (int) $row['id'],
                name: (string) $row['name'],
                isDefault: (bool) $row['is_default'],
                isActive: (bool) $row['is_active']
            );
        }
        return $result;
    }

    public function isAssigned(int $memberYearId, int $badgeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM member_badges WHERE member_year_id = ? AND badge_id = ?'
        );
        $stmt->execute([$memberYearId, $badgeId]);
        return $stmt->fetchColumn() !== false;
    }

    public function assign(int $memberYearId, int $badgeId, ?int $assignedBy): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_badges (member_year_id, badge_id, assigned_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $badgeId, $assignedBy]);
    }

    public function unassign(int $memberYearId, int $badgeId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM member_badges WHERE member_year_id = ? AND badge_id = ?'
        );
        $stmt->execute([$memberYearId, $badgeId]);
    }

    /**
     * Whether this badge has ever been assigned to any member, in any scout
     * year — used to block deletion (SECURITY/data-integrity: a deletable
     * badge must never have been in use).
     */
    public function badgeHasAnyAssignment(int $badgeId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM member_badges WHERE badge_id = ? LIMIT 1');
        $stmt->execute([$badgeId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Ids of every badge that has ever been assigned to a member, in any
     * scout year — used by the admin UI to disable the delete button.
     *
     * @return int[]
     */
    public function assignedBadgeIds(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT badge_id FROM member_badges');
        if ($stmt === false) {
            return [];
        }
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
