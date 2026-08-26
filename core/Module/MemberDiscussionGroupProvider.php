<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to say which discussion groups a
 * member belongs to, for the « parcours » of the admin member page —
 * without core knowing what a discussion group is. Same precedent as
 * Core\Module\FormationPathProvider (ARCHITECTURE.md §7.4): core defines
 * the interface, the module implements it, and the composition root
 * wires it only while that module is enabled. The `groups` module
 * implements this today.
 *
 * **Membership, and nothing else.** No post, no reply, no count of
 * either. A chef d'unité fielding "elle ne reçoit rien" needs to know
 * which groups reach this person; what people write to each other is not
 * a fact about a member to be summarised on a staff page. That is the
 * whole boundary of this hook, and it is the reason it returns what it
 * returns.
 *
 * **This hook decides nothing about who may look.** The page is
 * `role_min: admin`, and a site admin reads every group by the module's
 * own rule (Modules\Groups\Service\GroupAccessService::canRead()), so a
 * reader who sees a link can always open it. A provider that re-derived
 * its own audience would be a second answer waiting to disagree with
 * that one.
 */
interface MemberDiscussionGroupProvider
{
    /**
     * The groups this member belongs to, open ones first and each side
     * most recently active first — or an empty list when they belong to
     * none, which is the ordinary answer and not an anomaly.
     *
     * The id is a `members.id`: `discussion_group_members.member_id` is
     * the persistent identity too, so a membership survives the scout
     * year that saw it created.
     *
     * @return list<MemberDiscussionGroupView>
     */
    public function getDiscussionGroups(int $memberId): array;
}
