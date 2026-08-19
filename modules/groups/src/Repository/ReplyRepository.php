<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * Replies to a post, oldest first — the opposite direction from the post
 * stream, because a conversation reads forward.
 *
 * Paginated by keyset on the id alone, not on (created_at, id) like the
 * feed: replies are ordered ascending by id, which is monotonic and
 * already unique, so no tie-break column is needed and the cursor is just
 * "the last id you saw".
 */
class ReplyRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(
        int $postId,
        int $authorUserAccountId,
        int $authorMemberId,
        string $body,
        ?int $galleryMediaId,
        string $now
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_replies
                (post_id, author_user_account_id, author_member_id, body, gallery_media_id, edited_at, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?)'
        );
        $stmt->execute([$postId, $authorUserAccountId, $authorMemberId, $body, $galleryMediaId, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Reply
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discussion_group_replies WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * One page of a post's replies, oldest first. $afterId is the last id
     * of the previous page, or null for the first page.
     *
     * @return Reply[]
     */
    public function findPage(int $postId, int $limit, ?int $afterId = null): array
    {
        // LIMIT is interpolated, never bound: it is an int this class's
        // callers compute, and MySQL will not accept a bound parameter
        // there in emulation-off mode. Same precedent as
        // Repository\PostRepository::findPage().
        if ($afterId === null) {
            $sql = 'SELECT * FROM discussion_group_replies WHERE post_id = ? ORDER BY id ASC LIMIT ' . $limit;
            $params = [$postId];
        } else {
            $sql = 'SELECT * FROM discussion_group_replies WHERE post_id = ? AND id > ? ORDER BY id ASC LIMIT ' . $limit;
            $params = [$postId, $afterId];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The first $perPost replies of each of several posts, plus each
     * post's total reply count — the feed's own "show the first few, offer
     * Charger plus" need, in two queries for the whole page rather than
     * two per post.
     *
     * ROW_NUMBER() does the per-post limiting inside the database, so a
     * post with a thousand replies still transfers only $perPost of them.
     * Available on both engines this project runs on: MySQL 8.0 (the
     * production target) and SQLite 3.25+ (the test suite).
     *
     * @param int[] $postIds
     * @return array{replies: array<int, Reply[]>, counts: array<int, int>}
     *         both keyed by post id; `replies` holds at most $perPost per
     *         post, oldest first, and `counts` the true total.
     */
    public function findFirstForPosts(array $postIds, int $perPost): array
    {
        if ($postIds === []) {
            return ['replies' => [], 'counts' => []];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT * FROM (
                 SELECT *, ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY id ASC) AS row_num
                 FROM discussion_group_replies
                 WHERE post_id IN ({$placeholders})
             ) ranked
             WHERE row_num <= " . $perPost . '
             ORDER BY post_id ASC, id ASC'
        );
        $stmt->execute($postIds);

        $replies = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $replies[(int) $row['post_id']][] = $this->hydrate($row);
        }

        $countStmt = $this->pdo->prepare(
            "SELECT post_id, COUNT(*) AS total FROM discussion_group_replies
             WHERE post_id IN ({$placeholders}) GROUP BY post_id"
        );
        $countStmt->execute($postIds);

        $counts = [];
        foreach ($countStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['post_id']] = (int) $row['total'];
        }

        return ['replies' => $replies, 'counts' => $counts];
    }

    public function countForPost(int $postId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM discussion_group_replies WHERE post_id = ?');
        $stmt->execute([$postId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every gallery media id attached to a post's replies — read BEFORE
     * the post is deleted, since the CASCADE that removes the reply rows
     * never touches the gallery media they point at
     * (Service\ReplyService::deleteAllForPost()).
     *
     * @return int[]
     */
    public function findMediaIdsForPost(int $postId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT gallery_media_id FROM discussion_group_replies
             WHERE post_id = ? AND gallery_media_id IS NOT NULL'
        );
        $stmt->execute([$postId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function updateBody(int $id, string $body, string $editedAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_group_replies SET body = ?, edited_at = ? WHERE id = ?');
        $stmt->execute([$body, $editedAt, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM discussion_group_replies WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Reply
    {
        return new Reply(
            (int) $row['id'],
            (int) $row['post_id'],
            (int) $row['author_user_account_id'],
            (int) $row['author_member_id'],
            (string) $row['body'],
            $row['gallery_media_id'] !== null ? (int) $row['gallery_media_id'] : null,
            $row['edited_at'] !== null ? (string) $row['edited_at'] : null,
            (string) $row['created_at']
        );
    }
}
