<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Module\MenuEntry;
use Core\Module\MenuEntryProvider;

/**
 * Applies Core\Module\MenuEntryProvider implementations to a MenuBuilder and
 * re-derives the active-page highlight for whatever they added.
 *
 * This exists because of an ordering constraint documented in
 * ARCHITECTURE.md §7.4 and easy to get wrong: MenuBuilder::build() — and the
 * active-page scan that reads its output — must run *before* module services
 * are wired later in public/index.php, so a module's entries cannot possibly
 * be present the first time round. The composition root therefore calls back
 * into the builder from inside the module's own conditional block and
 * re-derives $menus / active_menu_id / active_page_url. MenuBuilder::build()
 * is a pure read of its internal page list, so calling it twice is safe.
 *
 * Before this class, that re-derivation was ~30 lines hand-written inline for
 * the one module that needed it. Every further module would have copied it,
 * and any copy that quietly dropped the highlight refresh would have produced
 * a bug invisible in tests (a correct page, no nav highlight). Having it once
 * here is the point.
 */
class DynamicMenuRegistrar
{
    /**
     * Collect every provider's entries and register them on the builder.
     *
     * @param MenuEntryProvider[] $providers
     * @param string|null $email The authenticated visitor's session email, or null when anonymous.
     * @return MenuEntry[] Everything actually added, for resolveActive() below.
     */
    public function register(MenuBuilder $menuBuilder, array $providers, ?string $email): array
    {
        $added = [];

        foreach ($providers as $provider) {
            foreach ($provider->getMenuEntries($email) as $entry) {
                $menuBuilder->addPage(
                    $entry->menuId,
                    $entry->label,
                    $entry->url,
                    $entry->roleMin,
                    $entry->order,
                    $entry->isDynamic,
                    $entry->subtitle,
                    $entry->group,
                    $entry->icon
                );
                $added[] = $entry;
            }
        }

        return $added;
    }

    /**
     * Re-run the active-page scan over the late-registered entries only,
     * starting from the result the first (pre-module) scan already reached.
     *
     * Deliberately mirrors public/index.php's own scan, including its
     * "longest match wins" tie-break and its isDynamic rule: a dynamic entry
     * matches on an exact path only, never as a path-segment prefix, so a
     * per-visitor entry at /x/1 cannot claim the highlight while the visitor
     * is on /x/1/edit. A non-dynamic entry does match as a prefix, which is
     * what keeps a module's top-level page highlighted across its sub-pages.
     *
     * @param MenuEntry[] $entries The return value of register().
     * @return array{menuId: string, pageUrl: string, matchLength: int} The updated highlight, unchanged when nothing matched better.
     */
    public function resolveActive(
        array $entries,
        string $currentPath,
        string $activeMenuId,
        string $activePageUrl,
        int $bestMatchLength
    ): array {
        foreach ($entries as $entry) {
            if ($entry->url === '') {
                continue;
            }

            $isExact = $entry->url === $currentPath;
            $isPrefix = !$entry->isDynamic && $entry->url !== '/' && str_starts_with($currentPath, $entry->url . '/');

            if (($isExact || $isPrefix) && strlen($entry->url) > $bestMatchLength) {
                $activeMenuId = $entry->menuId;
                $activePageUrl = $entry->url;
                $bestMatchLength = strlen($entry->url);
            }
        }

        return [
            'menuId' => $activeMenuId,
            'pageUrl' => $activePageUrl,
            'matchLength' => $bestMatchLength,
        ];
    }
}
