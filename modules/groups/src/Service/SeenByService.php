<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Member\MemberService;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\Post;

/**
 * Who has seen one post — the dialog behind the "vu par N" line.
 *
 * Read-only and one post at a time, the same shape as Service\
 * ReactorListService: the feed's counts are batched
 * (Service\GroupReadStateService::seenCountsForPosts()), but the names
 * are only ever asked for the single post whose dialog just opened.
 *
 * "Seen" here means "opened the group after this post was published",
 * which is what discussion_group_reads records. It is deliberately not a
 * per-post receipt: nobody needs to know a message was scrolled past
 * rather than read, and a per-post table would cost a row per member per
 * post to say something less true than it looks.
 *
 * Who may ask is NOT decided here — Controller\PostController::seenBy()
 * restricts it to the post's own author, and its docblock says why.
 */
class SeenByService
{
    public function __construct(
        private GroupReadStateService $readStateService,
        private MemberService $memberService
    ) {
    }

    /**
     * @return string[] display names, alphabetical
     */
    public function namesForPost(DiscussionGroup $group, Post $post, int $scoutYearId): array
    {
        $memberIds = array_values(array_filter(
            $this->readStateService->membersWhoSaw($group, $post->createdAt),
            // The author's own visit never counts as having seen their
            // own message — same rule as the count on the card.
            fn (int $memberId) => $memberId !== $post->authorMemberId
        ));

        if ($memberIds === []) {
            return [];
        }

        // A member who has since left the unit has no display name for
        // this scout year any more; dropped silently, exactly as
        // Service\ReactorListService drops one.
        $names = array_values($this->memberService->findDisplayNamesByMemberIds($memberIds, $scoutYearId));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }
}
