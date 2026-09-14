<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

use Core\Member\MemberService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;

/**
 * A menu entry offered only to somebody actually registered in the Staff
 * d'U section for the authorization year — the condition `role_min` cannot
 * express, written once here rather than once per module.
 *
 * **Why this exists at all** (issue #347): a `label` in `module.json` draws
 * its entry from the route's `role_min` and nothing else. A page whose
 * controller then narrows further — `/config/retro` and `/config/banner`
 * both ask `Core\Member\MemberService::isUnitChief()` on top of
 * `role_min: admin` — was therefore offered to an « Administrateur du
 * site » who is not himself a chef d'U, and answered his click with a
 * refusal. Such a route drops its `label` and its module subclasses this
 * instead, so the question the menu asks is the question the controller
 * will ask.
 *
 * **It protects nothing** (ARCHITECTURE.md §7.4/§12, SECURITY.md §3). The
 * route keeps its `role_min`, the controller keeps its own check, and a
 * hidden entry grants and withholds exactly nothing. What it buys is that
 * the menu stops promising what the page will refuse.
 *
 * A subclass supplies its entry and nothing else. That split is what keeps
 * the module owning its own label, icon and column while the condition —
 * and the cost of asking it — lives in one place: the two modules that
 * needed this first held a copy each, and SonarCloud was right to call
 * the second one duplication.
 *
 * **It runs on every request that builds a menu**, so it asks nothing at
 * all below `admin` — the role that could not open such a page anyway —
 * and the one lookup it makes above that is already memoised per request
 * by MemberService, which the controller then reads for free.
 */
abstract class UnitChiefMenuHook implements MenuEntryProvider
{
    public function __construct(
        private MemberService $memberService,
        private ScoutYearResolver $scoutYearResolver,
        private Role $viewerRole
    ) {
    }

    final public function getMenuEntries(?string $email): array
    {
        if ($email === null || !$this->viewerRole->hasAccess(Role::ADMIN)) {
            return [];
        }

        // The year this session is authorised in, never the date-computed
        // one — the same year the controllers ask about, for the reason
        // Modules\Retro\Controller\RetroConfigController::requireUnitChief()
        // spells out: on the computed year nobody is chef d'U between the
        // 1st of September and the import of the new roster.
        $scoutYearId = $this->scoutYearResolver->getAuthorizationYear()->id;
        if (!$this->memberService->isUnitChief($email, $scoutYearId)) {
            return [];
        }

        return [$this->entry()];
    }

    /**
     * The entry this module offers a chef d'U — its label, its URL, and
     * where in Espace chefs d'U it is drawn.
     */
    abstract protected function entry(): MenuEntry;
}
