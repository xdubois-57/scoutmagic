<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * discussion_group_post_links — at most one row per post, exactly like a
 * post's media rows live in Repository\PostMediaRepository, but this join
 * is 1:1 rather than 1:N.
 */
class PostLinkRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function attach(int $postId, string $url, ?string $title, ?string $description, ?int $imageFileId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_post_links (post_id, url, title, description, image_file_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$postId, $url, $title, $description, $imageFileId, date('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findForPost(int $postId): ?PostLink
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, post_id, url, title, description, image_file_id
             FROM discussion_group_post_links WHERE post_id = ?'
        );
        $stmt->execute([$postId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Batched form of findForPost() for a whole feed page —
     * Service\GroupFeedService resolves every post's link in one query
     * rather than one per post, same precedent as
     * PostMediaRepository::findMediaIdsForPosts().
     *
     * @param int[] $postIds
     * @return array<int, PostLink> postId => its link
     */
    public function findForPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, post_id, url, title, description, image_file_id
             FROM discussion_group_post_links WHERE post_id IN ({$placeholders})"
        );
        $stmt->execute($postIds);

        $byPost = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $link = $this->hydrate($row);
            $byPost[$link->postId] = $link;
        }

        return $byPost;
    }

    public function deleteForPost(int $postId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM discussion_group_post_links WHERE post_id = ?');
        $stmt->execute([$postId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PostLink
    {
        return new PostLink(
            id: (int) $row['id'],
            postId: (int) $row['post_id'],
            url: (string) $row['url'],
            title: $row['title'] !== null ? (string) $row['title'] : null,
            description: $row['description'] !== null ? (string) $row['description'] : null,
            imageFileId: $row['image_file_id'] !== null ? (int) $row['image_file_id'] : null
        );
    }
}
