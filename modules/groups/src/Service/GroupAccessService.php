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
    /**
     * The one sentence for "nothing here can sign this write" —
     * authorMemberIdFor() below returning null, checked again by every
     * action that needs an author member.
     *
     * Reaching it means the composer was rendered on a state
     * canParticipate() refuses, which it no longer is: it is the
     * belt-and-braces server-side twin of that refusal, not the way a
     * member is meant to learn about it.
     */
    public const NO_AUTHOR_MEMBER_MESSAGE = 'Aucun membre de ce groupe n\'est associé à votre compte.';

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

        // A post is recorded against a member (discussion_group_posts.
        // author_member_id, NOT NULL), so a session with no member to
        // record against cannot start a conversation here however wide
        // its role is — and the composer must say that instead of being
        // offered and then refusing the message.
        //
        // HERE and not in canParticipate(), and the difference is not a
        // detail: canParticipate() is also what gates answering a poll,
        // and an ACCOUNT-scoped ballot needs no member at all — Service\
        // PollService::voterFor() records it under `a:{userAccountId}`
        // with a null member_id. Refusing on the shared permission took
        // that vote away from a session the schema was perfectly happy
        // to record. The rule is therefore attached to the operation
        // whose column actually requires a member, never to the
        // permission every write shares.
        //
        // For a member of the group this is unreachable: canRead() passes
        // on exactly the membership authorMemberIdFor() resolves. What it
        // really answers is the site admin who reaches a group through
        // the implicit-moderator bypass with no member of their own
        // anywhere — the one case where the two ever disagree.
        if ($this->authorMemberIdFor($group, $context) === null) {
            return PostPermission::deny(
                PostPermission::REASON_NO_MEMBER_IDENTITY,
                'Aucun membre n\'est associé à votre compte : vos messages ne peuvent être signés par personne. '
                    . 'Demandez à un responsable de rattacher votre compte à un membre.'
            );
        }

        if ($group->postingPolicy === DiscussionGroup::POSTING_MODERATORS && !$this->canModerate($group, $context)) {
            return PostPermission::deny(
                PostPermission::REASON_MODERATORS_ONLY,
                'Dans ce groupe, seuls les modérateurs publient des messages. Vous pouvez commenter, réagir et '
                    . 'répondre aux sondages.'
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
     * The single member a write from this session is recorded against —
     * the one value every NOT NULL `*_member_id` column in this module
     * needs, resolved in one place instead of `…PostAs($group,
     * $context)[0] ?? 0` repeated in five controllers.
     *
     * Normally that is the first of this account's own members that
     * belongs to the group, exactly as before.
     *
     * A **site admin** falls back to the first member the account is
     * linked to at all, in group or not. Staff d'U is an implicit
     * moderator of every group (canPost()'s own docblock says so), yet
     * the composer it was shown refused every message with "Aucun membre
     * de ce groupe n'est associé à votre compte." the moment their own
     * children were in another section — the bypass let them in and the
     * author resolution shut the door behind them.
     *
     * Nothing is mis-signed by the fallback: a post is signed by the
     * ACCOUNT that wrote it (Service\PostAuthorResolver — "the account is
     * the human"), and author_member_id is bookkeeping the reader never
     * sees, carrying read state, "vu par" and the rate limit. It is the
     * admin's own member either way, never somebody else's, and only a
     * role that already reads and moderates the group reaches it.
     *
     * Null when the account has no member at all — canParticipate()
     * refuses on exactly that, so the composer is never offered.
     */
    public function authorMemberIdFor(DiscussionGroup $group, GroupSessionContext $context): ?int
    {
        $allowed = $this->memberIdsAllowedToPostAs($group, $context);
        if ($allowed !== []) {
            return $allowed[0];
        }

        if ($context->isSiteAdmin()) {
            return $context->linkedMemberIds[0] ?? null;
        }

        return null;
    }

    /**
     * Which members this account may answer a member-scoped poll for.
     *
     * The rule is the group's own reach, not the account's: a voter is
     * offered every member of theirs who would normally be counted in
     * THIS conversation.
     *
     * - **A section group** is one section's conversation, so its poll is
     *   that section's question. "Qui vient au week-end ?" asked in
     *   Louveteaux is not asking about an éclaireur, and a parent
     *   answering for one would put a head in a count that cannot use it.
     *   Only the members this group actually holds are offered.
     * - **Any other group** — an invitation group for a camp staff, a
     *   project, the whole unit — draws no section boundary of its own,
     *   so neither does its poll: every member this account reaches is
     *   offered, including the ones the group does not hold. That is what
     *   a parent of four needs when the unit asks a question of the
     *   families; offering two of them silently produces a count that is
     *   short, and a wrong count is worse than an answer somebody has to
     *   correct.
     *
     * Wider than memberIdsAllowedToPostAs() in the second case, and
     * deliberately so: posting is signing — a message signed as a member
     * of another section would be signed by somebody who is not here —
     * while answering a poll is counting heads. What it never widens is
     * the gate: nothing here is read before canParticipate() has said
     * this account may write in this group, every answer is still
     * recorded against a member this account really reaches, and one
     * member still answers once.
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
     * A section group has no second side at all (the rule above), which
     * is also what stops its picker drawing a heading over a list with
     * nothing to distinguish in it.
     *
     * @return array{in_group: int[], elsewhere: int[]} members.id values,
     *         each side in the caller's own order
     */
    public function memberIdsAllowedToVoteAsBySide(DiscussionGroup $group, GroupSessionContext $context): array
    {
        $inGroup = $this->memberIdsAllowedToPostAs($group, $context);
        if ($group->isSectionGroup()) {
            return ['in_group' => $inGroup, 'elsewhere' => []];
        }

        return [
            'in_group' => $inGroup,
            'elsewhere' => array_values(array_diff($context->linkedMemberIds, $inGroup)),
        ];
    }

    private function explicitRowFor(
        DiscussionGroup $group,
        GroupSessionContext $context
    ): ?\Modules\Groups\Repository\GroupMember
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
