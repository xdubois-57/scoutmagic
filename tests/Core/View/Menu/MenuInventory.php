<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View\Menu;

use Core\Security\Role;
use Core\View\MenuBuilder;

/**
 * Every menu entry the application registers, replayed through the real
 * `MenuBuilder`.
 *
 * **Why read the sources rather than boot the application.** The core's
 * entries are a hundred `$menuBuilder->addPage(...)` statements inside
 * `public/index.php`, a procedural bootstrap that opens a database,
 * starts a session and serves a request; no unit test can run it. The
 * repository already answers this the same way twice — `Tests\Core\Http\
 * MenuRegistrationOrderTest` and `Tests\Core\Help\HelpMenuCoverageTest`
 * both read that file's text. This does too, and then hands what it read
 * to the real builder, so the sorting, the role filter and the column
 * split are the shipped ones rather than a second implementation.
 *
 * **Module positions.** A module's entries used to be pushed behind every
 * core page and offset by the module's rank in `module_registry`, which
 * lives in a database and differs per installation. With no registry rows
 * — a fresh installation — `ModuleManager` falls back to ordering modules
 * by directory name, and that is the order replayed here: the only one
 * that is a property of the repository rather than of somebody's past
 * drag-and-drop.
 */
final class MenuInventory
{
    /** `ModuleManager::MODULE_ORDER_BASE`, mirrored for the "before" snapshot. */
    public const LEGACY_MODULE_ORDER_BASE = 1000;

    /** `ModuleManager::MODULE_ORDER_STEP`, mirrored for the "before" snapshot. */
    public const LEGACY_MODULE_ORDER_STEP = 1000;

    /** `ModuleManifest`'s default when a route declares no `menu_order`. */
    public const MANIFEST_DEFAULT_ORDER = 100;

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * The core pages, in the order `public/index.php` registers them.
     *
     * @return array<int, array{
     *     menu: string, label: string, url: string, roleMin: string,
     *     order: int, isDynamic: bool, sortGroup: string, menuGroup: ?string
     * }>
     */
    public static function corePages(): array
    {
        $source = (string) file_get_contents(self::root() . '/public/index.php');

        // addPage($menu, 'Label', '/url', 'role', order, isDynamic,
        //         subtitle, sortGroup, icon, avatarMemberId, menuGroup)
        // — every argument after the role is optional in the signature,
        // so the tail is captured loosely and split below. Both quote
        // styles are accepted: « Points d'attention » carries an
        // apostrophe and is written with double quotes.
        $pattern = '/\$menuBuilder->addPage\(\s*'
            . 'MenuBuilder::(MENU_[A-Z_]+)\s*,\s*'
            . '((?:\'(?:[^\'\\\\]|\\\\.)*\')|(?:"(?:[^"\\\\]|\\\\.)*")|[^,]+)\s*,\s*'
            . '((?:\'(?:[^\'\\\\]|\\\\.)*\')|(?:"(?:[^"\\\\]|\\\\.)*")|[^,]+)\s*,\s*'
            . "'([a-z]+)'\s*"
            . '((?:,[^;]*?)?)\)\s*;/s';

        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

        $pages = [];
        foreach ($matches as $match) {
            $label = self::literal($match[2]);
            $url = self::literal($match[3]);

            // The per-member entries of « Espace membres » build their
            // label from a member and their address from a year: they are
            // request data, not a property of this repository, and there
            // is nothing here to snapshot. They are dynamic, so they sort
            // ahead of everything else whatever this inventory does.
            if ($label === null || $url === null) {
                continue;
            }

            $tail = self::splitArguments($match[5]);

            $pages[] = [
                'menu' => constant(MenuBuilder::class . '::' . $match[1]),
                'label' => $label,
                'url' => $url,
                'roleMin' => $match[4],
                'order' => isset($tail[0]) && $tail[0] !== 'null' ? (int) $tail[0] : 100,
                'isDynamic' => ($tail[1] ?? 'false') === 'true',
                'sortGroup' => self::literal($tail[3] ?? null) ?? MenuBuilder::SORT_GROUP_CORE,
                'menuGroup' => self::literal($tail[6] ?? null),
            ];
        }

        return $pages;
    }

