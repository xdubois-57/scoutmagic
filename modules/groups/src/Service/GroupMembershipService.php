<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Support\Timestamps;

/**
 * The three things a member or a moderator can do to a group's own state,
 * as opposed to its content: leave it, reopen it, and — before either —
 * be allowed to create it at all.
 *
 * Each answers with a LeaveOutcome/ReopenOutcome rather than a bool, so
 * the Controller can say in French *why* something was refused without
 * re-deriving the reason it just computed. Authorisation itself stays the
 * Controller's, exactly like every other service in this module: by the
 * time these run, the caller's membership and moderator status have
 * already been established through Service\GroupAccessService.
 */
class GroupMembershipService
{
    public const SETTING_CREATION_QUOTA = 'groups_max_created_per_member';

    private const DEFAULT_CREATION_QUOTA = 5;

    public function __construct(
        private GroupRepository $groupRepository,
        private GroupMemberRepository $memberRepository,
        private SettingService $settingService,
        private JournalService $journalService
    ) {
    }

    /**
     * Removes one of the caller's own explicit memberships.
     *
     * **Derived section membership cannot be left**, and this refuses it
     * rather than silently doing nothing: that membership follows the
     * Desk import, so the only way out of it is to leave the section
     * itself. Deleting a row that does not exist would look like success
     * and leave the member exactly as much a member as before.
     */
    public function leave(DiscussionGroup $group, int $memberId, ?int $actorAccountId): LeaveOutcome
    {
        if ($this->memberRepository->find($group->id, $memberId) === null) {
            return LeaveOutcome::DERIVED_MEMBERSHIP;
        }

        if (!$this->memberRepository->removeUnlessLastModerator($group->id, $memberId)) {
            return LeaveOutcome::LAST_MODERATOR;
        }

        // Their posts, replies and reactions are deliberately left
        // untouched: a conversation with holes in it is worse for
        // everyone still in the group than one signed by someone who has
        // gone. Anonymising an author is a project-wide concern and not
        // this module's to invent.
        $this->journal('group_member_left', 'Membre sorti d\'un groupe', [
            'group_id' => $group->id,
            'member_id' => $memberId,
        ], $actorAccountId);

        return LeaveOutcome::LEFT;
    }

    /**
     * Reopens a closed group.
     *
     * **last_activity_at is reset to now**, which is the whole point: the
     * group was almost certainly closed BY the inactivity task, so
     * reopening without resetting it would have that same task close it
     * again on its next nightly run — and would leave the purge clock
     * still running against a group somebody just said they still wanted.
     *
     * A past-year group is refused: prompt 3's rule that an archived
     * group is a read-only archive is not being relaxed here, and
     * reopening one would put it back in the current year's list under
     * the wrong year.
     */
    public function reopen(DiscussionGroup $group, int $effectiveScoutYearId, ?int $actorAccountId): ReopenOutcome
    {
        if (!$group->isClosed()) {
            return ReopenOutcome::NOT_CLOSED;
        }

        if ($group->scoutYearId !== null && $group->scoutYearId !== $effectiveScoutYearId) {
            return ReopenOutcome::PAST_YEAR;
        }

        $this->groupRepository->setClosed($group->id, null);
        $this->groupRepository->touchActivity($group->id, Timestamps::now());

        $this->journal('group_reopened', 'Groupe rouvert', ['group_id' => $group->id], $actorAccountId);

        return ReopenOutcome::REOPENED;
    }

    /**
     * Closes a group by hand — the counterpart of reopen(), and what a
     * moderator does when a project is over rather than waiting months
     * for the inactivity task to notice.
     */
    public function close(DiscussionGroup $group, ?int $actorAccountId): void
    {
        if ($group->isClosed()) {
            return;
        }

        $this->groupRepository->setClosed($group->id, Timestamps::now());
        $this->journal('group_closed', 'Groupe clôturé par un modérateur', ['group_id' => $group->id], $actorAccountId);
    }

    /**
     * Whether $memberId may create one more group.
     *
     * Site admins are NOT exempt: the cap is about how much live clutter
     * one person can leave in everybody else's group list, and an admin's
     * clutter is exactly as cluttering as anyone else's.
     */
    public function canCreateAnotherGroup(int $memberId): bool
    {
        return $this->groupRepository->countOpenCreatedBy($memberId) < $this->creationQuota();
    }

    /**
     * The configured cap, floored at 1: a 0 would mean nobody may ever
     * create a group, which is a way of disabling half the module by
     * typo. Same posture as Service\ReportService::threshold().
     */
    public function creationQuota(): int
    {
        $raw = $this->settingService->get(self::SETTING_CREATION_QUOTA, 'groups', self::DEFAULT_CREATION_QUOTA);
        $configured = is_numeric($raw) ? (int) $raw : self::DEFAULT_CREATION_QUOTA;

        return max(1, $configured);
    }

    /**
     * @param array<string, mixed> $context ids only — never a group name
     *        or a member's name (SECURITY.md §11)
     */
    private function journal(string $type, string $description, array $context, ?int $actorAccountId): void
    {
        $this->journalService->log('groups', $type, 'info', $description, $context, $actorAccountId);
    }
}
