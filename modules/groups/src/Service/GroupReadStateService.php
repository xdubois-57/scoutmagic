<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupReadRepository;
use Modules\Groups\Repository\Post;
use Modules\Groups\Support\Timestamps;

/**
 * "This member has now seen this group" — written once per group page
 * view, and the only writer of discussion_group_reads.
 *
 * Authorisation is the Controller's, like every other service here: by the
 * time markRead() runs, the caller has already been confirmed able to read
 * the group. What this class does decide is WHICH member the mark is
 * recorded under, and it uses the same resolution a post or a reaction
 * does (Service\GroupAccessService::memberIdsAllowedToPostAs()) so a
 * parent linked to two children in one group has one reading position in
 * that conversation rather than two that each half-clear the badge.
 *
 * A caller with no member identity in the group at all — a site admin
 * reading a group they are not a member of — records nothing. That is
 * deliberate: an admin looking in must not silently mark the group read
 * for a member they happen to share an id with, and an admin has no unread
 * badge of their own to clear anyway.
 */
class GroupReadStateService
{
    public function __construct(
        private GroupReadRepository $repository,
        private GroupAccessService $accessService
    ) {
    }

    /**
     * Records the visit. Never throws: failing to remember that a group
     * was read is not a reason to fail rendering the group.
     */
    public function markRead(DiscussionGroup $group, GroupSessionContext $context): void
    {
        $memberId = $this->readerMemberId($group, $context);
        if ($memberId === null) {
            return;
        }

        try {
            $this->repository->markRead($group->id, $memberId, Timestamps::now());
        } catch (\PDOException) {
            // A read mark is a convenience, never load-bearing — the page
            // renders identically without it, the badge just stays.
        }
    }

    /**
     * Which member ids count as "has seen everything published before
     * their last visit" for one post — the seen-by list.
     *
     * @return int[]
     */
    public function membersWhoSaw(DiscussionGroup $group, string $postCreatedAt): array
    {
        return $this->repository->membersWhoReadSince($group->id, $postCreatedAt);
    }

    /**
     * How many OTHER members have opened the group since each of these
     * posts was published — one query for the whole page, then compared
     * in PHP (Repository\GroupReadRepository::readMarksForGroup()
     * explains why that way round).
     *
     * The post's own author never counts towards their own post: "vu par
     * 1" that turns out to be yourself is worse than no number at all.
     *
     * @param Post[] $posts
     * @return array<int, int> post id => how many members have seen it
     */
    public function seenCountsForPosts(DiscussionGroup $group, array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $marks = $this->repository->readMarksForGroup($group->id);

        $counts = [];
        foreach ($posts as $post) {
            $seen = 0;
            foreach ($marks as $memberId => $lastReadAt) {
                if ($memberId !== $post->authorMemberId && $lastReadAt >= $post->createdAt) {
                    $seen++;
                }
            }
            $counts[$post->id] = $seen;
        }

        return $counts;
    }

    private function readerMemberId(DiscussionGroup $group, GroupSessionContext $context): ?int
    {
        $allowed = $this->accessService->memberIdsAllowedToPostAs($group, $context);

        return $allowed[0] ?? null;
    }
}
