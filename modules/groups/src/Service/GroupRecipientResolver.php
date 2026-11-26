<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Config\ScoutYearService;
use Core\Import\MemberYearRepository;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionMembershipRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\StaffYearEligibility;
use Core\Security\DecryptionException;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupSectionRepository;

/**
 * Who gets notified about something happening in a group — the audience
 * for Service\GroupNotificationService's four dispatches.
 *
 * Three rules this class exists to hold:
 *
 * - **The audience is membership, never a role.** A group's readers are
 *   its explicitly invited members plus everyone with a period in one of
 *   its sections for its year — exactly the rule Service\
 *   GroupAccessService::canRead() applies from the other direction, read
 *   here group-first instead of account-first. The type's own `role_min`
 *   is a floor NotificationService::dispatch() re-checks; it is not the
 *   audience and never widens it.
 * - **Resolved at dispatch time, never at post time.** Everything below
 *   reads current membership, so a member who left the section between the
 *   post and the send drops out on their own, with nothing to invalidate.
 * - **One account, one notification.** An account linked to several
 *   members of the same group — a parent with two children in the same
 *   section is the ordinary case, not the edge case — appears exactly once
 *   in the returned list.
 *
 * Members are resolved to accounts through the blind index that already
 * backs login (Core\Security\RoleResolver): the member's Desk address
 * (member_years.email_blind_index) and any currently-valid secondary
 * address (member_emails). No new matching mechanism, and no address is
 * ever decrypted on this path — a blind index goes in, an account id comes
 * out.
 */
class GroupRecipientResolver
{
    public function __construct(
        private GroupMemberRepository $memberRepository,
        private GroupSectionRepository $sectionRepository,
        private SectionMembershipRepository $membershipRepository,
        private MemberYearRepository $memberYearRepository,
        private MemberEmailRepository $memberEmailRepository,
        private UserAccountRepository $userAccountRepository,
        private ?RoleResolver $roleResolver = null,
        private ?ScoutYearService $scoutYearService = null,
        private ?ScoutYearResolver $scoutYearResolver = null
    ) {
    }

    private ?StaffYearEligibility $staffYearEligibility = null;

    private ?MemberAccountResolver $memberAccounts = null;

