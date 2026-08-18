<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * Feed reads and post writes.
 *
 * The feed is paginated by keyset, never OFFSET: a feed reorders itself
 * under the reader (any reply or reaction bumps last_activity_at), and an
 * OFFSET page 2 would then skip or repeat posts. The cursor is the full
 * ordering key — (last_activity_at, id) — because last_activity_at alone
 * is not unique.
 *
 * Pinned posts are fetched by findPinned() and excluded from that stream
 * entirely. Mixing them in would break the cursor the moment a post is
 * pinned or unpinned mid-scroll, and would show a pinned post twice: once
 * at the top and once in its chronological place.
 */
class PostRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $groupId, int $authorUserAccountId, int $authorMemberId, string $body, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_posts
                (group_id, author_user_account_id, author_member_id, body, is_pinned, edited_at, last_activity_at, created_at)
             VALUES (?, ?, ?, ?, 0, NULL, ?, ?)'
        );
        // last_activity_at is seeded with the creation time, never left to
        // a default: the feed ordering and its cursor both read it.
        $stmt->execute([$groupId, $authorUserAccountId, $authorMemberId, $body, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Post
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discussion_group_posts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @return Post[] pinned posts, newest activity first
     */
    public function findPinned(int $groupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM discussion_group_posts
             WHERE group_id = ? AND is_pinned = 1
             ORDER BY last_activity_at DESC, id DESC'
        );
        $stmt->execute([$groupId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * One page of the unpinned stream. $cursor is the (last_activity_at,
     * id) pair of the last post of the previous page, or null for the
     * first page.
     *
     * @param array{last_activity_at: string, id: int}|null $cursor
     * @return Post[]
     */
    public function findPage(int $groupId, int $limit, ?array $cursor = null): array
    {
        if ($cursor === null) {
            $sql = 'SELECT * FROM discussion_group_posts
                    WHERE group_id = ? AND is_pinned = 0
                    ORDER BY last_activity_at DESC, id DESC
                    LIMIT ' . $limit;
            $params = [$groupId];
        } else {
            $sql = 'SELECT * FROM discussion_group_posts
                    WHERE group_id = ? AND is_pinned = 0
                      AND (last_activity_at < ? OR (last_activity_at = ? AND id < ?))
                    ORDER BY last_activity_at DESC, id DESC
                    LIMIT ' . $limit;
            $params = [$groupId, $cursor['last_activity_at'], $cursor['last_activity_at'], $cursor['id']];
        }

        // LIMIT is interpolated, never bound: it is an int this class
        // computes, and MySQL will not accept a bound parameter there in
        // emulation-off mode.
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function updateBody(int $id, string $body, string $editedAt): void
    {
        // last_activity_at is deliberately untouched: correcting a typo
        // must not resurrect an old post to the top of the feed (nor
        // postpone its later purge).
        $stmt = $this->pdo->prepare('UPDATE discussion_group_posts SET body = ?, edited_at = ? WHERE id = ?');
        $stmt->execute([$body, $editedAt, $id]);
    }

    public function setPinned(int $id, bool $isPinned): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_group_posts SET is_pinned = ? WHERE id = ?');
        $stmt->execute([$isPinned ? 1 : 0, $id]);
    }

    public function touchActivity(int $id, string $at): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_group_posts SET last_activity_at = ? WHERE id = ?');
        $stmt->execute([$at, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM discussion_group_posts WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Post
    {
        return new Post(
            (int) $row['id'],
            (int) $row['group_id'],
            (int) $row['author_user_account_id'],
            (int) $row['author_member_id'],
            (string) $row['body'],
            (bool) $row['is_pinned'],
            $row['edited_at'] !== null ? (string) $row['edited_at'] : null,
            (string) $row['last_activity_at'],
            (string) $row['created_at']
        );
    }
}
