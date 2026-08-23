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
     * May this session START a conversation here — canParticipate() plus
     * the group's own posting policy.
     *
     * The policy is about posts and nothing else: a group set to
     * "moderators only" still lets every member comment, react and answer
     * a poll (canParticipate(), which is what those actions check). A
     * group nobody may answer is not a restricted group, it is a
     * noticeboard, and "clôturé" already means that.
     *
     * Staff d'U needs no grant to publish in such a group: a site admin
     * is an implicit moderator of every group (GroupSessionContext::
     * isSiteAdmin(), which canModerate() reads first).
     */
    public function canPost(DiscussionGroup $group, GroupSessionContext $context): PostPermission
    {
        $participation = $this->canParticipate($group, $context);
        if (!$participation->allowed) {
            return $participation;
        }

        if ($group->postingPolicy === DiscussionGroup::POSTING_MODERATORS && !$this->canModerate($group, $context)) {
            return PostPermission::deny(
                PostPermission::REASON_MODERATORS_ONLY,
                'Dans ce groupe, seuls les modérateurs publient des messages. Vous pouvez commenter, réagir et répondre aux sondages.'
            );
        }

        return PostPermission::allow();
    }

    /**
     * canRead() plus: the group is open, its scout year is the effective
     * one (a past-year group is readable but read-only — that is the whole
     * archive case), and the account has a usable identity to sign a post
     * with. Each refusal carries its own French message so the composer
     * can explain itself.
     *
     * This is what every write that ANSWERS something already said checks
     * — a comment, a reaction, a vote, an edit of one's own message. What
     * starts a new conversation goes through canPost() above, which adds
     * the group's posting policy on top.
     */
    public function canParticipate(DiscussionGroup $group, GroupSessionContext $context): PostPermission
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
     * A moderator is a LOGIN that was granted the flag in this group, or a
     * site admin. The flag lives on discussion_group_members and nowhere
     * else — there is no second table and no role string — but it names
     * the user_accounts row it was granted to, and only that account
     * moderates through it.
     *
     * That last part is the whole rule: two addresses can reach the same
     * member (a parent's own, plus a secondary one they confirmed), and
     * granting the flag to one of them must not hand it to the other. So
     * the row is looked up by member — membership is a fact about a
     * member — and then the grant is checked against the account
     * currently identified. A row whose grant names nobody (NULL, granted
     * before this rule) moderates nothing.
     */
    public function canModerate(DiscussionGroup $group, GroupSessionContext $context): bool
    {
        if ($context->isSiteAdmin()) {
            return true;
        }

        if ($context->userAccountId === null) {
            return false;
        }

        foreach ($context->linkedMemberIds as $memberId) {
            $row = $this->memberRepository->find($group->id, $memberId);
            if ($row !== null && $row->isModerator && $row->moderatorUserAccountId === $context->userAccountId) {
                return true;
            }
        }

        return false;
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

    /**
     * Which members this account may answer a member-scoped poll for —
     * ALL of its own linked members, not only the ones who belong to
     * this group.
     *
     * Deliberately WIDER than memberIdsAllowedToPostAs() above, because
     * the two questions are different ones. Posting is signing: a
     * message signed as a member of another section would be signed by
     * somebody who is not here. Answering "un parent répond pour chaque
     * enfant" is counting heads: the poll asks a parent about their
     * children, and a family of four whose four children all reach this
     * account has four answers to give. Offering two of them does not
     * protect anything — it silently produces a count that is short, and
     * a wrong count is worse than an answer somebody has to correct.
     *
     * What it does NOT widen is the gate. Nothing here is read before
     * canParticipate() has said this account may write in this group,
     * every answer is still recorded against a member this account
     * really reaches, and one member still answers once. It is also the
     * set the feed already reads its own answers back with — Service\
     * GroupFeedService hands every linked member to the poll service —
     * which is where the two used to disagree: an account could see its
     * own answer highlighted on a poll the picker no longer let it
     * change.
     *
     * The group's own members come first, so the picker's default — what
     * a no-JavaScript submit sends, and what Service\PollService falls
     * back to when the form names nobody — stays somebody this group is
     * actually about.
     *
     * @return int[] members.id values, group members first
     */
    public function memberIdsAllowedToVoteAs(DiscussionGroup $group, GroupSessionContext $context): array
    {
        $sides = $this->memberIdsAllowedToVoteAsBySide($group, $context);

        return array_merge($sides['in_group'], $sides['elsewhere']);
    }

    /**
     * The same members, kept on the two sides the picker has to be able
     * to name: the ones this group is built from, and the ones this
     * account reaches from outside it.
     *
     * Offering the second kind without saying so would answer one
     * question by asking another — a parent scanning four totems cannot
     * tell which of them the group is even about. The split is resolved
     * HERE rather than by asking both questions from the Controller, so
     * the picker's two halves and the set a vote is checked against are
     * one membership resolution and can never disagree.
     *
     * @return array{in_group: int[], elsewhere: int[]} members.id values,
     *         each side in the caller's own order
     */
    public function memberIdsAllowedToVoteAsBySide(DiscussionGroup $group, GroupSessionContext $context): array
    {
        $inGroup = $this->memberIdsAllowedToPostAs($group, $context);

        return [
            'in_group' => $inGroup,
            'elsewhere' => array_values(array_diff($context->linkedMemberIds, $inGroup)),
        ];
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
