<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Module\MemberDiscussionGroupProvider;
use Core\Module\MemberDiscussionGroupView;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;

/**
 * This module's answer to core's « dans quels groupes est-elle » hook
 * (Core\Module\MemberDiscussionGroupProvider, ARCHITECTURE.md §7.4).
 *
 * Lives under Service\, not Api\: `Api\` is where this module publishes
 * an interface of its own for others to consume (§7.5 —
 * Service\HomeActivityService does exactly that), and here the interface is
 * core's.
 *
 * **Explicit memberships only**, the rows of `discussion_group_members`.
 * The module also derives membership from a section group's own
 * composition (GroupAccessService::isDerivedMember()), and that
 * deliberately does not appear here: a derived member is in the group
 * *because* of the section they are in this year, which the page already
 * states one card above under « Parcours dans l'unité ». Repeating it as
 * a group membership would say a chef d'unité did something they did
 * not.
 *
 * **Membership, and nothing else** — no post, no reply, no count of
 * either. See the interface's own docblock for why that boundary is the
 * point of this hook rather than an omission.
 */
class MemberDiscussionGroupService implements MemberDiscussionGroupProvider
{
    public function __construct(
        private GroupMemberRepository $members,
        private GroupRepository $groups
    ) {
    }

    /**
     * @return list<MemberDiscussionGroupView>
     */
    public function getDiscussionGroups(int $memberId): array
    {
        $rows = [];
        foreach ($this->members->findByMemberIds([$memberId]) as $membership) {
            $group = $this->groups->findById($membership->groupId);
            if ($group === null) {
                continue;
            }
            $rows[] = ['group' => $group, 'is_moderator' => $membership->isModerator];
        }
        if ($rows === []) {
            return [];
        }

        // Open groups first, and each side most recently active first: a
        // closed group is still part of the journey, but it is not where
        // somebody is reachable today.
        usort($rows, static function (array $a, array $b): int {
            $closed = ($a['group']->isClosed() <=> $b['group']->isClosed());

            return $closed !== 0 ? $closed : ($b['group']->lastActivityAt <=> $a['group']->lastActivityAt);
        });

        return array_map(
            static fn(array $row): MemberDiscussionGroupView => new MemberDiscussionGroupView(
                $row['group']->name,
                '/groups/' . $row['group']->id,
                $row['is_moderator'],
                $row['group']->isClosed()
            ),
            $rows
        );
    }
}
