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
use Modules\Groups\Repository\GroupReadRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;

/**
 * The account's own groups, and its archives.
 *
 * Deliberately batched: readability depends on derived membership, which
 * lives in the core member tables and cannot be expressed as a WHERE
 * clause over discussion_groups. Rather than asking GroupAccessService
 * once per group (one or more queries each), this loads the caller's whole
 * membership picture in a fixed number of queries — every explicit row for
 * the linked members, every group's section links, and each linked
 * member's own period history — and then decides in PHP. The rules it
 * applies are exactly GroupAccessService's, which stays the single
 * authority for a single group (and the only one a write path ever
 * consults).
 */
class GroupListService
{
    public function __construct(
        private GroupRepository $groupRepository,
        private GroupSectionRepository $sectionRepository,
        private GroupMemberRepository $memberRepository,
        private SectionMembershipRepository $membershipRepository,
        private PostRepository $postRepository,
        private ReplyRepository $replyRepository,
        private GroupArchiveRule $archiveRule,
        private ?GroupReadRepository $readRepository = null
    ) {
    }

    /**
     * The caller's ACTIVE groups — not archived by any cause
     * (Service\GroupArchiveRule) — most recently active first: the current
     * year's, a future year's and year-less ones alike.
     *
     * @return GroupListItem[]
     */
    public function findCurrent(GroupSessionContext $context): array
    {
        return array_values(array_filter($this->findReadable($context), fn(GroupListItem $i) => !$i->isArchived));
    }

    /**
     * The caller's archived groups — the "Archives" tab: archived by hand,
     * for inactivity, or because their scout year is over. Readable, never
     * postable (GroupAccessService::canParticipate()).
     *
     * Which year the session previews has no say in it: the rule is
     * measured against the public year.
     *
     * @return GroupListItem[]
     */
    public function findArchived(GroupSessionContext $context): array
    {
        return array_values(array_filter($this->findReadable($context), fn(GroupListItem $i) => $i->isArchived));
    }

    /**
     * @return GroupListItem[]
     */
    private function findReadable(GroupSessionContext $context): array
    {
        $groups = $this->groupRepository->findAll();
        if ($groups === []) {
            return [];
        }

        $groupIds = array_map(fn(DiscussionGroup $g) => $g->id, $groups);
        $sectionIdsByGroup = $this->sectionRepository->findSectionIdsByGroupIds($groupIds);

        $explicitByGroup = [];
        foreach ($this->memberRepository->findByMemberIds($context->linkedMemberIds) as $row) {
            $explicitByGroup[$row->groupId] = ($explicitByGroup[$row->groupId] ?? false) || $row->isModerator;
        }

        // (section id, scout year id) pairs the caller was ever active in,
        // one query per linked member — a handful at most, and enough to
        // answer derived membership for every group without touching the
        // database again.
        $periods = [];
        foreach ($context->linkedMemberIds as $memberId) {
            foreach ($this->membershipRepository->findAllForMember($memberId) as $period) {
                $periods[$period->sectionId . ':' . $period->scoutYearId] = true;
            }
        }

        $readable = [];
        // Whether one of the caller's linked members really belongs to the
        // group — as an invited member or through a section — which is not
        // the same question as « may read it »: a site admin reads every
        // group. Only a member's visit leaves a reading position
        // (Service\GroupReadStateService), so only a member can have an
        // unread badge to clear (issue #712).
        $belongsTo = [];
        foreach ($groups as $group) {
            $sectionIds = $sectionIdsByGroup[$group->id] ?? [];
            $isExplicit = array_key_exists($group->id, $explicitByGroup);
            $belongs = $isExplicit || $this->isDerived($group, $sectionIds, $periods, $context);

            if (!$context->isSiteAdmin() && !$belongs) {
                continue;
            }

            $belongsTo[$group->id] = $belongs;
            $readable[] = [$group, $sectionIds, $context->isSiteAdmin() || ($explicitByGroup[$group->id] ?? false)];
        }

        // One query for the caller's reading position across every group
        // they can see, rather than one per group — same batching rule as
        // everything else on this page.
        $lastRead = $this->readRepository?->lastReadFor(
            array_map(fn(array $row) => $row[0]->id, $readable),
            $context->linkedMemberIds
        ) ?? [];

        $headcounts = $this->headcounts($readable, $context);

        // Only groups whose badge could be shown at all are asked about:
        // one the caller belongs to and that is not archived. Archiving
        // and reopening write no content, so neither can raise the badge
        // by itself either (issue #1349's definition of « Nouveau »).
        $badgeable = [];
        foreach ($readable as [$group]) {
            if (($belongsTo[$group->id] ?? false) && !$this->archiveRule->isArchived($group)) {
                $badgeable[] = $group->id;
            }
        }
        $latestContent = $this->latestContentFor($badgeable, $context);

        $items = [];
        foreach ($readable as [$group, $sectionIds, $isModerator]) {
            $items[] = new GroupListItem(
                $group,
                $isModerator,
                $this->archiveRule->isArchived($group),
                $sectionIds,
                $this->hasUnread($group, $latestContent[$group->id] ?? null, $lastRead),
                $headcounts[$group->id] ?? 0
            );
        }

        return $items;
    }

