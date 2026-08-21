<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Member\MemberService;
use Modules\Groups\Repository\DiscussionGroup;

/**
 * "Publier en tant que" — the members of this group the caller may sign a
 * post or a reply as, with their display names resolved in one query.
 *
 * Never every linked member: an account linked to three children must not
 * be offered the one who is not a member here
 * (GroupAccessService::memberIdsAllowedToPostAs()). Fewer than two options
 * means there is nothing to choose, so the selector is not rendered at all.
 *
 * Its own service rather than a Controller method because two controllers
 * render a post card — Controller\GroupController for the group page and
 * Controller\PostController for each "Charger plus" page — and the reply
 * composer inside that card needs the same list on both. A private copy in
 * each would be two chances for the two to disagree about who may sign.
 */
class AuthorOptionsService
{
    public function __construct(
        private GroupAccessService $accessService,
        private MemberService $memberService,
        private ?MemberIdentityService $identityService = null
    ) {
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function forGroup(DiscussionGroup $group, GroupSessionContext $context): array
    {
        $memberIds = $this->accessService->memberIdsAllowedToPostAs($group, $context);
        if (count($memberIds) < 2) {
            return [];
        }

        $scoutYearId = $group->scoutYearId ?? $context->effectiveScoutYearId;

        // Account first, like everywhere else in this module — but with
        // the parentheses narrowed to the one membership each option
        // stands for (MemberIdentityService::accountLabelForMembers()).
        // The full identity would render all three options of a parent
        // with three children as the same string, and this control's
        // whole job is telling them apart:
        //
        //     Marie Dupont (Akéla)
        //     Marie Dupont (Baloo)
        //
        // The repeated human name is the point rather than noise: it is
        // what the message will be signed with, so the selector shows the
        // signature it is choosing between and not a fragment of it.
        $names = $this->memberService->findDisplayNamesByMemberIds($memberIds, $scoutYearId);
        $accountLabels = $this->identityService?->accountLabelForMembers($memberIds, $scoutYearId) ?? [];

        return array_map(
            fn(int $memberId) => [
                'id' => $memberId,
                // No named account behind this membership leaves the
                // totem standing alone, which is all there is to say.
                'name' => ($accountLabels[$memberId] ?? '') !== ''
                    ? $accountLabels[$memberId]
                    : ($names[$memberId] ?? ('Membre #' . $memberId)),
            ],
            $memberIds
        );
    }
}
