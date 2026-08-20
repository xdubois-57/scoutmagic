<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

use Modules\Groups\Support\SearchTerm;

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
     * @param bool $includeHidden only ever true for a moderator's own
     *        request — auto-hidden posts are excluded in the QUERY, not in
     *        the markup, so a non-moderator cannot reach one by reading
     *        the HTML or replaying the request.
     * @return Post[] pinned posts, newest activity first
     */
    public function findPinned(int $groupId, bool $includeHidden = false): array
    {
        $hiddenClause = $includeHidden ? '' : ' AND hidden_at IS NULL';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM discussion_group_posts
             WHERE group_id = ? AND is_pinned = 1' . $hiddenClause . '
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
    public function findPage(int $groupId, int $limit, ?array $cursor = null, bool $includeHidden = false): array
    {
        // Same query-level exclusion as findPinned(). It also keeps the
        // keyset cursor honest: a hidden post filtered out in PHP would
        // still consume a slot of the LIMIT, so a page could come back
        // short and the "is there more?" probe would be wrong.
        $hiddenClause = $includeHidden ? '' : ' AND hidden_at IS NULL';

        if ($cursor === null) {
            $sql = 'SELECT * FROM discussion_group_posts
                    WHERE group_id = ? AND is_pinned = 0' . $hiddenClause . '
                    ORDER BY last_activity_at DESC, id DESC
                    LIMIT ' . $limit;
            $params = [$groupId];
        } else {
            $sql = 'SELECT * FROM discussion_group_posts
                    WHERE group_id = ? AND is_pinned = 0' . $hiddenClause . '
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

    /**
     * Posts of one group whose own body matches, newest first.
     *
     * Ordered by created_at rather than last_activity_at: a search result
     * answers "when was this said", and a reply from this morning must
     * not float a two-year-old message to the top of the list. Same
     * query-level hidden exclusion as findPage() — a non-moderator cannot
     * reach an auto-hidden post through the search box either, which is
     * the whole reason $includeHidden is a WHERE clause and not a filter
     * applied afterwards.
     *
     * Pinned posts are NOT excluded here, unlike findPage(): there is no
     * separate pinned block in a result list to duplicate them into, and
     * a member searching for something they remember being pinned would
     * be baffled to not find it.
     *
     * @param string $pattern a LIKE pattern from Support\SearchTerm::pattern()
     * @return Post[]
     */
    public function search(int $groupId, string $pattern, int $limit, bool $includeHidden = false): array
    {
        $hiddenClause = $includeHidden ? '' : ' AND hidden_at IS NULL';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM discussion_group_posts
             WHERE group_id = ?' . $hiddenClause . "
               AND body LIKE ? ESCAPE '" . SearchTerm::ESCAPE_CHARACTER . "'
             ORDER BY created_at DESC, id DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute([$groupId, $pattern]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The posts behind a set of ids, restricted to one group and to what
     * this reader may see. $groupId is not decoration: the ids come from
     * a reply search, and re-stating the group here is what stops a bug
     * anywhere upstream from surfacing another group's post.
     *
     * @param int[] $ids
     * @return Post[]
     */
    public function findByIdsInGroup(int $groupId, array $ids, bool $includeHidden = false): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $hiddenClause = $includeHidden ? '' : ' AND hidden_at IS NULL';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM discussion_group_posts
             WHERE group_id = ? AND id IN ({$placeholders}){$hiddenClause}
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute(array_merge([$groupId], $ids));

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
     * Posts whose last activity predates $cutoff — the retention purge's
     * candidates. Pinned posts are excluded in the SQL and therefore never
     * reachable by the purge at all, at any age (module spec): a pinned
     * post is one somebody deliberately kept.
     *
     * Ordered oldest-first and bounded by $limit so a first run on a large
     * install deletes the most overdue batch and lets the next run
     * continue, rather than trying to delete thousands of posts (and their
     * stored media) in one pass.
     *
     * @return Post[]
     */
    public function findPurgeable(string $cutoff, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM discussion_group_posts
             WHERE is_pinned = 0 AND last_activity_at < ?
             ORDER BY last_activity_at, id
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$cutoff]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every post of one group, oldest first, hidden ones included — what
     * a whole-group purge walks to clean each post's media and link image
     * out of storage before the CASCADE removes the rows.
     *
     * @return Post[]
     */
    public function findAllForGroup(int $groupId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discussion_group_posts WHERE group_id = ? ORDER BY id');
        $stmt->execute([$groupId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function setHiddenAt(int $id, ?string $hiddenAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_group_posts SET hidden_at = ? WHERE id = ?');
        $stmt->execute([$hiddenAt, $id]);
    }

    /**
     * Marks the post as reviewed and cleared by a moderator: it becomes
     * visible again AND immune to further auto-hiding, in one write, so
     * the two can never drift apart.
     */
    public function restore(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discussion_group_posts SET hidden_at = NULL, moderation_cleared = 1 WHERE id = ?'
        );
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
            (string) $row['created_at'],
            $row['hidden_at'] !== null ? (string) $row['hidden_at'] : null,
            (bool) $row['moderation_cleared']
        );
    }
}
