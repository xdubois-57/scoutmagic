<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * Reactions on posts and on replies. Two tables (see their own schema.sql
 * comment for why they are not one polymorphic table), one class: the SQL
 * is identical apart from the table and the item column, so a second copy
 * of it would be two places to fix the next time the shape changes.
 *
 * The table and column names are NOT parameters in the SQL-injection
 * sense and can never come from a request: the constructor is private and
 * the only two ways to build this class are the named constructors below,
 * each passing a pair of string literals. That is what makes interpolating
 * them into the statements safe — everything that varies per call is still
 * a bound parameter, as SECURITY.md §1 requires.
 */
class ReactionRepository
{
    private function __construct(
        private \PDO $pdo,
        private string $table,
        private string $itemColumn
    ) {
    }

    public static function forPosts(\PDO $pdo): self
    {
        return new self($pdo, 'discussion_group_post_reactions', 'post_id');
    }

    public static function forReplies(\PDO $pdo): self
    {
        return new self($pdo, 'discussion_group_reply_reactions', 'reply_id');
    }

    /**
     * Sets this member's reaction on this item, replacing whatever they
     * had before — the "clicking a different one replaces it" half of the
     * module spec.
     *
     * The UNIQUE (item, member) index is the actual enforcement of "one
     * reaction per member", not the read-then-write in
     * Service\ReactionService: a double-tap on mobile genuinely fires
     * twice, and two requests can both read "no reaction yet" before
     * either inserts. The loser of that race gets SQLSTATE 23000 here and
     * falls through to the UPDATE, so the outcome is the same reaction
     * either way instead of a duplicate row or a 500.
     */
    public function set(int $itemId, int $memberId, string $reactionKey): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->table} ({$this->itemColumn}, member_id, reaction_key, created_at)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$itemId, $memberId, $reactionKey, gmdate('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            // '23000' is the integrity-constraint-violation SQLSTATE, and
            // it is the same string on both the MySQL and SQLite drivers.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $stmt = $this->pdo->prepare(
                "UPDATE {$this->table} SET reaction_key = ? WHERE {$this->itemColumn} = ? AND member_id = ?"
            );
            $stmt->execute([$reactionKey, $itemId, $memberId]);
        }
    }

    /**
     * Removes this member's own reaction on this item, and only theirs —
     * the "clicking the same one removes it" half of the toggle.
     */
    public function remove(int $itemId, int $memberId): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE {$this->itemColumn} = ? AND member_id = ?"
        );
        $stmt->execute([$itemId, $memberId]);
    }

    /**
     * The reaction key one of $memberIds already has on $itemId, or null.
     *
     * Several ids because an account may be linked to more than one member
     * of the group; the reaction was written under whichever one
     * Service\ReactionService resolved, and the same account must see it
     * as "theirs" on the next render.
     *
     * @param int[] $memberIds
     */
    public function findKeyFor(int $itemId, array $memberIds): ?string
    {
        if ($memberIds === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT reaction_key FROM {$this->table}
             WHERE {$this->itemColumn} = ? AND member_id IN ({$placeholders})
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute(array_merge([$itemId], array_values($memberIds)));

        $key = $stmt->fetchColumn();

        return $key === false ? null : (string) $key;
    }

    /**
     * Reaction counts for a whole page of items, in ONE query — the module
     * spec's explicit no-N+1 requirement. Only non-zero counts appear.
     *
     * @param int[] $itemIds
     * @return array<int, array<string, int>> item id => (reaction key => count)
     */
    public function countsFor(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT {$this->itemColumn} AS item_id, reaction_key, COUNT(*) AS total
             FROM {$this->table}
             WHERE {$this->itemColumn} IN ({$placeholders})
             GROUP BY {$this->itemColumn}, reaction_key"
        );
        $stmt->execute(array_values($itemIds));

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['item_id']][(string) $row['reaction_key']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The caller's own reaction on each of a page of items, in ONE query —
     * the second half of the no-N+1 requirement.
     *
     * @param int[] $itemIds
     * @param int[] $memberIds
     * @return array<int, string> item id => the reaction key, for items the caller reacted to
     */
    public function ownKeysFor(array $itemIds, array $memberIds): array
    {
        if ($itemIds === [] || $memberIds === []) {
            return [];
        }

        $itemPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));
        $memberPlaceholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT {$this->itemColumn} AS item_id, reaction_key FROM {$this->table}
             WHERE {$this->itemColumn} IN ({$itemPlaceholders}) AND member_id IN ({$memberPlaceholders})
             ORDER BY id ASC"
        );
        $stmt->execute(array_merge(array_values($itemIds), array_values($memberIds)));

        $own = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // First row wins per item, matching findKeyFor()'s own
            // ORDER BY id ASC LIMIT 1 for the multi-linked-member case.
            $own[(int) $row['item_id']] ??= (string) $row['reaction_key'];
        }

        return $own;
    }
}
