<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Menu;

use Core\Module\MenuEntry;
use Core\Module\UnitChiefMenuHook;
use Core\View\MenuBuilder;

/**
 * The « Rétrospective » entry of Espace chefs d'U.
 *
 * Contributed here rather than declared as a `label` in module.json
 * because `/config/retro` is not open to everyone its `role_min: admin`
 * admits: Controller\RetroConfigController narrows to the Staff d'U of the
 * authorization year, and the menu used to offer the link to an
 * « Administrateur du site » the page then refused (issue #347). The
 * condition, and why hiding an entry protects nothing, are in
 * Core\Module\UnitChiefMenuHook; this class is the entry itself.
 */
class RetroMenuHookService extends UnitChiefMenuHook
{
    /**
     * Where this entry sits in « Espace chefs d'U › Communication », on
     * the one scale every entry of that column shares: core and module
     * entries have the same sort rank, so this number competes with the
     * core pages beside it rather than only with other modules.
     *
     * It used to sit in « Services », which had become the column for
     * whatever fitted nowhere else. Setting up a retrospective board is
     * asking a staff to speak, so it belongs with Communication; the
     * « Services de l'unité » column now means what its name says — the
     * hall, the emergency phone.
     */
    private const CONFIG_ORDER = 120;

    protected function entry(): MenuEntry
    {
        return new MenuEntry(
            MenuBuilder::MENU_ESPACE_ADMIN,
            'Rétrospective',
            '/config/retro',
            'admin',
            self::CONFIG_ORDER,
            false,
            null,
            MenuBuilder::SORT_GROUP_MODULE,
            'bi-sticky',
            'communication'
        );
    }
}
