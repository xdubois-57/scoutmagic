<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Support;

use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\MemberIdentityService;

/**
 * What a member-scoped poll's "Vous répondez pour" picker offers, named
 * and sided.
 *
 * One implementation called from the Controllers rather than written
 * twice — the same reason Support\GroupLabel exists. Two of them need
 * it: Controller\GroupController for the page, and
 * Controller\PostController for the "Charger plus" page, the card a new
 * post returns, and the fragment re-rendered after a vote. Four surfaces
 * that must offer the same people; when this lived in both Controllers
 * they were already one edit away from disagreeing.
 *
 * Static, and handed the two collaborators it reads, because it holds
 * nothing: it is the assembly of an answer both Controllers already have
 * the parts for (they each inject Service\GroupAccessService and, when
 * the module is fully wired, Service\MemberIdentityService), not a new
 * dependency for a composition root to carry.
 *
 * Two rules live here and nowhere else:
 *
 * - **Who is offered** is Service\GroupAccessService::
 *   memberIdsAllowedToVoteAsBySide() — every member the account reaches,
 *   the group's own first, each carrying which side it is on. That
 *   docblock says why answering a poll is a wider question than
 *   publishing in the group.
 * - **Nothing is offered when there is nothing to choose between.** One
 *   member is not a choice, and a dialog asking a question with one
 *   answer is a click for nothing.
 */
final class PollVoterOptions
{
    /**
     * @return array<int, array{id: int, name: string, in_group: bool}>
     */
    public static function forGroup(
        GroupAccessService $access,
        ?MemberIdentityService $identity,
        DiscussionGroup $group,
        GroupSessionContext $context
    ): array {
        $sides = $access->memberIdsAllowedToVoteAsBySide($group, $context);
        $memberIds = array_merge($sides['in_group'], $sides['elsewhere']);
        if (count($memberIds) < 2) {
            return [];
        }

        // Account-first, narrowed to the one membership each option
        // stands for ("Marie Dupont (Akéla)") — three options reading
        // "Marie Dupont (Akéla, Baloo, Chil)" would be three identical
        // options. An unnamed membership keeps its id rather than an
        // empty row: the picker's job is to be pickable.
        $labels = $identity?->accountLabelForMembers(
            $memberIds,
            $group->scoutYearId ?? $context->effectiveScoutYearId
        ) ?? [];

        return array_map(
            static fn(int $memberId): array => [
                'id' => $memberId,
                'name' => ($labels[$memberId] ?? '') !== '' ? $labels[$memberId] : ('Membre #' . $memberId),
                // Which side of the group this membership is on — the
                // picker groups them under two headings rather than
                // mixing four totems a reader cannot tell apart.
                'in_group' => in_array($memberId, $sides['in_group'], true),
            ],
            $memberIds
        );
    }
}
