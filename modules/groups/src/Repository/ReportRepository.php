<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * Reports on posts and on replies. Two tables (see their own schema.sql
 * comment for why they are not one polymorphic table), one class — the
 * exact same shape, and for the same reasons, as
 * Repository\ReactionRepository.
 *
 * The table and column names can never come from a request: the
 * constructor is private and the only two ways to build this class are the
 * named constructors below, each passing string literals. That is what
 * makes interpolating them into the statements safe — everything that
 * varies per call is still a bound parameter (SECURITY.md §1).
 */
class ReportRepository
{
    private function __construct(
        private \PDO $pdo,
        private string $table,
        private string $itemColumn
    ) {
    }

    public static function forPosts(\PDO $pdo): self
    {
        return new self($pdo, 'discussion_group_post_reports', 'post_id');
    }

    public static function forReplies(\PDO $pdo): self
    {
        return new self($pdo, 'discussion_group_reply_reports', 'reply_id');
    }

    /**
     * Records one member's report of one item, and answers whether it was
     * new. A repeat report by the same member is absorbed here rather than
     * counted twice: the UNIQUE (item, reporter) index is the actual
     * enforcement, so a double submit loses the race and lands in the
     * catch instead of pushing the item over the threshold on one
     * person's say-so.
     */
    public function record(int $itemId, int $reporterMemberId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->table} ({$this->itemColumn}, reporter_member_id, created_at)
                 VALUES (?, ?, ?)"
            );
            $stmt->execute([$itemId, $reporterMemberId, gmdate('Y-m-d H:i:s')]);

            return true;
        } catch (\PDOException $e) {
            // '23000' is the integrity-constraint-violation SQLSTATE, the
            // same string on both the MySQL and SQLite drivers.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return false;
        }
    }

    public function countFor(int $itemId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE {$this->itemColumn} = ?");
        $stmt->execute([$itemId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Whether one of $memberIds has already reported $itemId — so the UI
     * can show "signalé" instead of offering the action again. Several
     * ids because an account may be linked to more than one member of the
     * group, exactly like Repository\ReactionRepository::findKeyFor().
     *
     * @param int[] $memberIds
     */
    public function hasReported(int $itemId, array $memberIds): bool
    {
        if ($memberIds === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM {$this->table}
             WHERE {$this->itemColumn} = ? AND reporter_member_id IN ({$placeholders}) LIMIT 1"
        );
        $stmt->execute(array_merge([$itemId], array_values($memberIds)));

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Which of a whole page of items the caller has already reported, in
     * ONE query — same no-N+1 contract as the reaction aggregation.
     *
     * @param int[] $itemIds
     * @param int[] $memberIds
     * @return array<int, true> keyed by item id, for reported items only
     */
    public function reportedByFor(array $itemIds, array $memberIds): array
    {
        if ($itemIds === [] || $memberIds === []) {
            return [];
        }

        $itemPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));
        $memberPlaceholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT {$this->itemColumn} AS item_id FROM {$this->table}
             WHERE {$this->itemColumn} IN ({$itemPlaceholders}) AND reporter_member_id IN ({$memberPlaceholders})"
        );
        $stmt->execute(array_merge(array_values($itemIds), array_values($memberIds)));

        $reported = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $itemId) {
            $reported[(int) $itemId] = true;
        }

        return $reported;
    }

    /**
     * Report counts for a whole page, in ONE query — what a moderator's
     * view shows next to a hidden item. Only non-zero counts appear.
     *
     * @param int[] $itemIds
     * @return array<int, int> item id => report count
     */
    public function countsFor(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT {$this->itemColumn} AS item_id, COUNT(*) AS total FROM {$this->table}
             WHERE {$this->itemColumn} IN ({$placeholders}) GROUP BY {$this->itemColumn}"
        );
        $stmt->execute(array_values($itemIds));

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['item_id']] = (int) $row['total'];
        }

        return $counts;
    }
}
