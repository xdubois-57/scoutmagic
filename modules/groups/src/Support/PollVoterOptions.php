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
 *   memberIdsAllowedToVoteAsBySide() — the members this group would
 *   normally count, each carrying which side of it they are on: its own
 *   section for a section group, every member the account reaches for
 *   any other. That docblock says why the two answers differ.
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
        // options.
        $scoutYearId = $group->scoutYearId ?? $context->effectiveScoutYearId;
        $labels = $identity?->accountLabelForMembers($memberIds, $scoutYearId) ?? [];

        // The fallback for a membership no NAMED account stands behind,
        // which in a section group is most of them: an animé's only login
        // is usually a parent's, under the parent's own name, and a
        // membership with no login at all has no account name to borrow.
        // accountLabelForMembers() answers '' for both on purpose and
        // leaves the fallback to each caller — and the one this picker
        // wants is the name the membership itself carries, the same one
        // "qui a réagi" and the group's member list show. Issue #358: it
        // used to fall straight through to "Membre #40", offering a
        // parent a choice between two numbers.
        $ownNames = $identity?->ownDisplayNames($memberIds, $scoutYearId) ?? [];

        return array_map(
            static fn(int $memberId): array => [
                'id' => $memberId,
                // The bare id survives as the last resort only: a
                // membership whose year is gone has no name left to show,
                // and an option with no label at all is unpickable.
                'name' => self::name($memberId, $labels, $ownNames),
                // Which side of the group this membership is on — the
                // picker groups them under two headings rather than
                // mixing four totems a reader cannot tell apart.
                'in_group' => in_array($memberId, $sides['in_group'], true),
            ],
            $memberIds
        );
    }

    /**
     * @param array<int, string> $labels account-first labels, '' when none
     * @param array<int, string> $ownNames the membership's own display name
     */
    private static function name(int $memberId, array $labels, array $ownNames): string
    {
        foreach ([$labels[$memberId] ?? '', $ownNames[$memberId] ?? ''] as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Membre #' . $memberId;
    }
}
