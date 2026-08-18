<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * The discussion_group_post_media join table — which gallery media (by
 * id, inside the group's own delegated album) belongs to which post, and
 * in what order. Never touches gallery_media itself: that table belongs
 * to a different module and is reached only through Modules\Gallery\Api\
 * DelegatedAlbumManager (Service\PostMediaService).
 */
class PostMediaRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function attach(int $postId, int $galleryMediaId, int $sortOrder): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_post_media (post_id, gallery_media_id, sort_order, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$postId, $galleryMediaId, $sortOrder, date('Y-m-d H:i:s')]);
    }

    /**
     * @return int[] gallery_media ids, in attachment order
     */
    public function findMediaIdsForPost(int $postId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT gallery_media_id FROM discussion_group_post_media WHERE post_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$postId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Batched form of findMediaIdsForPost() for a whole feed page —
     * Service\GroupFeedService resolves every post's media in one query
     * rather than one per post.
     *
     * @param int[] $postIds
     * @return array<int, int[]> postId => gallery_media ids, in attachment order
     */
    public function findMediaIdsForPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT post_id, gallery_media_id FROM discussion_group_post_media
             WHERE post_id IN ({$placeholders})
             ORDER BY post_id ASC, sort_order ASC, id ASC"
        );
        $stmt->execute($postIds);

        $byPost = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byPost[(int) $row['post_id']][] = (int) $row['gallery_media_id'];
        }

        return $byPost;
    }
}
