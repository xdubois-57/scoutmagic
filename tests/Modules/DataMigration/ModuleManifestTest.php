<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest decisions of the chantier's lot A, pinned.
 */
final class ModuleManifestTest extends TestCase
{
    private ModuleManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/data_migration/module.json');
    }

    public function testTheManifestValidates(): void
    {
        $this->assertSame('data_migration', $this->manifest->id);
        $this->assertSame('Migration de données', $this->manifest->name);
    }

    /** A one-off tool: no unit should find it in its menu on the morning it updates. */
    public function testItIsNotEnabledByDefault(): void
    {
        $this->assertFalse($this->manifest->enabledByDefault);
    }

    /**
     * No `requires`: every import depends on a different module, so a hard
     * dependency on any of them would hide the six others. Each import
     * checks its own module instead (ImporterRegistry).
     */
    public function testItRequiresNoOtherModule(): void
    {
        $raw = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/modules/data_migration/module.json'), true);
        $this->assertArrayNotHasKey('requires', $raw);
        $this->assertSame('technique', $raw['category']);
    }

    /**
     * What it writes is the unit's whole history: `admin`, the floor of
     * Espace chefs d'U, on every route, and one labelled entry only — the
     * imports are reached from the home page, never from a menu.
     */
    public function testEveryRouteIsAdminAndOnlyTheHomePageIsInAMenu(): void
    {
        $labelled = [];
        foreach ($this->manifest->routes as $route) {
            $this->assertSame('admin', $route['role_min'], $route['path']);
            $this->assertSame('espace_admin', $route['menu'], $route['path']);
            if ($route['label'] !== '') {
                $labelled[] = $route;
            }
        }

        $this->assertCount(1, $labelled);
        $this->assertSame('/admin/migration-donnees', $labelled[0]['path']);
        $this->assertSame('services', $labelled[0]['menu_group']);
        $this->assertSame(190, $labelled[0]['menu_order']);
    }
}
