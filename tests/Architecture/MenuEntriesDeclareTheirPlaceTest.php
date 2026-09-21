<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Page\TextPageMenuProvider;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Core\View\Menu\MenuInventory;

/**
 * **Every menu entry says where it goes. An order nobody chose is an
 * order somebody put up with.**
 *
 * Until the menu reorganisation, thirty of the thirty-seven entries
 * modules declare had no `menu_order` at all. They were not unordered —
 * they were ordered by a formula nobody reading the manifest could see:
 * the manifest's default of 100, plus a thousand per position of that
 * module in a list an administrator had once dragged. « Inscriptions »
 * sat last in the public menu, behind a legal page, and no value anyone
 * could write in `modules/registration/module.json` would have moved it.
 *
 * Three rules, and each one is a way that could come back:
 *
 * 1. an explicit order on every entry, core page and module route alike;
 * 2. an explicit column on every entry of a menu that has columns — the
 *    fallback to the last declared one still exists in `addPage()`, and
 *    nothing may quietly rely on it again;
 * 3. every order below {@see TextPageMenuProvider}'s base, which is the
 *    promise that a page the unit writes itself comes after every page
 *    the site ships.
 */
final class MenuEntriesDeclareTheirPlaceTest extends TestCase
{
    /**
     * The first order a free-text page can take. Read off the provider so
     * that moving the boundary moves this test with it, rather than
     * leaving it asserting a number the application stopped using.
     */
    private static function textPageBase(): int
    {
        $reflection = new \ReflectionClass(TextPageMenuProvider::class);

        return (int) $reflection->getConstant('ORDER_BASE');
    }

    public function testEveryModuleRouteWithALabelDeclaresItsOrder(): void
    {
        $missing = [];

        foreach (glob(dirname(__DIR__, 2) . '/modules/*/module.json') ?: [] as $manifestPath) {
            $moduleId = basename(dirname($manifestPath));
            $data = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($data)) {
                continue;
            }

            foreach ($data['routes'] ?? [] as $route) {
                if (!is_array($route) || ($route['label'] ?? '') === '') {
                    continue;
                }
                if (!isset($route['menu_order'])) {
                    $missing[] = "{$moduleId} › {$route['label']} ({$route['path']})";
                }
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "These module menu entries take ModuleManifest's default order instead of choosing one:\n  "
            . implode("\n  ", $missing)
        );
    }

    public function testEveryEntryOfAMenuWithColumnsNamesItsColumn(): void
    {
        $homeless = [];

        foreach (self::allEntries() as $entry) {
            if (MenuBuilder::groupIdsFor($entry['menu']) === []) {
                continue;
            }
            if ($entry['menuGroup'] === null) {
                $homeless[] = "{$entry['menu']} › {$entry['label']} ({$entry['url']})";
            }
        }

        sort($homeless);

        $this->assertSame(
            [],
            $homeless,
            "These entries fall back to the last declared column instead of naming one:\n  "
            . implode("\n  ", $homeless)
        );
    }

    public function testEveryColumnNamedByAnEntryIsDeclared(): void
    {
        $unknown = [];

        foreach (self::allEntries() as $entry) {
            if ($entry['menuGroup'] === null) {
                continue;
            }
            if (!in_array($entry['menuGroup'], MenuBuilder::groupIdsFor($entry['menu']), true)) {
                $unknown[] = "{$entry['menu']} › {$entry['label']} → '{$entry['menuGroup']}'";
            }
        }

        sort($unknown);

        $this->assertSame([], $unknown, "These entries name a column their menu does not declare:\n  "
            . implode("\n  ", $unknown));
    }

    /**
     * The range above the boundary belongs to the pages a unit writes
     * itself. A shipped entry reaching into it would interleave with them
     * — and a unit reordering its own pages would find a page of the site
     * moving among them.
     */
    public function testNoShippedEntryReachesIntoTheRangeReservedForTextPages(): void
    {
        $base = self::textPageBase();
        $trespassing = [];

        foreach (self::allEntries() as $entry) {
            if ($entry['order'] >= $base) {
                $trespassing[] = "{$entry['menu']} › {$entry['label']} = {$entry['order']}";
            }
        }

        sort($trespassing);

        $this->assertSame(
            [],
            $trespassing,
            "Orders from {$base} up belong to the unit's own free-text pages:\n  "
            . implode("\n  ", $trespassing)
        );
    }

    /**
     * @return array<int, array{menu: string, label: string, url: string, order: int, menuGroup: ?string}>
     */
    private static function allEntries(): array
    {
        $entries = [];
        foreach ([...MenuInventory::corePages(), ...MenuInventory::modulePages(false)] as $page) {
            $entries[] = [
                'menu' => $page['menu'],
                'label' => $page['label'],
                'url' => $page['url'],
                'order' => $page['order'],
                'menuGroup' => $page['menuGroup'],
            ];
        }

        return $entries;
    }
}
