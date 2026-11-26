<?php

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * What PostRepository and ReplyRepository share around their
 * `findLatestCreatedAtByGroups()` — the two halves of the group list's
 * « Nouveau » badge (issue #1349): the group ids normalised the same way, and
 * the rows read back the same way. The SQL itself stays in each repository,
 * written out whole, so that every interpolated fragment is an `array_fill`
 * placeholder run inside its own IN (…) (Tests\Security\SqlInjectionAuditTest).
 */
final class LatestCreatedAtByGroup
{
    /**
     * @param int[] $groupIds
     * @return int[] distinct, as integers, re-indexed
     */
    public static function groupIds(array $groupIds): array
    {
        return array_values(array_unique(array_map('intval', $groupIds)));
    }

    /**
     * Reads `group_id, latest` rows; a group whose MAX() is NULL is absent.
     *
     * @return array<int, string> group id => created_at of its newest row
     */
    public static function fromRows(\PDOStatement $stmt): array
    {
        $latest = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['latest'] !== null) {
                $latest[(int) $row['group_id']] = (string) $row['latest'];
            }
        }

        return $latest;
    }
}
