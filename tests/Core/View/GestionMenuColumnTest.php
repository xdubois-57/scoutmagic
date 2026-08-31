<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Module\ModuleManager;
use Core\Module\ModuleManifest;
use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The « Gestion » column of Espace animateurs is a curated order —
 * Finances, Statistiques, Prévisions — held by explicit `menu_order`
 * values in three different modules' manifests (ARCHITECTURE.md §7.1).
 *
 * Explicit values are absolute: ModuleManager::loadModule() adds no
 * module-position offset to them, which is exactly what makes the order
 * survive an admin reordering modules on /config/modules. That is the
 * property worth pinning, because nothing else in the codebase says these
 * three pages belong together, and the failure mode — a column that
 * silently reshuffles on somebody else's install — is invisible here.
 *
 * The column is read from the real manifests rather than from a fixture:
 * a fourth page landing in « Gestion » without an explicit `menu_order`
 * would sort behind all three (a default value is 1000-and-up) and must
 * fail this test rather than ship.
 */
class GestionMenuColumnTest extends TestCase
{
    private const MENU = MenuBuilder::MENU_ESPACE_CHEFS;
    private const GROUP = 'gestion';

    public function testGestionColumnIsFinancesStatistiquesPrevisions(): void
    {
        $this->assertSame(
            ['Finances', 'Statistiques', 'Prévisions'],
            $this->gestionColumnLabels($this->moduleIdsInDiscoveryOrder())
        );
    }

    public function testGestionColumnOrderDoesNotFollowModuleSortOrder(): void
    {
        $natural = $this->moduleIdsInDiscoveryOrder();
        $reversed = array_reverse($natural);

        $this->assertNotSame($natural, $reversed, 'The fixture needs at least two modules to be meaningful');

        $this->assertSame(
            $this->gestionColumnLabels($natural),
            $this->gestionColumnLabels($reversed),
            'Reordering modules on /config/modules must not reshuffle the Gestion column'
        );
    }

    /**
     * Every module contributing a labelled Espace animateurs route, in the
     * order discoverModules() would return them (directory order).
     *
     * @return array<int, string>
     */
    private function moduleIdsInDiscoveryOrder(): array
    {
        $ids = [];

        foreach ($this->manifests() as $manifest) {
            foreach ($manifest->routes as $route) {
                if ($route['label'] !== '' && $route['menu'] === self::MENU) {
                    $ids[] = $manifest->id;
                    continue 2;
                }
            }
        }

        return $ids;
    }

    /**
     * Register every labelled Espace animateurs route of the given modules
     * — in the given module order — exactly the way
     * ModuleManager::loadModule() does, then read back the labels of the
     * « Gestion » column a chief sees.
     *
     * @param array<int, string> $moduleIdsInOrder
     * @return array<int, string>
     */
    private function gestionColumnLabels(array $moduleIdsInOrder): array
    {
        $builder = new MenuBuilder(Role::CHIEF);
        $manifests = [];

        foreach ($this->manifests() as $manifest) {
            $manifests[$manifest->id] = $manifest;
        }

        foreach (array_values($moduleIdsInOrder) as $position => $moduleId) {
            foreach ($manifests[$moduleId]->routes as $route) {
                if ($route['label'] === '' || $route['menu'] !== self::MENU) {
                    continue;
                }

                $builder->addPage(
                    $route['menu'],
                    $route['label'],
                    $route['path'],
                    $route['role_min'],
                    $this->effectiveMenuOrder($route, $position),
                    false,
                    null,
                    MenuBuilder::SORT_GROUP_MODULE,
                    $route['menu_icon'] ?? null,
                    null,
                    $route['menu_group'] ?? null
                );
            }
        }

        foreach ($builder->build() as $menu) {
            if ($menu['id'] !== self::MENU) {
                continue;
            }

            foreach ($menu['groups'] as $group) {
                if ($group['id'] === self::GROUP) {
                    return array_column($group['pages'], 'label');
                }
            }
        }

        return [];
    }

    /**
     * ModuleManager's own arithmetic, read off the class rather than
     * copied: an explicit menu_order is taken as-is, a default one gets
     * the module's position folded into it.
     *
     * @param array{menu_order: int, menu_order_explicit: bool} $route
     */
    private function effectiveMenuOrder(array $route, int $modulePosition): int
    {
        if ($route['menu_order_explicit']) {
            return $route['menu_order'];
        }

        $reflection = new \ReflectionClass(ModuleManager::class);
        /** @var int $base */
        $base = $reflection->getConstant('MODULE_ORDER_BASE');
        /** @var int $step */
        $step = $reflection->getConstant('MODULE_ORDER_STEP');

        return $base + ($modulePosition * $step) + $route['menu_order'];
    }

    /**
     * @return array<int, ModuleManifest>
     */
    private function manifests(): array
    {
        $manifests = [];

        foreach (glob(dirname(__DIR__, 3) . '/modules/*/module.json') ?: [] as $path) {
            $manifests[] = ModuleManifest::fromFile($path);
        }

        $this->assertNotEmpty($manifests, 'No module manifest found');

        return $manifests;
    }
}
