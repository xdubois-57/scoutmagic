<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Banner\Menu;

use Core\Module\MenuEntry;
use Core\Module\UnitChiefMenuHook;
use Core\View\MenuBuilder;

/**
 * The « Bannière » entry of Espace chefs d'U — the twin of
 * Modules\Retro\Menu\RetroMenuHookService, for the page that had the same
 * hole as issue #347's and had simply not been clicked yet:
 * `/config/banner` is `role_min: admin` and
 * Controller\BannerConfigController asks for Staff d'U membership.
 *
 * The condition it is contributed under lives in
 * Core\Module\UnitChiefMenuHook.
 */
class BannerMenuHookService extends UnitChiefMenuHook
{
    /**
     * Where this entry sits in « Espace chefs d'U › Contenu du site », on
     * the one scale every entry of that column shares: core and module
     * entries have the same sort rank, so this number competes with the
     * core pages beside it rather than only with other modules.
     */
    private const CONFIG_ORDER = 80;

    protected function entry(): MenuEntry
    {
        return new MenuEntry(
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
        );
    }
}
