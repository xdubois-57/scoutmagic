<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * discussion_group_reads — one row per (group, member): the last time that
 * member opened that group.
 *
 * Everything here is batched across a whole list of groups on purpose: the
 * two readers (the group list and the home page's activity card) both need
 * "which of these N groups have something new", and doing that one group
 * at a time would be exactly the N+1 the rest of this module is careful to
 * avoid.
 */
class GroupReadRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Records that $memberId has now seen everything in $groupId.
     *
     * INSERT-then-UPDATE rather than a read-modify-write, for the same
     * reason Repository\ReactionRepository::set() does it: two tabs (or a
     * double tap) genuinely race here, and the UNIQUE (group, member)
     * index is what makes the loser fall through to the UPDATE instead of
     * either duplicating the row or 500ing.
     */
    public function markRead(int $groupId, int $memberId, string $readAt): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO discussion_group_reads (group_id, member_id, last_read_at) VALUES (?, ?, ?)'
            );
            $stmt->execute([$groupId, $memberId, $readAt]);
        } catch (\PDOException $e) {
            // '23000' is the integrity-constraint-violation SQLSTATE, and
            // it is the same string on both the MySQL and SQLite drivers.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            // Never moves the mark backwards: an older tab finishing its
            // write after a newer one must not un-read what the newer one
            // already marked as seen.
            $stmt = $this->pdo->prepare(
                'UPDATE discussion_group_reads SET last_read_at = ?
                 WHERE group_id = ? AND member_id = ? AND last_read_at < ?'
            );
            $stmt->execute([$readAt, $groupId, $memberId, $readAt]);
        }
    }

    /**
     * The caller's own last-read timestamp for each of a set of groups, in
     * ONE query. A group absent from the result has never been opened by
     * any of $memberIds — the caller decides what that means (the group
     * list treats it as "everything is new").
     *
     * Several member ids for the same reason every other per-member read
     * in this module takes several: an account linked to two members of
     * the same group has one reading position, and whichever of its
     * members recorded it is the one that counts. MAX() picks the latest,
     * so the answer does not depend on which linked member happened to
     * open the group.
     *
     * @param int[] $groupIds
     * @param int[] $memberIds
     * @return array<int, string> group id => last read timestamp
     */
    public function lastReadFor(array $groupIds, array $memberIds): array
    {
        if ($groupIds === [] || $memberIds === []) {
            return [];
        }

        $groupPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
        $memberPlaceholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT group_id, MAX(last_read_at) AS last_read_at
             FROM discussion_group_reads
             WHERE group_id IN ({$groupPlaceholders}) AND member_id IN ({$memberPlaceholders})
             GROUP BY group_id"
        );
        $stmt->execute(array_merge(array_values($groupIds), array_values($memberIds)));

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['group_id']] = (string) $row['last_read_at'];
        }

        return $result;
    }

    /**
     * Every read mark of one group, in ONE query — member id => the last
     * time that member opened the group.
     *
     * This is what makes a whole feed page's "vu par" counts cost one
     * query instead of one per post: a group has a bounded, small
     * membership, so the entire mark set is cheaper to fetch once and
     * compare in PHP than to ask the database per post. The per-post
     * question (membersWhoReadSince() below) stays for the dialog, which
     * only ever runs for the single post somebody just opened.
     *
     * @return array<int, string> member id => last read timestamp
     */
    public function readMarksForGroup(int $groupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT member_id, last_read_at FROM discussion_group_reads WHERE group_id = ?'
        );
        $stmt->execute([$groupId]);

        $marks = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $marks[(int) $row['member_id']] = (string) $row['last_read_at'];
        }

        return $marks;
    }

    /**
     * Every member who has opened $groupId at or after $since — the "seen
     * by" answer for one post, where $since is that post's own created_at.
     *
     * Deliberately derived from the group-level read mark rather than a
     * per-post one: a member who opened the group after a post was
     * published has seen the feed that post was in, and a per-post read
     * table would cost one row per post per member for an answer nobody
     * needs that precisely.
     *
     * @return int[] member ids, ascending
     */
    public function membersWhoReadSince(int $groupId, string $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT member_id FROM discussion_group_reads
             WHERE group_id = ? AND last_read_at >= ? ORDER BY member_id ASC'
        );
        $stmt->execute([$groupId, $since]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
