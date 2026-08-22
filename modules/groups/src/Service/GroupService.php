<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;

/**
 * Creating groups and maintaining their membership. Authorisation is the
 * Controller's job (it re-checks every action through GroupAccessService);
 * this service only performs the writes, so nothing here is a security
 * boundary on its own.
 */
class GroupService
{
    public function __construct(
        private GroupRepository $groupRepository,
        private GroupSectionRepository $sectionRepository,
        private GroupMemberRepository $memberRepository
    ) {
    }

    /**
     * A section group: scoped to one section AND one scout year, its
     * section linked as a derived-membership source. The creating chief
     * becomes a moderator — the only way a group starts with one.
     */
    public function createSectionGroup(
        string $name,
        int $sectionId,
        int $scoutYearId,
        int $createdByMemberId,
        ?int $createdByUserAccountId = null
    ): int {
        $groupId = $this->groupRepository->create($name, $scoutYearId, $sectionId, $createdByMemberId);
        $this->sectionRepository->add($groupId, $sectionId);
        $this->memberRepository->add($groupId, $createdByMemberId, true, null, $createdByUserAccountId);

        return $groupId;
    }

    /**
     * An invitation group: no section of its own, and a scout year only if
     * the chief wants one (schema.sql documents why that column is
     * nullable). Its creator is its first member and its moderator.
     */
    public function createInvitationGroup(
        string $name,
        ?int $scoutYearId,
        int $createdByMemberId,
        ?int $createdByUserAccountId = null
    ): int {
        $groupId = $this->groupRepository->create($name, $scoutYearId, null, $createdByMemberId);
        // The creator is the group's first moderator, and the flag names
        // the login they created it with — not their membership, which
        // another address could also reach.
        $this->memberRepository->add($groupId, $createdByMemberId, true, null, $createdByUserAccountId);

        return $groupId;
    }

    /**
     * Renames a group (any group) and, for an invitation group only,
     * links or unlinks it to a scout year — a section group's year is
     * derived from its section (schema.sql) and never editable, so
     * $scoutYearId is silently ignored when $group->isSectionGroup() is
     * true. That is the actual enforcement of the rule, not just what
     * Controller\GroupController's edit form happens to offer: nothing
     * anywhere in this module can detach a section group from its
     * section's year by calling this method directly.
     *
     * $description is required rather than defaulted so that a caller
     * cannot clear a group's one-liner by forgetting to pass it: null
     * here always means "the moderator emptied the field", never "this
     * caller had nothing to say about it".
     */
    /**
     * @param string|null $postingPolicy one of DiscussionGroup::POSTING_*,
     *        or null to leave the current one alone — which is what a
     *        form that does not carry the control at all means.
     */
    public function edit(
        DiscussionGroup $group,
        string $name,
        ?int $scoutYearId,
        ?string $description,
        ?string $postingPolicy = null
    ): void {
        $this->groupRepository->rename($group->id, $name);
        $this->groupRepository->setDescription($group->id, $description);

        if ($postingPolicy !== null) {
            $this->groupRepository->setPostingPolicy($group->id, $postingPolicy);
        }

        if (!$group->isSectionGroup()) {
            $this->groupRepository->setScoutYearId($group->id, $scoutYearId);
        }
    }

    public function inviteMember(DiscussionGroup $group, int $memberId, int $invitedByMemberId): void
    {
        $this->memberRepository->add($group->id, $memberId, false, $invitedByMemberId);
    }

    /**
     * Inviting a whole section adds a derived-membership source, not a row
     * per member: the section's members are resolved per request, so
     * whoever joins that section afterwards is in the group too.
     */
    public function inviteSection(DiscussionGroup $group, int $sectionId): void
    {
        $this->sectionRepository->add($group->id, $sectionId);
    }

    /**
     * $moderatorUserAccountId is which LOGIN the flag is for — required
     * to grant, ignored to revoke (Repository\GroupMemberRepository
     * clears both). The Controller has already checked that the account
     * can actually log in as this member.
     *
     * Revoking goes through the same last-moderator protection as
     * leaving the group (ARCHITECTURE.md §8.40: a group must keep a
     * moderator of its own — site admins do not count): "Retirer la
     * modération" on the group's only moderator is refused rather than
     * performed, and the caller says why in French.
     *
     * @return bool false when a revocation was refused because the
     *         target is the group's last moderator
     */
    public function setModerator(
        DiscussionGroup $group,
        int $memberId,
        bool $isModerator,
        int $grantedByMemberId,
        ?int $moderatorUserAccountId = null
    ): bool {
        if (!$isModerator) {
            return $this->memberRepository->demoteUnlessLastModerator($group->id, $memberId);
        }

        $this->memberRepository->setModerator(
            $group->id,
            $memberId,
            true,
            $grantedByMemberId,
            $moderatorUserAccountId
        );

        return true;
    }

    /**
     * Removes an explicit membership row. A derived member (one who
     * belongs through a linked section) has nothing to remove here and
     * stays a member — the Controller says so in French rather than
     * pretending the removal worked.
     *
     * Same guard as a voluntary departure (GroupMembershipService::
     * leave()): removing the group's last explicit moderator is refused,
     * or one click on the members page would leave the group with nobody
     * of its own in charge.
     *
     * @return bool false when the removal was refused because the target
     *         is the group's last moderator
     */
    public function removeMember(DiscussionGroup $group, int $memberId): bool
    {
        return $this->memberRepository->removeUnlessLastModerator($group->id, $memberId);
    }
}