    /**
     * How many people each group actually holds.
     *
     * The two kinds of membership added together and counted ONCE: the
     * people explicitly invited (discussion_group_members) and the people
     * a section group derives from its sections. Either on its own is
     * wrong in a way that is worse than no number at all — the invited
     * rows of a section group are usually just its moderator, which would
     * print « 1 membre » next to a group of forty; and the sections of an
     * invitation group are empty, which would print « 0 ».
     *
     * Two queries for the whole page, not two per card: the section
     * lookups are batched by section and the explicit rows by group, the
     * same rule the rest of this method already follows.
     *
     * The year a section group resolves against is its OWN when it has
     * one — that is what keeps a past-year group's headcount the one it
     * had that year rather than this year's roster.
     *
     * @param list<array{0: DiscussionGroup, 1: int[], 2: bool}> $readable
     * @return array<int, int> group id => people
     */
    private function headcounts(array $readable, GroupSessionContext $context): array
    {
        $explicitByGroup = $this->memberRepository->findMemberIdsByGroups(
            array_map(fn(array $row) => $row[0]->id, $readable)
        );

        // Grouped by the year they resolve against: two groups on the same
        // section but different years have genuinely different membership.
        $sectionIdsByYear = [];
        foreach ($readable as [$group, $sectionIds]) {
            $year = $group->scoutYearId ?? $context->effectiveScoutYearId;
            foreach ($sectionIds as $sectionId) {
                $sectionIdsByYear[$year][$sectionId] = true;
            }
        }

        $membersBySectionAndYear = [];
        foreach ($sectionIdsByYear as $year => $sectionIds) {
            $membersBySectionAndYear[$year] = $this->membershipRepository->findMemberIdsBySection(
                array_keys($sectionIds),
                (int) $year
            );
        }

        $counts = [];
        foreach ($readable as [$group, $sectionIds]) {
            $year = $group->scoutYearId ?? $context->effectiveScoutYearId;
            $seen = [];
            foreach ($explicitByGroup[$group->id] ?? [] as $memberId) {
                $seen[$memberId] = true;
            }
            foreach ($sectionIds as $sectionId) {
                foreach ($membersBySectionAndYear[$year][$sectionId] ?? [] as $memberId) {
                    $seen[$memberId] = true;
                }
            }
            $counts[$group->id] = count($seen);
        }

        return $counts;
    }

    /**
     * « Nouveau » means new CONTENT: a message or a reply somebody else
     * wrote after the reader's last visit (issue #1349). It used to be
     * the group's last_activity_at, which a reaction — added, changed or
     * taken back — and a reopening also move, so the badge came back with
     * nothing new to read. That clock stays what it is for (the feed's
     * order, the inactivity closing, the retention purge); it is simply
     * not this question any more.
     *
     * A group never opened is measured from its creation, so an empty
     * group is not flagged for merely existing. $latestContent is null for
     * a group with no visible content by anybody else — and for every
     * group the badge is never put on: one the caller does not belong to
     * (issue #712, only a member's visit leaves a reading position to
     * clear it) and an archived group (read-only, nothing to catch up
     * on). See findReadable().
     *
     * @param array<int, string> $lastRead
     */
    private function hasUnread(DiscussionGroup $group, ?string $latestContent, array $lastRead): bool
    {
        if ($latestContent === null) {
            return false;
        }

        return $latestContent > ($lastRead[$group->id] ?? $group->createdAt);
    }

    /**
     * The newest visible post or reply by somebody else, per group — two
     * queries for the whole list. "Somebody else" is every member linked
     * to the caller, the same exclusion the thread badges and the home
     * band's counters apply (Repository\ReplyRepository::
     * countNewerForPosts()), so the badge and the numbers beside it agree
     * about what is new.
     *
     * @param int[] $groupIds
     * @return array<int, string> group id => created_at of its newest content
     */
    private function latestContentFor(array $groupIds, GroupSessionContext $context): array
    {
        if ($groupIds === []) {
            return [];
        }

        $latest = $this->postRepository->findLatestCreatedAtByGroups($groupIds, $context->linkedMemberIds);
        foreach ($this->replyRepository->findLatestCreatedAtByGroups($groupIds, $context->linkedMemberIds) as $groupId => $at) {
            if (!isset($latest[$groupId]) || $at > $latest[$groupId]) {
                $latest[$groupId] = $at;
            }
        }

        return $latest;
    }

    /**
     * @param int[] $sectionIds
     * @param array<string, bool> $periods
     */
    private function isDerived(
        DiscussionGroup $group,
        array $sectionIds,
        array $periods,
        GroupSessionContext $context
    ): bool
    {
        $scoutYearId = $group->scoutYearId ?? $context->effectiveScoutYearId;

        foreach ($sectionIds as $sectionId) {
            if (isset($periods[$sectionId . ':' . $scoutYearId])) {
                return true;
            }
        }

        return false;
    }
}
