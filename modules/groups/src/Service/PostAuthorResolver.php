<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Member\MemberService;
use Core\Security\UserAccountRepository;
use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\Reply;

/**
 * Author labels for a whole page of posts, in two queries total — one for
 * the members' display names, one for the accounts' real names. Never one
 * lookup per post: a feed page is exactly where an N+1 would hurt most.
 *
 * The label is always both identities — "Akéla (Marie Dupont)" — because
 * each answers a different question: the totem says which member's
 * membership the post came through, the account name says which human
 * actually wrote it. A parent posting for their child is neither hidden
 * behind the child's totem nor mistaken for the child.
 */
class PostAuthorResolver
{
    public function __construct(
        private MemberService $memberService,
        private UserAccountRepository $userAccountRepository
    ) {
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
     * @return array<int, array{display_name: string, account_name: string}> item id => label parts
     */
    public function resolve(array $items, int $scoutYearId): array
    {
        if ($items === []) {
            return [];
        }

        $displayNames = $this->memberService->findDisplayNamesByMemberIds(
            array_map(fn(Post|Reply $item) => $item->authorMemberId, $items),
            $scoutYearId
        );
        $accountNames = $this->userAccountRepository->findNamesByIds(
            array_map(fn(Post|Reply $item) => $item->authorUserAccountId, $items)
        );

        $labels = [];
        foreach ($items as $item) {
            $account = $accountNames[$item->authorUserAccountId] ?? ['first_name' => null, 'last_name' => null];
            $accountName = trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? ''));

            $labels[$item->id] = [
                // A member who has left the unit has no member_year for
                // this year any more, so their display name is gone —
                // the post stays, attributed to the account that wrote it.
                'display_name' => $displayNames[$item->authorMemberId] ?? $accountName,
                'account_name' => $accountName,
            ];
        }

        return $labels;
    }
}