    /**
     * The module entries, as `ModuleManager` would register them on an
     * installation that has never reordered anything.
     *
     * @param bool $legacyOffset true replays the removed
     *        `MODULE_ORDER_BASE + position * STEP` offset — what the
     *        "before" snapshot needs, and nothing else should want.
     *
     * @return array<int, array{
     *     module: string, menu: string, label: string, url: string,
     *     roleMin: string, order: int, isDynamic: bool, sortGroup: string,
     *     menuGroup: ?string
     * }>
     */
    public static function modulePages(bool $legacyOffset): array
    {
        $manifests = glob(self::root() . '/modules/*/module.json') ?: [];
        sort($manifests);

        $pages = [];
        $position = 0;

        foreach ($manifests as $manifestPath) {
            $moduleId = basename(dirname($manifestPath));
            $data = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($data)) {
                continue;
            }

            // A module gated by `visible_when` never exists on a deploying
            // unit's installation, which is the installation these
            // snapshots describe.
            if (!empty($data['visible_when'])) {
                continue;
            }

            foreach ($data['routes'] ?? [] as $route) {
                if (!is_array($route) || ($route['label'] ?? '') === '') {
                    continue;
                }

                $explicit = isset($route['menu_order']);
                $declared = $explicit ? (int) $route['menu_order'] : self::MANIFEST_DEFAULT_ORDER;

                $order = ($legacyOffset && !$explicit)
                    ? self::LEGACY_MODULE_ORDER_BASE + ($position * self::LEGACY_MODULE_ORDER_STEP) + $declared
                    : $declared;

                $pages[] = [
                    'module' => $moduleId,
                    'menu' => (string) $route['menu'],
                    'label' => (string) $route['label'],
                    'url' => (string) $route['path'],
                    'roleMin' => (string) $route['role_min'],
                    'order' => $order,
                    'isDynamic' => false,
                    'sortGroup' => MenuBuilder::SORT_GROUP_MODULE,
                    'menuGroup' => isset($route['menu_group']) ? (string) $route['menu_group'] : null,
                ];
            }

            $position++;
        }

        return $pages;
    }

    /**
     * What a person of `$role` sees: one line per entry, prefixed by its
     * column when the menu has columns.
     *
     * Built by the shipped `MenuBuilder`, so a change to the sort, to the
     * role filter or to the column split moves these lines.
     *
     * @return array<string, array<int, string>> menu label => lines
     */
    public static function render(string $role, bool $legacyOffset): array
    {
        $builder = new MenuBuilder(Role::fromString($role));

        foreach ([...self::corePages(), ...self::modulePages($legacyOffset)] as $page) {
            $builder->addPage(
                $page['menu'],
                $page['label'],
                $page['url'],
                $page['roleMin'],
                $page['order'],
                $page['isDynamic'],
                null,
                $page['sortGroup'],
                null,
                null,
                $page['menuGroup'],
            );
        }

        $rendered = [];
        foreach ($builder->build() as $menu) {
            $lines = [];
            foreach ($menu['groups'] as $group) {
                $columnLabel = $group['label'] ?? null;
                foreach ($group['pages'] as $page) {
                    $lines[] = $columnLabel === null
                        ? $page['label']
                        : $columnLabel . ' › ' . $page['label'];
                }
            }
            $rendered[$menu['label']] = $lines;
        }

        return $rendered;
    }

    /**
     * Splits an argument tail on top-level commas — the arguments contain
     * `MenuBuilder::CONST` and quoted strings, never nested calls with
     * commas, so counting quotes and parentheses is enough.
     *
     * @return array<int, string>
     */
    private static function splitArguments(string $tail): array
    {
        $tail = ltrim($tail);
        if ($tail === '' || $tail[0] !== ',') {
            return [];
        }

        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;

        foreach (str_split(substr($tail, 1)) as $char) {
            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote && !str_ends_with(substr($current, 0, -1), '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = trim($current);

        return $parts;
    }

    /** The value of a quoted literal, or null for `null` and for a constant. */
    private static function literal(?string $argument): ?string
    {
        if ($argument === null) {
            return null;
        }

        $argument = trim($argument);
        if ($argument === '' || $argument === 'null') {
            return null;
        }

        if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/', $argument, $m) === 1) {
            return stripcslashes($m[1]);
        }

        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $argument, $m) === 1) {
            return stripcslashes($m[1]);
        }

        // MenuBuilder::SORT_GROUP_CORE and friends.
        if (preg_match('/^MenuBuilder::([A-Z_]+)$/', $argument, $m) === 1) {
            return (string) constant(MenuBuilder::class . '::' . $m[1]);
        }

        return null;
    }
}
