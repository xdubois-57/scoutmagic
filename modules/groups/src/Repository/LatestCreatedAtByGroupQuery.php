<?php

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * The one query behind both halves of the group list's « Nouveau » badge
 * (issue #1349): for each group, the newest visible row written by somebody
 * other than the reader. PostRepository and ReplyRepository each describe
 * their own table — what to read, how a row reaches its group, what makes it
 * visible, who wrote it — and this runs it once for the whole list.
 *
 * The fragments are fixed SQL written in those two repositories, never
 * input. Two whole statements rather than an optional clause stitched in,
 * for the reason ReplyRepository::countNewerForPosts() gives: every
 * interpolated fragment is then a placeholder run inside its own IN (…).
 * Groups with no such row are absent from the result.
 */
final class LatestCreatedAtByGroupQuery
{
    /**
     * @param string $from           FROM clause, joins included
     * @param string $groupColumn    column holding the group id
     * @param string $createdColumn  column whose MAX() is returned
     * @param string $visibleWhere   condition a visible row satisfies
     * @param string $authorColumn   column holding the author's member id
     */
    public function __construct(
        private string $from,
        private string $groupColumn,
        private string $createdColumn,
        private string $visibleWhere,
        private string $authorColumn
    ) {
    }

    /**
     * @param int[] $groupIds
     * @param int[] $excludeMemberIds
     * @return array<int, string> group id => created_at of its newest such row
     */
    public function fetch(\PDO $pdo, array $groupIds, array $excludeMemberIds): array
    {
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        if ($groupIds === []) {
            return [];
        }

        $groupPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
        $select = "SELECT {$this->groupColumn} AS group_id, MAX({$this->createdColumn}) AS latest
                   FROM {$this->from}
                   WHERE {$this->groupColumn} IN ({$groupPlaceholders}) AND {$this->visibleWhere}";

        if ($excludeMemberIds === []) {
            $stmt = $pdo->prepare("{$select} GROUP BY {$this->groupColumn}");
            $stmt->execute($groupIds);
        } else {
            $authorPlaceholders = implode(',', array_fill(0, count($excludeMemberIds), '?'));
            $stmt = $pdo->prepare(
                "{$select} AND {$this->authorColumn} NOT IN ({$authorPlaceholders}) GROUP BY {$this->groupColumn}"
            );
            $stmt->execute([...$groupIds, ...array_values($excludeMemberIds)]);
        }

        $latest = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['latest'] !== null) {
                $latest[(int) $row['group_id']] = (string) $row['latest'];
            }
        }

        return $latest;
    }
}
