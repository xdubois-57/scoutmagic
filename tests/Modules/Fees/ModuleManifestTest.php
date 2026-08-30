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
        $this->assertSame('1.4.0', $this->manifest->version);
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
     * The one setting the module has: the federal page the barème's
     * « Chercher les montants » button reads. Declared with a description
     * (AGENTS.md § Module creation checklist) and defaulting to the real
     * page, so a unit that never touches Configuration > Réglages still
     * gets a working button the day it configures an AI connector.
     */
    public function testTheFederalScalePageIsADeclaredSetting(): void
    {
        $keys = array_column($this->manifest->settings, 'key');
        $this->assertSame(['fees_federal_scale_url'], $keys);

        $setting = $this->manifest->settings[0];
        $this->assertSame('url', $setting['type']);
        $this->assertNotSame('', $setting['description']);
        $this->assertSame(
            \Modules\Fees\Service\FederalScaleLookupService::DEFAULT_URL,
            $setting['default_value'],
            'the manifest default and the service constant must not drift'
        );
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
    /**
     * Finance is an OPTIONAL dependency (ARCHITECTURE.md §7.5) and must
     * never appear here: with it disabled the "conserver le PDF" control
     * simply is not offered, and the verification works exactly the same.
     * A hard requirement would make a unit install accounting software to
     * check its cotisations.
     */
    public function testTheModuleRequiresNoOtherModule(): void
    {
        $this->assertSame([], $this->manifest->requires);
    }

    /**
     * The invoice screens, including the POST — an upload route left off
     * the manifest is a 404 nobody notices until a treasurer submits.
     */
    public function testTheInvoiceRoutesAreDeclaredIncludingTheUpload(): void
    {
        $declared = array_map(
            static fn(array $route): string => $route['method'] . ' ' . $route['path'],
            $this->manifest->routes
        );

        $this->assertContains('GET /admin/fees/factures', $declared);
        $this->assertContains('GET /admin/fees/factures/import', $declared);
        $this->assertContains('POST /admin/fees/factures/import', $declared);
    }

    /**
     * Two tables carry the barème and the households a chef d'unité set
     * aside, and three the invoices themselves.
     *
     * The roster snapshot pair is deliberately NOT among them any more.
     * It was this module's first table and the reason it shipped before
     * it had a screen worth opening — but a frozen roster is a fact about
     * members, produced by the core's own Desk import, and a core needing
     * an optional module to describe its own import is the inversion
     * ARCHITECTURE.md §7.4 forbids. It lives in schema/core.sql now, and
     * this module reads it through Core\Import\RosterSnapshotRepository
     * like any other core table.
     */
    public function testTheSchemaDeclaresTheTablesTheModuleOwns(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 3) . '/modules/fees/schema.sql');
        $this->assertNotFalse($schema);

        $this->assertSame(5, preg_match_all('/^CREATE TABLE/mi', $schema));
        foreach ([
            'fees_household_tariffs',
            'fees_ignored_households',
            'fees_invoices',
            'fees_invoice_lines',
            'fees_invoice_people',
        ] as $table) {
            $this->assertStringContainsString($table, $schema);
        }

        $this->assertSame(0, preg_match_all('/^CREATE TABLE.*fees_roster_snapshot/mi', $schema));
    }

    /**
     * And the core declares them, which is the other half of the same
     * claim: a table nobody declares is a table nobody creates.
     */
    public function testTheCoreSchemaDeclaresTheRosterSnapshotThisModuleReads(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 3) . '/schema/core.sql');
        $this->assertNotFalse($schema);

        $this->assertSame(1, preg_match_all('/^CREATE TABLE fees_roster_snapshots \(/mi', $schema));
        $this->assertSame(1, preg_match_all('/^CREATE TABLE fees_roster_snapshot_members \(/mi', $schema));
    }

    /**
     * The invoice tables hold foreign keys and figures, never a name. An
     * invoice's people are matched to members at import time and only the
     * resulting id is kept; a person the site could not match is a row with
     * a NULL one, so the count stays right without a name being stored.
     * Whoever needs the name opens the PDF, which is what keeping it is
     * for.
     */
    public function testTheInvoiceTablesStoreNoName(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 3) . '/modules/fees/schema.sql');
        $this->assertNotFalse($schema);
        $ddl = (string) preg_replace('/^\s*--.*$/m', '', $schema);

        $tables = self::tableDdl($ddl, 'fees_invoices')
            . self::tableDdl($ddl, 'fees_invoice_lines')
            . self::tableDdl($ddl, 'fees_invoice_people');

        $this->assertStringNotContainsString('BLOB', $tables);
        $this->assertStringNotContainsString('name', $tables);
        $this->assertStringNotContainsString('birth', $tables);
    }

    /**
     * The snapshot holds foreign keys and codes, never a person. Names and
     * birth dates stay in `member_years`, which persists all year — so
     * there is no BLOB in either snapshot table, and one paragraph to add
     * to the RGPD page instead of a retention rule to invent.
     *
     * The module's one encrypted column lives elsewhere, in
     * `fees_ignored_households`: a chief's free-text reason about a
     * family's arrangements, held to the same rule as
     * `member_years.leaving_comment_encrypted`.
     */
    public function testTheSnapshotStoresNoPersonalData(): void
    {
        // In the core now, not this module — but still checked from here:
        // this module is what reads the snapshot, and the claim that it
        // carries no personal data is the reason it can be read freely.
        $schema = file_get_contents(dirname(__DIR__, 3) . '/schema/core.sql');
        $this->assertNotFalse($schema);
        // Comments explain the rule and therefore name what it forbids —
        // assert on the DDL itself, not on the prose above it.
        $ddl = (string) preg_replace('/^\s*--.*$/m', '', $schema);

        $snapshotTables = self::tableDdl($ddl, 'fees_roster_snapshots')
            . self::tableDdl($ddl, 'fees_roster_snapshot_members');

        $this->assertStringNotContainsString('BLOB', $snapshotTables);
        $this->assertStringNotContainsString('_encrypted', $snapshotTables);
        $this->assertStringNotContainsString('blind_index', $snapshotTables);
    }

    /**
     * The only free text this module stores is encrypted, and never
     * journaled — a reason written about a family's arrangements is exactly
     * the kind of sentence SECURITY.md §5 is about.
     */
    public function testTheIgnoredHouseholdReasonIsEncrypted(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 3) . '/modules/fees/schema.sql');
        $this->assertNotFalse($schema);
        $ddl = self::tableDdl((string) preg_replace('/^\s*--.*$/m', '', $schema), 'fees_ignored_households');

        $this->assertStringContainsString('reason_encrypted BLOB NOT NULL', $ddl);
    }

    private static function tableDdl(string $ddl, string $table): string
    {
        preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\s*\((.*?)\n\)/s', $ddl, $matches);

        return $matches[1] ?? '';
    }
}
