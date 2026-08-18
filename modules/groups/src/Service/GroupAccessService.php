<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Member\SectionMembershipRepository;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupSectionRepository;

/**
 * Who may read, post in, and moderate a group.
 *
 * Membership has one mechanism and two sources, unioned:
 *
 * - derived — every member with a membership period in one of the group's
 *   sections for the group's scout year. Never materialised as rows:
 *   resolved per request against Core\Member\SectionMembershipRepository,
 *   so a Desk import that moves a member between sections takes effect on
 *   the next request with nothing to re-sync.
 * - explicit — a discussion_group_members row, which also carries the
 *   moderator flag.
 *
 * hasAnyPeriod() is the right granularity, the same "was a member that
 * year" reasoning Core\Member\SectionDocumentOwnershipChecker applies to
 * section documents: membership history only, never section visibility. A
 * section that is hidden or made inactive must not silently cut off the
 * members who legitimately belong to its group.
 *
 * Everything is decided from an already-resolved GroupSessionContext —
 * never $_SESSION, never a cached membership: the effective scout year is
 * re-resolved by the Controller on every request.
 */
class GroupAccessService
{
    public function __construct(
        private GroupMemberRepository $memberRepository,
        private GroupSectionRepository $sectionRepository,
        private SectionMembershipRepository $membershipRepository
    ) {
    }

    public function canRead(DiscussionGroup $group, GroupSessionContext $context): bool
    {
        if ($context->isSiteAdmin()) {
            return true;
        }

        if ($this->explicitRowFor($group, $context) !== null) {
            return true;
        }

        return $this->isDerivedMember($group, $context);
    }

    /**
     * canRead() plus: the group is open, its scout year is the effective
     * one (a past-year group is readable but read-only — that is the whole
     * archive case), and the account has a usable identity to sign a post
     * with. Each refusal carries its own French message so the composer
     * can explain itself.
     */
    public function canPost(DiscussionGroup $group, GroupSessionContext $context): PostPermission
    {
        if (!$this->canRead($group, $context)) {
            return PostPermission::deny(
                PostPermission::REASON_NOT_MEMBER,
                'Vous ne faites pas partie de ce groupe.'
            );
        }

        if ($group->isClosed()) {
            return PostPermission::deny(
                PostPermission::REASON_CLOSED,
                'Ce groupe est clôturé : vous pouvez encore le consulter, mais plus y publier.'
            );
        }

        if ($group->scoutYearId !== null && $group->scoutYearId !== $context->effectiveScoutYearId) {
            return PostPermission::deny(
                PostPermission::REASON_PAST_YEAR,
                'Ce groupe appartient à une année scoute passée : il est consultable, mais en lecture seule.'
            );
        }

        if (!$context->hasCompleteProfile) {
            return PostPermission::deny(
                PostPermission::REASON_INCOMPLETE_PROFILE,
                'Renseignez votre prénom et votre nom pour pouvoir publier : ils accompagnent chacun de vos messages.',
                '/account',
                'Mon compte'
            );
        }

        return PostPermission::allow();
    }

    /**
     * A moderator is an explicitly-flagged member, or a site admin. The
     * flag lives on discussion_group_members and nowhere else — there is
     * no second table and no role string.
     */
    public function canModerate(DiscussionGroup $group, GroupSessionContext $context): bool
    {
        if ($context->isSiteAdmin()) {
            return true;
        }

        $row = $this->explicitRowFor($group, $context);

        return $row !== null && $row->isModerator;
    }

    /**
     * Which of the caller's own linked members actually belong to this
     * group — what the post composer offers when an account is linked to
     * several members (a parent with three children must never be able to
     * post as the one who is not a member of this group).
     *
     * @return int[] members.id values, in the caller's own order
     */
    public function memberIdsAllowedToPostAs(DiscussionGroup $group, GroupSessionContext $context): array
    {
        $allowed = [];
        foreach ($context->linkedMemberIds as $memberId) {
            if ($this->memberRepository->find($group->id, $memberId) !== null
                || $this->hasPeriodInAnySection($group, $context, $memberId)
            ) {
                $allowed[] = $memberId;
            }
        }

        return $allowed;
    }

    private function explicitRowFor(DiscussionGroup $group, GroupSessionContext $context): ?\Modules\Groups\Repository\GroupMember
    {
        foreach ($context->linkedMemberIds as $memberId) {
            $row = $this->memberRepository->find($group->id, $memberId);
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    private function isDerivedMember(DiscussionGroup $group, GroupSessionContext $context): bool
    {
        foreach ($context->linkedMemberIds as $memberId) {
            if ($this->hasPeriodInAnySection($group, $context, $memberId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A year-less invitation group has no year of its own to resolve
     * derived membership against, so its invited sections resolve against
     * whatever the effective year currently is — "the members of section X
     * now". A group with a year always resolves against that year, which
     * is what keeps an archived group readable by exactly the people who
     * were there.
     */
    private function hasPeriodInAnySection(DiscussionGroup $group, GroupSessionContext $context, int $memberId): bool
    {
        $scoutYearId = $group->scoutYearId ?? $context->effectiveScoutYearId;

        foreach ($this->sectionRepository->findSectionIds($group->id) as $sectionId) {
            if ($this->membershipRepository->hasAnyPeriod($memberId, $sectionId, $scoutYearId)) {
                return true;
            }
        }

        return false;
    }
}
