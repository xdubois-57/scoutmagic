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
     * @param Post[] $posts
     * @return array<int, array{display_name: string, account_name: string}> post id => label parts
     */
    public function resolve(array $posts, int $scoutYearId): array
    {
        if ($posts === []) {
            return [];
        }

        $displayNames = $this->memberService->findDisplayNamesByMemberIds(
            array_map(fn(Post $p) => $p->authorMemberId, $posts),
            $scoutYearId
        );
        $accountNames = $this->userAccountRepository->findNamesByIds(
            array_map(fn(Post $p) => $p->authorUserAccountId, $posts)
        );

        $labels = [];
        foreach ($posts as $post) {
            $account = $accountNames[$post->authorUserAccountId] ?? ['first_name' => null, 'last_name' => null];
            $accountName = trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? ''));

            $labels[$post->id] = [
                // A member who has left the unit has no member_year for
                // this year any more, so their display name is gone —
                // the post stays, attributed to the account that wrote it.
                'display_name' => $displayNames[$post->authorMemberId] ?? $accountName,
                'account_name' => $accountName,
            ];
        }

        return $labels;
    }
}
