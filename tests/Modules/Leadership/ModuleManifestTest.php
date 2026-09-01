<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest is validated at load time by Core\Module\ModuleManager, so a
 * mistake here takes the module out of every menu with only a badge on the
 * Modules page to show for it. Same precedent as
 * Tests\Modules\Groups\ModuleManifestTest.
 */
class ModuleManifestTest extends TestCase
{
    private ModuleManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/leadership/module.json');
    }

    public function testTheManifestParsesAndValidates(): void
    {
        $this->assertSame('leadership', $this->manifest->id);
        $this->assertSame('Encadrement', $this->manifest->name);
        $this->assertFalse($this->manifest->enabledByDefault);
    }

    /**
     * ModuleManager only re-applies schema.sql when the manifest version is
     * greater than the installed one, so a schema change without a bump is
     * silently a no-op on every already-enabled install (AGENTS.md
     * § Database). Editing schema.sql should break this test — the fix is
     * to bump module.json, which is the whole point.
     */
    public function testTheVersionIsBumpedWheneverTheSchemaChanges(): void
    {
        $this->assertSame('1.1.1', $this->manifest->version);
    }

    /**
     * Every page of this module reads personal data of the unit's staff and
     * shows it by name, so every one of them is admin — the floor of the
     * Espace chefs d'U menu. A single route slipping to chief would open
     * all of it to every animateur.
     */
    public function testEveryRouteIsAdminOnTheUnitStaffMenu(): void
    {
        foreach ($this->manifest->routes as $route) {
            $this->assertSame('admin', $route['role_min'], "Route {$route['path']} must be admin.");
            $this->assertSame('espace_admin', $route['menu'], "Route {$route['path']} must sit in Espace chefs d'U.");
        }
    }

    /** The module's one write is a POST; every read is a GET. */
    public function testTheOnlyWriteRouteIsAPost(): void
    {
        $writes = array_values(array_filter(
            $this->manifest->routes,
            static fn (array $route): bool => $route['method'] === 'POST'
        ));

        $this->assertCount(1, $writes);
        $this->assertSame('/admin/leadership/training/mapping', $writes[0]['path']);
    }

    /** Exactly one menu entry: the overview. The three sub-pages hang off it. */
    public function testOnlyTheOverviewAppearsInTheMenu(): void
    {
        $labelled = array_values(array_filter(
            $this->manifest->routes,
            static fn (array $route): bool => $route['label'] !== ''
        ));

        $this->assertCount(1, $labelled);
        $this->assertSame('/admin/leadership', $labelled[0]['path']);
        $this->assertSame('Encadrement', $labelled[0]['label']);
        // AGENTS.md: a labelled route names the mega-menu column it is
        // drawn in. Without one it fell into the untitled remainder of
        // « Espace chefs d'U » — a module about following the staff, next
        // to nothing, while « Suivi » stood right beside it.
        $this->assertSame('suivi', $labelled[0]['menu_group']);
    }

    /**
     * No page of this module is ever cached on a device.
     *
     * Every one of them lists named members of the unit's staff alongside
     * an administrative situation, and ARCHITECTURE.md §8.25 keeps
     * admin/configuration pages out of the offline cache without exception.
     * An `offline` section here would be a privacy decision, not a config
     * change — this asserts nobody made it by accident.
     */
    public function testNoPageIsOfferedOffline(): void
    {
        $this->assertSame([], $this->manifest->offline);
    }

    /**
     * The module declares no cookie, no setting and no scheduled task: it
     * computes everything at display time from core tables, which is what
     * makes it unable to drift out of step with Desk.
     */
    public function testTheModuleDeclaresNoStateOfItsOwn(): void
    {
        $this->assertSame([], $this->manifest->cookies);
        $this->assertSame([], $this->manifest->settings);
        $this->assertSame([], $this->manifest->scheduledTasks);
    }

    /**
     * The unit note lives in `editable_contents` under one fixed key, so
     * the module needs no table for it — and one note for the unit rather
     * than one per member or per section.
     */
    public function testTheSchemaDeclaresExactlyOneTable(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 3) . '/modules/leadership/schema.sql');
        $this->assertNotFalse($schema);

        $this->assertSame(
            1,
            preg_match_all('/^CREATE TABLE/mi', $schema),
            'The module stores its formation-level vocabulary and nothing else.'
        );
        $this->assertStringContainsString('leadership_formation_levels', $schema);
    }
}
