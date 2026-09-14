<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Menu;

use Core\Member\MemberService;
use Core\Module\MenuEntry;
use Core\Module\MenuEntryProvider;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Core\View\MenuBuilder;

/**
 * The « Rétrospective » entry of Espace chefs d'U — contributed here
 * rather than declared as a `label` in module.json, because the page it
 * points at is not open to everyone the route's `role_min` admits.
 *
 * `/config/retro` is `role_min: admin`, and Controller\RetroConfigController
 * then narrows to the people actually registered in the Staff d'U section
 * for the authorization year (Core\Member\MemberService::isUnitChief()).
 * A static `label` is drawn from `role_min` alone, so an « Administrateur
 * du site » who is not himself a chef d'U was shown the entry, clicked it,
 * and was refused — issue #347. The manifest cannot express the
 * difference; this hook can, and asks exactly the question the controller
 * will ask.
 *
 * **This is not what protects the page**, and removing it would not open
 * anything (ARCHITECTURE.md §12, SECURITY.md §3): the route keeps its
 * `role_min`, the controller keeps its own check, and this only decides
 * whether a link is offered. What it buys is that the menu stops making a
 * promise the page will break.
 *
 * It runs on **every request that builds a menu**, so it asks nothing at
 * all below `admin` — the role that could not open the page anyway — and
 * the one lookup it does make above it is already memoised per request by
 * MemberService, which the controller then reads for free.
 */
class RetroMenuHookService implements MenuEntryProvider
{
    /**
     * Well clear of the core pages' small orders — MenuBuilder ranks by
     * sort group first anyway, so this only orders module entries against
     * each other. The value the manifest gave this entry before it moved
     * here.
     */
    private const CONFIG_ORDER = 510;

    public function __construct(
        private MemberService $memberService,
        private ScoutYearResolver $scoutYearResolver,
        private Role $viewerRole
    ) {
    }

    public function getMenuEntries(?string $email): array
    {
        if ($email === null || !$this->viewerRole->hasAccess(Role::ADMIN)) {
            return [];
        }

        // The year the session is authorised in, never the date-computed
        // one — the same year the controller asks about, for the reason
        // RetroConfigController::requireUnitChief() spells out.
        $scoutYearId = $this->scoutYearResolver->getAuthorizationYear()->id;
        if (!$this->memberService->isUnitChief($email, $scoutYearId)) {
            return [];
        }

        return [
            new MenuEntry(
                MenuBuilder::MENU_ESPACE_ADMIN,
                'Rétrospective',
                '/config/retro',
                'admin',
                self::CONFIG_ORDER,
                false,
                null,
                MenuBuilder::SORT_GROUP_MODULE,
                'bi-sticky',
                'services'
            ),
        ];
    }
}
