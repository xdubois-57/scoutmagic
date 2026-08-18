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
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;

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
        private SectionMembershipRepository $membershipRepository
    ) {
    }

    /**
     * Groups of the effective year (and year-less ones) the caller belongs
     * to, most recently active first.
     *
     * @return GroupListItem[]
     */
    public function findCurrent(GroupSessionContext $context): array
    {
        return array_values(array_filter($this->findReadable($context), fn(GroupListItem $i) => !$i->isArchived));
    }

    /**
     * Past-year groups the caller was a member of — the "Archives" tab.
     * Readable, never postable (GroupAccessService::canPost()).
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

        $items = [];
        foreach ($groups as $group) {
            $sectionIds = $sectionIdsByGroup[$group->id] ?? [];
            $isExplicit = array_key_exists($group->id, $explicitByGroup);
            $isModerator = $context->isSiteAdmin() || ($explicitByGroup[$group->id] ?? false);

            if (!$context->isSiteAdmin() && !$isExplicit && !$this->isDerived($group, $sectionIds, $periods, $context)) {
                continue;
            }

            $items[] = new GroupListItem(
                $group,
                $isModerator,
                $group->scoutYearId !== null && $group->scoutYearId !== $context->effectiveScoutYearId,
                $sectionIds
            );
        }

        return $items;
    }

    /**
     * @param int[] $sectionIds
     * @param array<string, bool> $periods
     */
    private function isDerived(DiscussionGroup $group, array $sectionIds, array $periods, GroupSessionContext $context): bool
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