    /**
     * Everyone who can currently read $group, as dispatch() wants them.
     *
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    public function forGroup(DiscussionGroup $group, int $effectiveScoutYearId): array
    {
        return $this->toRecipients($this->memberIdsFor($group, $effectiveScoutYearId));
    }

    /**
     * The group's moderators only: the explicit is_moderator rows, plus
     * every site admin — the same two sources Service\GroupAccessService::
     * canModerate() accepts, so "who may act on a report" and "who is told
     * about one" can never drift apart.
     *
     * A grant names ONE login (schema.sql), so the notification goes to
     * that account and not to every account that happens to reach the same
     * member: the other address cannot act on the report, and telling it
     * about one would be telling somebody a report exists for nothing. A
     * row whose grant names nobody moderates nothing and is skipped here
     * for exactly the same reason.
     *
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    public function moderatorsFor(DiscussionGroup $group): array
    {
        $resolved = [];
        foreach ($this->memberRepository->findByGroup($group->id) as $member) {
            if ($member->isModerator && $member->moderatorUserAccountId !== null) {
                $resolved[] = ['userAccountId' => $member->moderatorUserAccountId, 'memberId' => $member->memberId];
            }
        }

        // Site admins moderate every group without holding a row in any of
        // them, so they are added by account id directly — there is no
        // member id to attach, and dispatch() takes null for it.
        foreach ($this->siteAdminAccountIds() as $accountId) {
            $resolved[] = ['userAccountId' => $accountId, 'memberId' => null];
        }

        return $this->deduplicate($resolved);
    }

    /**
     * The group's moderators PLUS every site admin — the escalated
     * audience for a report about a moderator's own content.
     *
     * moderatorsFor() already includes site admins, so this is currently
     * the same set; it exists as its own method because the two answer
     * different questions and only one of them is allowed to change. If
     * moderatorsFor() ever narrows (a site admin opting out of routine
     * group moderation is a reasonable future setting), the escalated
     * audience must NOT narrow with it.
     *
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    public function moderatorsAndSiteAdminsFor(DiscussionGroup $group): array
    {
        $resolved = $this->moderatorsFor($group);
        foreach ($this->siteAdminAccountIds() as $accountId) {
            $resolved[] = ['userAccountId' => $accountId, 'memberId' => null];
        }

        return $this->deduplicate($resolved);
    }

    /**
     * Whether $userAccountId holds the moderator flag in this group.
     *
     * The ACCOUNT, not the member: a grant names one login, so "is the
     * person a report is about one of the people who would judge it" is a
     * question about the login that wrote the content — the other address
     * reaching the same member judges nothing and is nobody's conflict of
     * interest.
     *
     * Explicit rows only, deliberately: a site admin is not part of that
     * conflict — they are the escalation target, not the thing being
     * escalated away from.
     */
    public function isExplicitModeratorAccount(DiscussionGroup $group, int $userAccountId): bool
    {
        foreach ($this->memberRepository->findByGroup($group->id) as $member) {
            if ($member->isModerator && $member->moderatorUserAccountId === $userAccountId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everyone in $group except the accounts $excludedAccountIds names —
     * how the actor is kept out of a notification that would be
     * meaningless to them ("someone replied to your post" when the someone
     * is you).
     *
     * dispatch()'s own $actorUserAccountId only suppresses the PUSH; the
     * in-app row is still written, which is right for "a new post in a
     * group you follow" but wrong for a notification whose entire subject
     * is that somebody else acted.
     *
     * @param int[] $excludedAccountIds
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    public function forGroupExcluding(
        DiscussionGroup $group,
        int $effectiveScoutYearId,
        array $excludedAccountIds
    ): array
    {
        return $this->excluding($this->forGroup($group, $effectiveScoutYearId), $excludedAccountIds);
    }

    /**
     * @param array<int, array{userAccountId: int, memberId: ?int}> $recipients
     * @param int[] $excludedAccountIds
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    public function excluding(array $recipients, array $excludedAccountIds): array
    {
        if ($excludedAccountIds === []) {
            return $recipients;
        }

        $excluded = array_flip(array_map('intval', $excludedAccountIds));

        return array_values(array_filter(
            $recipients,
            static fn(array $recipient): bool => !isset($excluded[$recipient['userAccountId']])
        ));
    }

    /**
     * Whether $memberId is currently in $group's audience — the same
     * question forGroup() answers for everyone, asked about one person.
     * Used to notify an item's author only while they can still open the
     * group: notifying someone about a page that would 404 for them is
     * worse than not notifying them at all.
     */
    public function isCurrentMember(DiscussionGroup $group, int $memberId, int $effectiveScoutYearId): bool
    {
        return in_array($memberId, $this->memberIdsFor($group, $effectiveScoutYearId), true);
    }

    /**
     * Of these ACCOUNTS, the ones that can still open $group — as
     * dispatch() wants them, once each, in the order given.
     *
     * The account-first twin of forGroup(), for an audience made of the
     * people who WROTE something rather than of memberships: the
     * participants of a thread a new reply notifies (Service\
     * GroupNotificationService::replyReceived(), issue #1349). Each
     * account is the login that typed, so the question is the one login
     * asks — which members does this address reach in the effective year
     * (its Desk address, then its confirmed secondary ones, exactly as
     * Core\Member\MemberService::getLinkedMembers() resolves them, through
     * the blind index and with nothing decrypted) — and whether one of
     * them is in the group right now, the same membership forGroup()
     * reads. That is Service\GroupAccessService::canRead() asked from the
     * account's side, at send time: somebody who left the section since
     * they wrote drops out on their own.
     *
     * **In each account's OWN authorization year**, not the actor's: during
     * a year transition the staff year is served only to the accounts that
     * reach `intendant` in it (Core\ScoutYear\ScoutYearResolver), so the
     * chief who replies and the parent who answered before them open the
     * group in two different years. Judging the parent in the chief's
     * year could hand them a reply's text for a group their own session
     * would refuse. $effectiveScoutYearId is only the fallback when this
     * resolver was built without a ScoutYearResolver (a narrow unit test,
     * never the composition root).
     *
     * An account none of whose members is in the group can still open it
     * when it is a site admin, the one bypass canRead() knows — the author
     * of issue #158, who posts with a borrowed member and must still hear
     * the answers. Such a row carries `memberId: null`, as moderatorsFor()
     * does.
     *
     * @param int[] $accountIds
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    public function accountsThatCanRead(DiscussionGroup $group, int $effectiveScoutYearId, array $accountIds): array
    {
        $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
        if ($accountIds === []) {
            return [];
        }

        $indexes = $this->userAccountRepository->findEmailBlindIndexesByIds($accountIds);

        // Membership and Desk links are read once per distinct year, which
        // outside a transition is one year for everybody.
        $inGroupByYear = [];
        $deskMembersByYear = [];

        $recipients = [];
        foreach ($accountIds as $accountId) {
            $index = $indexes[$accountId] ?? null;
            if ($index === null || $index === '') {
                // No such account any more: nobody to tell.
                continue;
            }

            $yearId = $this->authorizationYearIdFor($accountId, $effectiveScoutYearId);
            $inGroupByYear[$yearId] ??= array_flip($this->memberIdsFor($group, $yearId));
            $deskMembersByYear[$yearId] ??= $this->memberYearRepository->findMemberIdsByEmailBlindIndexes(
                array_values($indexes),
                $yearId
            );

            $memberId = $this->firstMemberInGroup(
                [...($deskMembersByYear[$yearId][$index] ?? []), ...$this->secondaryMemberIds($index, $yearId)],
                $inGroupByYear[$yearId]
            );
            if ($memberId !== null) {
                $recipients[] = ['userAccountId' => $accountId, 'memberId' => $memberId];
            } elseif ($this->isSiteAdminAccount($accountId)) {
                $recipients[] = ['userAccountId' => $accountId, 'memberId' => null];
            }
        }

        return $this->deduplicate($recipients);
    }

    /**
     * The year this account's own session would decide access in —
     * GroupSessionContextFactory's year (ScoutYearResolver::
     * getAuthorizationYear()) computed for an account that is not the one
     * signed in: the staff year when it is served to this account (it
     * reaches `intendant` in it, through the same Core\ScoutYear\
     * StaffYearEligibility the request uses, and the year is adjacent to
     * the public one), the public year otherwise.
     *
     * The address is decrypted only if a staff year is configured at all,
     * which is the only branch that asks for it; one that cannot be read
     * is not served the staff year, the safe direction.
     */
    private function authorizationYearIdFor(int $accountId, int $fallbackYearId): int
    {
        if ($this->scoutYearResolver === null) {
            return $fallbackYearId;
        }

        return $this->scoutYearResolver->getEffectiveYear(
            null,
            Role::PUBLIC,
            function (int $staffYearId) use ($accountId): bool {
                if ($this->roleResolver === null) {
                    return false;
                }
                try {
                    $account = $this->userAccountRepository->findById($accountId);
                } catch (DecryptionException) {
                    return false;
                }
                if ($account === null) {
                    return false;
                }

                $this->staffYearEligibility ??= new StaffYearEligibility($this->roleResolver);

                return $this->staffYearEligibility->isEligible($account->email, $staffYearId);
            }
        )->id;
    }

    /**
     * The members a confirmed secondary address reaches in that year —
     * login's second path (MemberService::memberYearRowsViaSecondaryEmail()):
     * the address names members, and only those with an active record for
     * the year count.
     *
     * @return int[]
     */
    private function secondaryMemberIds(string $blindIndex, int $scoutYearId): array
    {
        $memberIds = $this->memberEmailRepository->findMemberIdsByValidBlindIndex($blindIndex);
        if ($memberIds === []) {
            return [];
        }

        return array_map(
            static fn(array $row): int => (int) $row['member_id'],
            $this->memberYearRepository->findAllByMemberIds($memberIds, $scoutYearId)
        );
    }

    /**
     * @param int[] $memberIds
     * @param array<int, int> $inGroup member id => anything
     */
    private function firstMemberInGroup(array $memberIds, array $inGroup): ?int
    {
        foreach ($memberIds as $memberId) {
            if (isset($inGroup[$memberId])) {
                return $memberId;
            }
        }

        return null;
    }

    /**
     * One member id to the account that reads their mail, or null when no
     * account has ever been created for them (an animé with no login of
     * their own — the common case for a young member, not an error).
     */
    public function accountIdForMember(int $memberId): ?int
    {
        return $this->accountIdsForMember($memberId)[0] ?? null;
    }

    /**
     * EVERY account that can log in as this member: their Desk address
     * first, then any confirmed secondary one — the same two sources, in
     * the same order, that login itself resolves (SECURITY.md §2).
     *
     * accountIdForMember() above answers "where does their mail go" and
     * takes the first; this answers "who could be sitting behind this
     * membership", which is the question the moderator grant asks, since
     * the flag names one login and a member can be reachable by several.
     *
     * @return int[] account ids, Desk address first
     */
    public function accountIdsForMember(int $memberId): array
    {
        return $this->memberAccounts()->accountIdsForMember($memberId);
    }

    /**
     * Built here rather than injected so this class keeps the exact
     * constructor its ~40 existing call sites pass — the resolution it
     * wraps is core's (Core\Member\MemberAccountResolver), and this is
     * the one place that had a second copy of it.
     */
    private function memberAccounts(): MemberAccountResolver
    {
        return $this->memberAccounts ??= new MemberAccountResolver(
            $this->memberYearRepository,
            $this->memberEmailRepository,
            $this->userAccountRepository
        );
    }

    /**
     * Every account whose CURRENT role reaches admin — resolved now, the
     * same way GroupSessionContext::isSiteAdmin() resolves it for a
     * request, because "site admin" here is a derived role and not a
     * stored flag: there is no column to select on.
     *
     * O(accounts) role resolutions, which is why only the report
     * notification uses it — reporting is a rare event, unlike posting.
     *
     * Degrades to "explicit moderators only" when built without the
     * resolver (a narrow unit test, never the composition root — the same
     * documented escape hatch as NotificationService's own optional
     * RoleResolver). Under-notifying is the safe direction: a moderator
     * with a real row still hears about it.
     *
     * @return int[]
     */
    private function siteAdminAccountIds(): array
    {
        // Answered before the account sweep rather than once per account:
        // with no resolver nobody can be a site admin, and listing every
        // account to be told that N times is a query for nothing.
        if ($this->roleResolver === null) {
            return [];
        }

        $ids = [];
        foreach ($this->userAccountRepository->findAllIds() as $accountId) {
            if ($this->isSiteAdminAccount($accountId)) {
                $ids[] = $accountId;
            }
        }

        return $ids;
    }

    /**
     * The same question about ONE account, which is what a notification
     * path can afford: one role resolution instead of the O(accounts)
     * sweep above.
     *
     * Public because Service\GroupNotificationService needs exactly this
     * and nothing else. A site admin reads every group without holding a
     * row in any of them — Service\GroupAccessService::canRead() answers
     * `true` on that alone — so "this account can still open the group"
     * and "this account is a site admin" are the same question for
     * somebody the group's own membership does not contain. Asking it
     * here rather than re-deriving the bypass in the notification service
     * is what keeps the two from disagreeing about who may be notified.
     *
     * Degrades to `false` when built without the resolver (a narrow unit
     * test, never the composition root), the same escape hatch and the
     * same safe direction as siteAdminAccountIds(): under-notifying.
     */
    public function isSiteAdminAccount(int $userAccountId): bool
    {
        $currentYearId = $this->scoutYearService?->getCurrentYear()['id'] ?? null;
        if ($this->roleResolver === null || $currentYearId === null) {
            return false;
        }

        try {
            $account = $this->userAccountRepository->findById($userAccountId);
        } catch (DecryptionException) {
            // One unreadable account (e.g. predating a key rotation) must
            // never cost every moderator their notification — same
            // posture as NotificationService::findAccountSafely().
            return false;
        }

        if ($account === null) {
            return false;
        }

        return Role::fromString($this->roleResolver->resolve($account->email, $currentYearId))
            ->hasAccess(Role::ADMIN);
    }

    /**
     * Explicit rows plus derived section membership, deduplicated. A
     * member appearing in both (an invited member who is also in one of
     * the sections) is still one member.
     *
     * Public because Service\MentionService needs exactly this list and
     * nothing else: a mention may only ever resolve to somebody who is
     * currently in the group, and re-deriving "who is in this group"
     * anywhere else would be a second answer waiting to disagree with
     * this one.
     *
     * @return int[]
     */
    public function memberIdsFor(DiscussionGroup $group, int $effectiveScoutYearId): array
    {
        $memberIds = array_map(
            static fn($member): int => $member->memberId,
            $this->memberRepository->findByGroup($group->id)
        );

        // A year-less invitation group resolves its sections against
        // whatever the effective year currently is, a group with a year
        // against that year — the same rule GroupAccessService::
        // hasPeriodInAnySection() applies, so the two never disagree about
        // who is in an archived group.
        $scoutYearId = $group->scoutYearId ?? $effectiveScoutYearId;
        $sectionIds = $this->sectionRepository->findSectionIds($group->id);
        if ($sectionIds !== []) {
            $memberIds = array_merge(
                $memberIds,
                $this->membershipRepository->findMemberIdsForSections($sectionIds, $scoutYearId)
            );
        }

        return array_values(array_unique($memberIds));
    }

    /**
     * @param int[] $memberIds
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function toRecipients(array $memberIds): array
    {
        $recipients = [];
        foreach ($memberIds as $memberId) {
            $accountId = $this->accountIdForMember($memberId);
            if ($accountId !== null) {
                $recipients[] = ['userAccountId' => $accountId, 'memberId' => $memberId];
            }
        }

        return $this->deduplicate($recipients);
    }

    /**
     * First occurrence wins, so the member id kept is the one from the
     * first membership that resolved to this account — arbitrary but
     * stable, and only ever used as context on the stored row.
     *
     * @param array<int, array{userAccountId: int, memberId: ?int}> $recipients
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function deduplicate(array $recipients): array
    {
        $byAccount = [];
        foreach ($recipients as $recipient) {
            $byAccount[$recipient['userAccountId']] ??= $recipient;
        }

        return array_values($byAccount);
    }

}
