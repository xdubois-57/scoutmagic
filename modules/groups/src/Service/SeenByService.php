<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

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
        private MemberIdentityService $identityService
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

        // Named the way this module names anybody — the account, then its
        // memberships (Service\MemberIdentityService). Deduplicated on the
        // rendered line for the same reason the reactors list is: a parent
        // whose two children have both opened the group is one person who
        // has seen the message, not two.
        $names = [];
        foreach ($this->identityService->forMembers($memberIds, $scoutYearId) as $identity) {
            $label = MemberIdentityService::label($identity);
            // A member who has since left the unit, with no account
            // either, has nothing left to name them by; dropped silently,
            // exactly as Service\ReactorListService drops one.
            if ($label !== '') {
                $names[$label] = true;
            }
        }

        $names = array_keys($names);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }
}
