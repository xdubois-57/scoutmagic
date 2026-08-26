<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to describe a member's own training
 * path on their member page — without core knowing how a raw Desk
 * formation level turns into a path. Same precedent as
 * Core\Module\SectionResponsableProvider and HomeBannerProvider
 * (ARCHITECTURE.md §7.4): core defines the interface, the module
 * implements it, and the composition root wires it in only while that
 * module is enabled. The `leadership` module implements this today.
 *
 * **Self only on the member's own page.** There the block is rendered
 * under `is_self`, separately from the route's authorization — exactly
 * like the photo the member may replace and the private documents. A
 * chief or a chef d'unité viewing somebody else's page does not see it,
 * even though Core\Http\Controller\MemberController::show() lets them
 * onto the page: a member's page answers a question that member asked
 * about themselves.
 *
 * **The staff answer lives on staff pages**, which is the other half of
 * that rule and not a contradiction of it. `Core\Member\
 * AdminMemberPageService` shows this block on `/admin/members/{id}`,
 * `role_min: admin` — the same floor as the leadership module's own
 * `/admin/leadership/training`, so nothing reaches a reader there who
 * could not already open it one menu away. What "self only" forbids is a
 * chief reading it off a page built for the member; it was never a rule
 * that the unit may not know where its own staff stand.
 */
interface FormationPathProvider
{
    /**
     * The member's training path for a scout year, or null when the module
     * has nothing to say (no level recorded and nothing to draw).
     */
    public function getFormationPath(int $memberId, int $scoutYearId): ?FormationPathView;
}
