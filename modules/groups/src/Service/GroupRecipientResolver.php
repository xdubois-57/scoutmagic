<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Config\ScoutYearService;
use Core\Import\MemberYearRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionMembershipRepository;
use Core\Security\DecryptionException;
use Core\Security\EncryptionService;
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
        private EncryptionService $encryption,
        private ?RoleResolver $roleResolver = null,
        private ?ScoutYearService $scoutYearService = null
    ) {
    }

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
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    public function moderatorsFor(DiscussionGroup $group): array
    {
        $recipients = [];
        foreach ($this->memberRepository->findByGroup($group->id) as $member) {
            if ($member->isModerator) {
                $recipients[] = $member->memberId;
            }
        }

        $resolved = $this->toRecipients($recipients);

        // Site admins moderate every group without holding a row in any of
        // them, so they are added by account id directly — there is no
        // member id to attach, and dispatch() takes null for it.
        foreach ($this->siteAdminAccountIds() as $accountId) {
            $resolved[] = ['userAccountId' => $accountId, 'memberId' => null];
        }

        return $this->deduplicate($resolved);
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
    public function forGroupExcluding(DiscussionGroup $group, int $effectiveScoutYearId, array $excludedAccountIds): array
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
     * One member id to the account that reads their mail, or null when no
     * account has ever been created for them (an animé with no login of
     * their own — the common case for a young member, not an error).
     */
    public function accountIdForMember(int $memberId): ?int
    {
        foreach ($this->blindIndexesForMember($memberId) as $blindIndex) {
            $account = $this->userAccountRepository->findByBlindIndex($blindIndex);
            if ($account !== null) {
                return $account->id;
            }
        }

        return null;
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
        $currentYearId = $this->scoutYearService?->getCurrentYear()['id'] ?? null;
        if ($this->roleResolver === null || $currentYearId === null) {
            return [];
        }

        $ids = [];
        foreach ($this->userAccountRepository->findAllIds() as $accountId) {
            try {
                $account = $this->userAccountRepository->findById($accountId);
            } catch (DecryptionException) {
                // One unreadable account (e.g. predating a key rotation)
                // must never cost every moderator their notification —
                // same posture as NotificationService::findAccountSafely().
                continue;
            }
            if ($account === null) {
                continue;
            }

            $role = Role::fromString($this->roleResolver->resolve($account->email, $currentYearId));
            if ($role->hasAccess(Role::ADMIN)) {
                $ids[] = $accountId;
            }
        }

        return $ids;
    }

    /**
     * Explicit rows plus derived section membership, deduplicated. A
     * member appearing in both (an invited member who is also in one of
     * the sections) is still one member.
     *
     * @return int[]
     */
    private function memberIdsFor(DiscussionGroup $group, int $effectiveScoutYearId): array
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

    /**
     * Every blind index that could name this member's account: the Desk
     * address first (the one Core\Import\DeskImportService creates the
     * account under), then any currently-valid secondary address. A
     * 'pending' or 'inactive' secondary never resolves a login
     * (Core\Security\RoleResolver) and must not resolve a notification
     * either.
     *
     * @return string[]
     */
    private function blindIndexesForMember(int $memberId): array
    {
        $indexes = [];

        $deskIndex = $this->memberYearRepository->findMostRecentEmailBlindIndexForMember($memberId);
        if ($deskIndex !== null && $deskIndex !== '') {
            $indexes[] = $deskIndex;
        }

        foreach ($this->memberEmailRepository->findValidByMember($memberId) as $secondary) {
            $indexes[] = $this->encryption->blindIndex(strtolower(trim($secondary->email)));
        }

        return array_values(array_unique($indexes));
    }
}
