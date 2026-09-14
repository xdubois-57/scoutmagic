<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Banner\Menu;

use Core\Member\MemberService;
use Core\Module\MenuEntry;
use Core\Module\MenuEntryProvider;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Core\View\MenuBuilder;

/**
 * The « Bannière » entry of Espace chefs d'U, and the twin of
 * Modules\Retro\Menu\RetroMenuHookService — same defect, same shape.
 *
 * `/config/banner` is `role_min: admin`, and
 * Controller\BannerConfigController then narrows to the people actually
 * registered in the Staff d'U section for the authorization year
 * (Core\Member\MemberService::isUnitChief()). A `label` in module.json is
 * drawn from `role_min` alone, so the entry was offered to an
 * « Administrateur du site » the page would refuse. Issue #347 was
 * reported against the retro page; this one had exactly the same hole and
 * nobody had clicked it yet.
 *
 * **This is not what protects the page** (ARCHITECTURE.md §12,
 * SECURITY.md §3): the route keeps its `role_min` and the controller
 * keeps its own check. This only decides whether a link is offered.
 *
 * It runs on **every request that builds a menu**, so it asks nothing
 * below `admin`, and above it the one lookup is memoised per request by
 * MemberService — the controller reads the same answer for free.
 */
class BannerMenuHookService implements MenuEntryProvider
{
    /**
     * Well clear of the core pages' small orders — MenuBuilder ranks by
     * sort group first anyway, so this only orders module entries against
     * each other.
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

        $scoutYearId = $this->scoutYearResolver->getAuthorizationYear()->id;
        if (!$this->memberService->isUnitChief($email, $scoutYearId)) {
            return [];
        }

        return [
            new MenuEntry(
                MenuBuilder::MENU_ESPACE_ADMIN,
                'Bannière',
                '/config/banner',
                'admin',
                self::CONFIG_ORDER,
                false,
                null,
                MenuBuilder::SORT_GROUP_MODULE,
                'bi-megaphone',
                'contenu'
            ),
        ];
    }
}
