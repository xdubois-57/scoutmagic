<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Page;

use Core\Module\MenuEntry;
use Core\Module\MenuEntryProvider;
use Core\View\MenuBuilder;

/**
 * Puts each active page in its menu, in its column, at its order.
 *
 * A {@see MenuEntryProvider} rather than a direct `addPage()` loop in
 * the composition root, because that interface already solved the two
 * things this would otherwise have to redo: ordering against the core
 * and module entries, and refreshing which entry is highlighted once
 * entries arrive after the first menu build
 * ({@see \Core\View\DynamicMenuRegistrar}).
 *
 * **It is handed the pages the routes were registered from**, not a
 * repository to query again. One request reads `text_pages` once: the
 * boot-time read that creates the routes is the same list the menu is
 * built from, so a page can never appear in a menu without a route
 * behind it — which would be a link to a 404 — nor the reverse.
 *
 * `$email` is ignored, deliberately. These entries do not vary per
 * visitor: `MenuBuilder` filters them by the role floor of their menu
 * like every other entry, and that floor is the same floor the route
 * enforces. A menu entry is never a permission (ARCHITECTURE.md §12).
 */
final class TextPageMenuProvider implements MenuEntryProvider
{
    /**
     * Where free-text pages sit among the entries of their menu.
     *
     * Past the core pages a unit did not choose and before nothing in
     * particular: they are additions to a menu somebody curated, so they
     * follow what the site shipped rather than interleaving with it.
     */
    private const ORDER_BASE = 600;

    /** @param TextPage[] $pages the active pages, as read once at boot */
    public function __construct(private array $pages)
    {
    }

    /**
     * @param string|null $email ignored — see the class docblock
     * @return MenuEntry[]
     */
    public function getMenuEntries(?string $email): array
    {
        $entries = [];

        foreach ($this->pages as $page) {
            // **A stale column is skipped, and skipping it is the point.**
            // `MenuBuilder::addPage()` throws when a column is not
            // declared for its menu, and these entries are added while
            // building the navigation that every page of the site
            // renders — so one bad row would be an uncaught exception on
            // every request, not a broken link on one page.
            //
            // `TextPageService::assertMenuPlacement()` prevents that at
            // write time, but `MenuBuilder::MENU_GROUPS` is a PHP
            // constant: validity is time-of-write only, and renaming or
            // removing a column in a later version orphans every row
            // that named it. That is not hypothetical — this feature's
            // own `defaultGroupFor()` is written to survive exactly that
            // rename.
            //
            // Filtering here rather than catching around the whole loop
            // is deliberate: a catch would drop EVERY page's menu entry
            // because one row went stale. The page itself keeps its
            // route and stays reachable by address; only its menu entry
            // waits for somebody to re-file it.
            if (!self::placementIsStillValid($page)) {
                continue;
            }

            $entries[] = new MenuEntry(
                menuId: $page->menuId,
                label: $page->menuLabel,
                url: $page->path(),
                roleMin: $page->roleMin(),
                order: self::ORDER_BASE + $page->sortOrder,
                isDynamic: false,
                subtitle: null,
                sortGroup: MenuBuilder::SORT_GROUP_CORE,
                icon: 'bi-file-text',
                menuGroup: $page->menuGroup,
            );
        }

        return $entries;
    }

    /**
     * Whether this page's column is one its menu still declares.
     *
     * A menu with no columns at all — « Notre unité » — is valid only
     * with no column, which is the same pair
     * {@see TextPageService::assertMenuPlacement()} enforces on the way
     * in. The two say the same thing on purpose: one refuses bad input,
     * the other survives input that went bad afterwards.
     */
    private static function placementIsStillValid(TextPage $page): bool
    {
        $declared = MenuBuilder::groupIdsFor($page->menuId);

        return $page->menuGroup === null
            ? $declared === []
            : in_array($page->menuGroup, $declared, true);
    }
}
