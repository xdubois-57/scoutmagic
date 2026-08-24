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
 * **Self only.** The block this feeds is rendered under the member page's
 * own `is_self` test, separately from the route's authorization — exactly
 * like the photo the member may replace and the private documents. A chief
 * or a chef d'unité viewing somebody else's page does not see it, even
 * though Core\Http\Controller\MemberController::show() lets them onto the
 * page. Where the unit as a whole stands is a question for that module's
 * own admin pages, which are role-gated in their own right; a member's
 * page answers a question that member asked about themselves.
 */
interface FormationPathProvider
{
    /**
     * The member's training path for a scout year, or null when the module
     * has nothing to say (no level recorded and nothing to draw).
     */
    public function getFormationPath(int $memberId, int $scoutYearId): ?FormationPathView;
}
