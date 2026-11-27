<?php

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * The query behind both halves of the group list's « Nouveau » badge
 * (issue #1349): for each group, the newest visible post — or reply —
 * written by somebody other than the reader. PostRepository and
 * ReplyRepository each expose their half and delegate here, so the
 * normalisation, the binding and the row reading exist once.
 *
 * Both statements are written out whole below and chosen by a `match` of
 * literals; the only interpolated fragments are `array_fill` placeholder
 * runs inside their own IN (…), built in this file
 * (Tests\Security\SqlInjectionAuditTest). One statement either way: with
 * nobody to exclude, the author clause binds a single 0, which no member id
 * is (members.id is an unsigned auto-increment, author_member_id NOT NULL).
 * Groups with no such row are absent from the result.
 */
final class LatestCreatedAtByGroup
{
    /** Posts: hidden posts are left out. */
    public const POSTS = 'posts';

    /** Replies: a reply hidden itself or through its post is left out. */
    public const REPLIES = 'replies';

    /**
     * @param self::POSTS|self::REPLIES $kind
     * @param int[] $groupIds
     * @param int[] $excludeMemberIds
     * @return array<int, string> group id => created_at of its newest such row
     */
    public static function fetch(\PDO $pdo, string $kind, array $groupIds, array $excludeMemberIds): array
    {
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        if ($groupIds === []) {
            return [];
        }

        $groupPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
        $authorPlaceholders = implode(',', array_fill(0, max(1, count($excludeMemberIds)), '?'));
        $sql = match ($kind) {
            self::POSTS => "SELECT group_id, MAX(created_at) AS latest FROM discussion_group_posts
                 WHERE group_id IN ({$groupPlaceholders}) AND hidden_at IS NULL
                   AND author_member_id NOT IN ({$authorPlaceholders})
                 GROUP BY group_id",
            self::REPLIES => "SELECT p.group_id, MAX(r.created_at) AS latest FROM discussion_group_replies r
                 INNER JOIN discussion_group_posts p ON p.id = r.post_id
                 WHERE p.group_id IN ({$groupPlaceholders})
                   AND r.hidden_at IS NULL AND p.hidden_at IS NULL
                   AND r.author_member_id NOT IN ({$authorPlaceholders})
                 GROUP BY p.group_id",
        };

        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$groupIds, ...($excludeMemberIds === [] ? [0] : array_map('intval', array_values($excludeMemberIds)))]);

        $latest = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['latest'] !== null) {
                $latest[(int) $row['group_id']] = (string) $row['latest'];
            }
        }

        return $latest;
    }
}
