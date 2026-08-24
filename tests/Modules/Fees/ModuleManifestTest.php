<?php

declare(strict_types=1);

namespace Tests\Modules\Fees;

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
        $this->manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/fees/module.json');
    }

    public function testTheManifestParsesAndValidates(): void
    {
        $this->assertSame('fees', $this->manifest->id);
        $this->assertSame('Cotisations', $this->manifest->name);
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
        $this->assertSame('1.0.0', $this->manifest->version);
    }

    /**
     * Every route of this module is the unit leaders' own, at the floor of
     * the Espace chefs d'U menu. Nothing here is ever offered one level
     * below — not a page, not an export, not an internal endpoint: what a
     * unit is billed is the chefs d'unité's business.
     */
    public function testEveryRouteIsAdminOnTheUnitStaffMenu(): void
    {
        $this->assertNotSame([], $this->manifest->routes);
        foreach ($this->manifest->routes as $route) {
            $this->assertSame('admin', $route['role_min'], "Route {$route['path']} must be admin.");
            $this->assertSame('espace_admin', $route['menu'], "Route {$route['path']} must sit in Espace chefs d'U.");
        }
    }

    public function testTheHomePageIsTheOnlyMenuEntryAndNamesItsColumn(): void
    {
        $labelled = array_values(array_filter(
            $this->manifest->routes,
            static fn (array $route): bool => $route['label'] !== ''
        ));

        $this->assertCount(1, $labelled);
        $this->assertSame('/admin/fees', $labelled[0]['path']);
        $this->assertSame('Cotisations', $labelled[0]['label']);
        $this->assertSame('suivi', $labelled[0]['menu_group']);
    }

    /**
     * A snapshot names every member of the unit and what they are billed.
     * ARCHITECTURE.md §8.25 keeps admin pages out of the offline cache
     * without exception; an `offline` entry here would be a privacy
     * decision rather than a config change.
     */
    public function testNoPageIsOfferedOffline(): void
    {
        $this->assertSame([], $this->manifest->offline);
    }

    /**
     * The module carries no hard dependency: `finance` becomes an optional
     * one later (a kept invoice PDF becomes an expense receipt), and an
     * optional dependency is a nullable constructor argument, never a
     * `requires` entry (ARCHITECTURE.md §7.5).
     */
    public function testTheModuleRequiresNoOtherModule(): void
    {
        $this->assertSame([], $this->manifest->requires);
    }

    /**
     * Two tables, and the second one is the whole reason this module ships
     * before it has a screen worth opening: the composition of the roster
     * at each import is the only past state of Desk the site will ever
     * hold.
     */
    public function testTheSchemaDeclaresTheTwoSnapshotTables(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 3) . '/modules/fees/schema.sql');
        $this->assertNotFalse($schema);

        $this->assertSame(2, preg_match_all('/^CREATE TABLE/mi', $schema));
        $this->assertStringContainsString('fees_roster_snapshots', $schema);
        $this->assertStringContainsString('fees_roster_snapshot_members', $schema);
    }

    /**
     * The snapshot holds foreign keys and codes, never a person. Names and
     * birth dates stay in `member_years`, which persists all year — so
     * there is no BLOB here, nothing encrypted, and one line to add to the
     * RGPD page instead of a retention rule to invent.
     */
    public function testTheSnapshotStoresNoPersonalData(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 3) . '/modules/fees/schema.sql');
        $this->assertNotFalse($schema);
        // Comments explain the rule and therefore name what it forbids —
        // assert on the DDL itself, not on the prose above it.
        $ddl = (string) preg_replace('/^\s*--.*$/m', '', $schema);

        $this->assertStringNotContainsString('BLOB', $ddl);
        $this->assertStringNotContainsString('_encrypted', $ddl);
        $this->assertStringNotContainsString('blind_index', $ddl);
    }
}
