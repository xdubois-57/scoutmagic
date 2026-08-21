<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\Reply;

/**
 * Author identities for a whole page of posts, resolved once for the
 * whole set. Never one lookup per post: a feed page is exactly where an
 * N+1 would hurt most, which is why this class exists at all.
 *
 * WHICH identity is Service\MemberIdentityService's rule, applied
 * identically on every surface of this module: the account first — the
 * human who actually typed it — with the memberships that account carries
 * in parentheses, "Marie Dupont (Akéla, Baloo)".
 *
 * This used to read the other way round ("Akéla (Marie Dupont)"), which
 * put a child's totem in front of a message a parent had written and made
 * the same person read differently here than in "qui a réagi" or on the
 * members page, where only the totem appeared. The account is the human;
 * the memberships are what that human has.
 *
 * Keyed on the AUTHOR'S ACCOUNT, not on the member the post was signed
 * as: that member is one of the ones in the parentheses, and the
 * distinction between them is stored (author_member_id, which still
 * decides read state, "vu par" and the rate limit) rather than displayed.
 */
class PostAuthorResolver
{
    public function __construct(private MemberIdentityService $identityService)
    {
    }

    /**
     * Posts and replies alike: both carry the same three properties this
     * reads (id, authorMemberId, authorUserAccountId), so one page's posts
     * AND their replies can be resolved together in the same two queries
     * rather than two more per post.
     *
     * The returned array is keyed by the item's own id, so callers must not
     * mix posts and replies in a single call — their ids come from
     * different sequences and would collide. Service\GroupFeedService makes
     * two calls for that reason.
     *
     * @param array<int, Post|Reply> $items
     * @return array<int, array{account_name: string, member_names: string[]}> item id => identity
     */
    public function resolve(array $items, int $scoutYearId): array
    {
        if ($items === []) {
            return [];
        }

        $identities = $this->identityService->forAccounts(
            array_map(fn(Post|Reply $item) => $item->authorUserAccountId, $items),
            $scoutYearId
        );

        $labels = [];
        foreach ($items as $item) {
            // An account deleted since it wrote this leaves the message
            // standing and unattributed rather than taking it with it —
            // the same posture as a member who has left the unit, whose
            // membership simply stops appearing in the parentheses.
            $labels[$item->id] = $identities[$item->authorUserAccountId]
                ?? ['account_name' => '', 'member_names' => []];
        }

        return $labels;
    }
}
