<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

use Modules\Groups\Support\SearchTerm;

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
     * The $limit replies immediately BEFORE $beforeId, oldest first — the
     * "Voir les commentaires précédents" direction, which is the one a
     * long thread is read in: what was just said is on screen, and older
     * comments are fetched backwards from there.
     *
     * Selected newest-first so the LIMIT takes the ones nearest the
     * cursor, then reversed, because what is fetched last still has to be
     * displayed first.
     *
     * @return Reply[]
     */
    public function findPageBefore(int $postId, int $limit, int $beforeId, bool $includeHidden = false): array
    {
        $hiddenClause = $includeHidden ? '' : ' AND hidden_at IS NULL';
        // LIMIT interpolated rather than bound, for the reason findPage()
        // gives below.
        $stmt = $this->pdo->prepare(
            'SELECT * FROM discussion_group_replies WHERE post_id = ? AND id < ?' . $hiddenClause
            . ' ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$postId, $beforeId]);

        return array_reverse(array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /**
     * One page of a post's replies, oldest first. $afterId is the last id
     * of the previous page, or null for the first page.
     *
     * The general read accessor for a post's replies — but no longer how
     * the UI pages through them: a thread opens on its end and walks
     * backwards (findPageBefore(), findLastForPosts()). Reaching for the
     * $afterId cursor to paginate a thread again would put a reader back
     * at the oldest comment, which is the thing that was wrong.
     *
     * @return Reply[]
     */
    public function findPage(int $postId, int $limit, ?int $afterId = null, bool $includeHidden = false): array
    {
        $hiddenClause = $includeHidden ? '' : ' AND hidden_at IS NULL';
        // LIMIT is interpolated, never bound: it is an int this class's
        // callers compute, and MySQL will not accept a bound parameter
        // there in emulation-off mode. Same precedent as
        // Repository\PostRepository::findPage().
        if ($afterId === null) {
            $sql = 'SELECT * FROM discussion_group_replies WHERE post_id = ?' . $hiddenClause
                . ' ORDER BY id ASC LIMIT ' . $limit;
            $params = [$postId];
        } else {
            $sql = 'SELECT * FROM discussion_group_replies WHERE post_id = ? AND id > ?' . $hiddenClause
                . ' ORDER BY id ASC LIMIT ' . $limit;
            $params = [$postId, $afterId];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The LAST $perPost replies of each of several posts, plus each post's
     * total reply count — the feed's own "show the end of the
     * conversation, offer the rest" need, in two queries for the whole
     * page rather than two per post.
     *
     * The last, not the first: a thread of forty comments opened on its
     * five oldest is a thread nobody can use, because the five that matter
     * are the ones somebody wrote this morning and reaching them meant
     * paging forward seven times. Ranked newest-first inside the window
     * and ordered oldest-first outside it, so the SELECT takes the tail
     * and the display still reads as a conversation.
     *
     * ROW_NUMBER() does the per-post limiting inside the database, so a
     * post with a thousand replies still transfers only $perPost of them.
     * Available on both engines this project runs on: MySQL 8.0 (the
     * production target) and SQLite 3.25+ (the test suite).
     *
     * @param int[] $postIds
     * @return array{replies: array<int, Reply[]>, counts: array<int, int>}
     *         both keyed by post id; `replies` holds at most $perPost per
     *         post — the newest ones, in oldest-first display order — and
     *         `counts` the true total.
     */
    public function findLastForPosts(array $postIds, int $perPost, bool $includeHidden = false): array
    {
        if ($postIds === []) {
            return ['replies' => [], 'counts' => []];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        // Applied INSIDE the window function's own subquery, not around
        // it: filtering after ROW_NUMBER() would let a hidden reply
        // consume one of the $perPost ranks and silently shorten the
        // visible list. The count query below carries the same clause, so
        // "3 réponses" never counts something the reader cannot see.
        $hiddenClause = $includeHidden ? '' : ' AND hidden_at IS NULL';

        $stmt = $this->pdo->prepare(
            "SELECT * FROM (
                 SELECT *, ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY id DESC) AS row_num
                 FROM discussion_group_replies
                 WHERE post_id IN ({$placeholders}){$hiddenClause}
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
             WHERE post_id IN ({$placeholders}){$hiddenClause} GROUP BY post_id"
        );
        $countStmt->execute($postIds);

        $counts = [];
        foreach ($countStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['post_id']] = (int) $row['total'];
        }

        return ['replies' => $replies, 'counts' => $counts];
    }

    public function countForPost(int $postId, bool $includeHidden = false): int
    {
        $hiddenClause = $includeHidden ? '' : ' AND hidden_at IS NULL';
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM discussion_group_replies WHERE post_id = ?' . $hiddenClause
        );
        $stmt->execute([$postId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many replies each of these posts has received since $since —
     * what a collapsed thread's "2 nouveaux" badge counts.
     *
     * $excludeMemberIds is the reader's own members: a comment you wrote
     * yourself is never new to you, and counting it would put a badge on
     * every thread you have just taken part in.
     *
     * Posts with nothing new are simply absent from the result rather
     * than present with a zero, so the caller's `?? 0` is the only place
     * the default lives.
     *
     * @param int[] $postIds
     * @param int[] $excludeMemberIds
     * @return array<int, int> post id => how many replies are newer than $since
     */
    public function countNewerForPosts(array $postIds, string $since, array $excludeMemberIds = [], bool $includeHidden = false): array
    {
        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        // Same rule as findLastForPosts(): a hidden reply is excluded in
        // SQL for everyone but a moderator, so a badge can never announce
        // something the reader would not find on opening the thread.
        $hiddenClause = $includeHidden ? '' : ' AND hidden_at IS NULL';

        // Two whole statements rather than one with an optional clause
        // stitched into it: every interpolated fragment here is then either
        // a ternary between two literals or an array_fill() placeholder run
        // sitting inside its own IN (…), which is what makes the query
        // provably parameterised — to a reader and to
        // Tests\Security\SqlInjectionAuditTest alike. The five duplicated
        // lines are worth that.
        if ($excludeMemberIds === []) {
            $stmt = $this->pdo->prepare(
                "SELECT post_id, COUNT(*) AS total FROM discussion_group_replies
                 WHERE post_id IN ({$placeholders}){$hiddenClause} AND created_at > ?
                 GROUP BY post_id"
            );
            $stmt->execute([...array_values($postIds), $since]);
        } else {
            $authorPlaceholders = implode(',', array_fill(0, count($excludeMemberIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT post_id, COUNT(*) AS total FROM discussion_group_replies
                 WHERE post_id IN ({$placeholders}){$hiddenClause} AND created_at > ?
                   AND author_member_id NOT IN ({$authorPlaceholders})
                 GROUP BY post_id"
            );
            $stmt->execute([...array_values($postIds), $since, ...array_values($excludeMemberIds)]);
        }

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['post_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The ids of the posts that carry a matching reply — ids only, not
     * the replies themselves. A search result is a list of posts, and a
     * post whose thread mentions the term is exactly as much of a hit as
     * one whose own body does; showing the reply on its own, out of the
     * conversation it answers, tells the reader nothing.
     *
     * Both hidden states are honoured for a non-moderator, and both in
     * the SQL: a hidden reply must not surface its post, and a matching
     * reply under a hidden post must not reveal that the post exists.
     *
     * @param string $pattern a LIKE pattern from Support\SearchTerm::pattern()
     * @return int[]
     */
    public function searchPostIds(int $groupId, string $pattern, int $limit, bool $includeHidden = false): array
    {
        $hiddenClause = $includeHidden ? '' : ' AND r.hidden_at IS NULL AND p.hidden_at IS NULL';
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT r.post_id FROM discussion_group_replies r
             INNER JOIN discussion_group_posts p ON p.id = r.post_id
             WHERE p.group_id = ?' . $hiddenClause . "
               AND r.body LIKE ? ESCAPE '" . SearchTerm::ESCAPE_CHARACTER . "'
             ORDER BY r.post_id DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute([$groupId, $pattern]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The posts these replies belong to — how a reported COMMENT reaches
     * a moderator's list, which is a list of conversations: the comment
     * is read (and acted on) inside the message it answers, never on its
     * own.
     *
     * @param int[] $replyIds
     * @return int[] post ids, deduplicated
     */
    public function postIdsForReplyIds(array $replyIds): array
    {
        if ($replyIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($replyIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT post_id FROM discussion_group_replies WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_map('intval', $replyIds));

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function setHiddenAt(int $id, ?string $hiddenAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE discussion_group_replies SET hidden_at = ? WHERE id = ?');
        $stmt->execute([$hiddenAt, $id]);
    }

    /** @see Repository\PostRepository::restore() — same one-write contract. */
    public function restore(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discussion_group_replies SET hidden_at = NULL, moderation_cleared = 1 WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * Gallery media belonging to HIDDEN replies of a group — what
     * Service\PostMediaService subtracts from "Galerie du groupe" for a
     * non-moderator, so a hidden reply's image does not remain reachable
     * through the gallery after the reply itself disappeared from the
     * feed.
     *
     * @return int[]
     */
    public function findMediaIdsForHiddenRepliesInGroup(int $groupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.gallery_media_id FROM discussion_group_replies r
             INNER JOIN discussion_group_posts p ON p.id = r.post_id
             WHERE p.group_id = ? AND r.gallery_media_id IS NOT NULL
               AND (r.hidden_at IS NOT NULL OR p.hidden_at IS NOT NULL)'
        );
        $stmt->execute([$groupId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
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
     * How many visible replies landed in this group after $since, other
     * people's only — half of the homepage's "réactions ou réponses"
     * counter. A reply under a hidden post is excluded with its post: the
     * reader could not reach it by opening the group.
     *
     * @param int[] $excludeMemberIds
     */
    public function countNewerForGroup(int $groupId, string $since, array $excludeMemberIds = []): int
    {
        if ($excludeMemberIds === []) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM discussion_group_replies r
                 INNER JOIN discussion_group_posts p ON p.id = r.post_id
                 WHERE p.group_id = ? AND r.created_at > ?
                   AND r.hidden_at IS NULL AND p.hidden_at IS NULL'
            );
            $stmt->execute([$groupId, $since]);
        } else {
            $authorPlaceholders = implode(',', array_fill(0, count($excludeMemberIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM discussion_group_replies r
                 INNER JOIN discussion_group_posts p ON p.id = r.post_id
                 WHERE p.group_id = ? AND r.created_at > ?
                   AND r.hidden_at IS NULL AND p.hidden_at IS NULL
                   AND r.author_member_id NOT IN ({$authorPlaceholders})"
            );
            $stmt->execute([$groupId, $since, ...array_values($excludeMemberIds)]);
        }

        return (int) $stmt->fetchColumn();
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
            (string) $row['created_at'],
            $row['hidden_at'] !== null ? (string) $row['hidden_at'] : null,
            (bool) $row['moderation_cleared']
        );
    }
}
