<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest is validated at load time by Core\Module\ModuleManager, so a
 * mistake here takes the module out of every menu with only a badge on the
 * Modules page to show for it. Same precedent as
 * Tests\Modules\Leadership\ModuleManifestTest.
 */
class ModuleManifestTest extends TestCase
{
    private ModuleManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/attestations/module.json');
    }

    public function testTheManifestParsesAndValidates(): void
    {
        $this->assertSame('attestations', $this->manifest->id);
        $this->assertSame('Attestations', $this->manifest->name);
    }

    /**
     * A unit that hands out no certificates must not find this page in its
     * menu on the morning it updates. Nothing here is useful without a
     * federation PDF to deposit.
     */
    public function testTheModuleIsNotEnabledByDefault(): void
    {
        $this->assertFalse($this->manifest->enabledByDefault);
    }

    /**
     * The working rule this project keeps: touching schema.sql and bumping
     * the module version are one action (AGENTS.md § Database,
     * docs/module-development.md § Important rules). Editing the schema
     * should break this test — the fix is to bump module.json, which is the
     * whole point.
     */
    public function testTheVersionIsBumpedWheneverTheSchemaChanges(): void
    {
        $this->assertSame('1.0.0', $this->manifest->version);
    }

    /**
     * A deposited batch is the whole unit's nominative paperwork in one
     * file. `admin` is the floor of the Espace chefs d'U menu and the floor
     * this module lives at — a single route slipping to `chief` would open
     * every family's certificate to every animateur de section.
     *
     * No exception, including any export or internal API endpoint a later
     * iteration adds.
     */
    public function testEveryRouteIsAdminOnTheUnitStaffMenu(): void
    {
        $this->assertNotSame([], $this->manifest->routes);

        foreach ($this->manifest->routes as $route) {
            $this->assertSame('admin', $route['role_min'], "Route {$route['path']} must be admin.");
            $this->assertSame('espace_admin', $route['menu'], "Route {$route['path']} must sit in Espace chefs d'U.");
        }
    }

    /**
     * Nothing this module holds may ever be written to a visitor's device:
     * a certificate is owner-scoped by construction (ARCHITECTURE.md §8.3)
     * and the page that lists batches is an administration page. Declaring
     * an `offline` entry would be a privacy decision, not a config change.
     */
    public function testNoPageIsOfferedOffline(): void
    {
        $this->assertSame([], $this->manifest->offline);
    }

    /** The module sets no cookie, so it declares none. */
    public function testTheModuleDeclaresNoCookie(): void
    {
        $this->assertSame([], $this->manifest->cookies);
    }
}
