<?php

declare(strict_types=1);

namespace Tests\Core\Config;

use Core\Config\SettingException;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class SettingServiceTest extends TestCase
{
    private SettingService $service;
    private SettingRepository $repo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new SettingRepository($this->pdo);
        $this->service = new SettingService($this->repo);
    }

    public function testRegisterCreatesNewSetting(): void
    {
        $this->service->register('test_key', 'default_val', 'text', 'Label', 'Desc');

        $result = $this->repo->findByModuleAndKey(null, 'test_key');
        $this->assertNotNull($result);
        $this->assertSame('default_val', $result['setting_value']);
        $this->assertSame('text', $result['setting_type']);
    }

    public function testRegisterDoesNotOverwriteExistingValue(): void
    {
        $this->service->register('existing', 'original', 'text', 'Label', 'Desc');
        $this->repo->updateValue(null, 'existing', 'modified');

        // Re-register should NOT overwrite
        $this->service->register('existing', 'new_default', 'text', 'Label', 'Desc');

        $this->service->clearCache();
        $this->assertSame('modified', $this->service->get('existing'));
    }

    // --- pruneUndeclared(): a setting dropped from a module.json ---

    public function testPruneUndeclaredRemovesAModuleSettingTheManifestNoLongerDeclares(): void
    {
        $this->service->register('kept', '1', 'text', 'Gardé', 'Desc', 'calendar');
        $this->service->register('event_reminder_hour', '18:00', 'text', 'Heure', 'Desc', 'calendar');

        $this->assertSame(1, $this->service->pruneUndeclared('calendar', ['kept']));

        $this->assertNotNull($this->repo->findByModuleAndKey('calendar', 'kept'));
        $this->assertNull($this->repo->findByModuleAndKey('calendar', 'event_reminder_hour'));
    }

    /**
     * The restriction that makes this safe: every setting a module
     * registers at runtime rather than from its manifest (finance's
     * seeded/running bookkeeping flags) is registered non-editable, and is
     * therefore never a candidate for pruning.
     */
    public function testPruneUndeclaredNeverTouchesANonEditableInternalFlag(): void
    {
        $this->service->register('declared', '1', 'text', 'Déclaré', 'Desc', 'finance');
        $this->service->register('finance_seeded', '1', 'boolean', 'Interne', 'Desc', 'finance', null, null, false);

        $this->assertSame(0, $this->service->pruneUndeclared('finance', ['declared']));
        $this->assertNotNull($this->repo->findByModuleAndKey('finance', 'finance_seeded'));
    }

    public function testPruneUndeclaredLeavesOtherModulesAndCoreAlone(): void
    {
        $this->service->register('core_key', '1', 'text', 'Coeur', 'Desc');
        $this->service->register('other_key', '1', 'text', 'Autre', 'Desc', 'gallery');
        $this->service->register('declared', '1', 'text', 'Déclaré', 'Desc', 'calendar');

        $this->assertSame(0, $this->service->pruneUndeclared('calendar', ['declared']));

        $this->assertNotNull($this->repo->findByModuleAndKey(null, 'core_key'));
        $this->assertNotNull($this->repo->findByModuleAndKey('gallery', 'other_key'));
    }

    /**
     * An empty declared list is a caller mistake far more often than a
     * deliberate "clear this module's settings", so it deletes nothing.
     */
    public function testPruneUndeclaredIsANoOpWhenNothingIsDeclared(): void
    {
        $this->service->register('some_key', '1', 'text', 'Label', 'Desc', 'calendar');

        $this->assertSame(0, $this->service->pruneUndeclared('calendar', []));
        $this->assertNotNull($this->repo->findByModuleAndKey('calendar', 'some_key'));
    }

    public function testPruneUndeclaredClearsTheCacheSoAPrunedSettingStopsResolving(): void
    {
        $this->service->register('declared', '1', 'text', 'Déclaré', 'Desc', 'calendar');
        $this->service->register('gone', 'x', 'text', 'Parti', 'Desc', 'calendar');
        $this->assertSame('x', $this->service->get('gone', 'calendar'));

        $this->service->pruneUndeclared('calendar', ['declared']);

        $this->assertNull($this->service->get('gone', 'calendar'));
    }

    // --- deleteCoreSettings(): the only way a CORE setting ever goes ---

    /**
     * Core settings have no pruning mechanism at all: pruneUndeclared() is
     * scoped to a module_id, and nothing anywhere removes a
     * `module_id IS NULL` row that the composition root stopped
     * registering. That was harmless only while core never retired a
     * setting; retiring seven of them (the scheduler-continuation and
     * migration-chain settings) is what this exists for.
     */
    public function testDeleteCoreSettingsRemovesExactlyTheNamedCoreRows(): void
    {
        $this->service->register('scheduler_max_hops', '30', 'number', 'Plafond', 'Desc');
        $this->service->register('scheduler_chain_hops', '0', 'number', 'Compteur', 'Desc', null, null, null, false);
        $this->service->register('base_url', 'https://exemple.be', 'url', 'URL', 'Desc');

        $this->assertSame(
            2,
            $this->repo->deleteCoreSettings(['scheduler_max_hops', 'scheduler_chain_hops'])
        );

        $this->assertNull($this->repo->findByModuleAndKey(null, 'scheduler_max_hops'));
        $this->assertNull($this->repo->findByModuleAndKey(null, 'scheduler_chain_hops'));
        $this->assertNotNull($this->repo->findByModuleAndKey(null, 'base_url'));
    }

    /**
     * Unlike pruneUndeclared(), this deletes non-editable rows too — the
     * retired settings included internal counters nobody could ever see,
     * and leaving those behind would defeat the point. The safety here is
     * that the caller names every key, rather than computing a difference.
     */
    public function testDeleteCoreSettingsRemovesNonEditableRowsAsWell(): void
    {
        $this->service->register('migration_chain_hops', '0', 'number', 'Interne', 'Desc', null, null, null, false);

        $this->assertSame(1, $this->repo->deleteCoreSettings(['migration_chain_hops']));
        $this->assertNull($this->repo->findByModuleAndKey(null, 'migration_chain_hops'));
    }

    /**
     * A module's row that happens to share a key with a retired core one
     * must survive: the two are different settings, and only the core one
     * was retired.
     */
    public function testDeleteCoreSettingsNeverTouchesAModuleRowOfTheSameName(): void
    {
        $this->service->register('shared_key', '1', 'text', 'Coeur', 'Desc');
        $this->service->register('shared_key', '1', 'text', 'Module', 'Desc', 'gallery');

        $this->assertSame(1, $this->repo->deleteCoreSettings(['shared_key']));

        $this->assertNull($this->repo->findByModuleAndKey(null, 'shared_key'));
        $this->assertNotNull($this->repo->findByModuleAndKey('gallery', 'shared_key'));
    }

    public function testDeleteCoreSettingsIsANoOpOnAnEmptyListAndOnKeysThatDoNotExist(): void
    {
        $this->service->register('kept', '1', 'text', 'Gardé', 'Desc');

        $this->assertSame(0, $this->repo->deleteCoreSettings([]));
        $this->assertSame(0, $this->repo->deleteCoreSettings(['never_registered']));
        $this->assertNotNull($this->repo->findByModuleAndKey(null, 'kept'));
    }

    public function testRegisterSelfHealsDefaultValueOnAnAlreadyExistingRow(): void
    {
        $this->service->register('existing', 'original', 'text', 'Label', 'Desc');
        $this->repo->updateValue(null, 'existing', 'modified');

        // Simulates default_value having been NULL for a row that predates
        // this column (or whose declared default changed since) — the next
        // boot's register() call must still backfill it, not skip it just
        // because the row already exists.
        $this->service->register('existing', 'original', 'text', 'Label', 'Desc');

        $this->service->resetAllToDefaults();

        $this->service->clearCache();
        $this->assertSame('original', $this->service->get('existing'));
    }

    /**
     * The boot path calls register() ~130 times per request; a row that
     * already exists with an unchanged default must cost zero SQL. Proven
     * by deleting the row behind the service's back: a no-op register()
     * leaves it deleted, any INSERT or UPDATE would have recreated it or
     * thrown.
     */
    public function testRegisterIsANoOpWhenTheRowExistsWithTheSameDefault(): void
    {
        $this->service->register('warm', 'default_val', 'text', 'Label', 'Desc');
        // Prime the cache the way the boot path does before module loading.
        $this->service->get('warm');

        $this->pdo->exec("DELETE FROM settings WHERE setting_key = 'warm'");

        $this->service->register('warm', 'default_val', 'text', 'Label', 'Desc');

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'warm'");
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testRegisterHealsADefaultValueLeftNullByAnOlderRow(): void
    {
        $this->service->register('legacy', 'declared', 'text', 'Label', 'Desc');
        $this->pdo->exec("UPDATE settings SET default_value = NULL WHERE setting_key = 'legacy'");
        $this->service->clearCache();

        $this->service->register('legacy', 'declared', 'text', 'Label', 'Desc');

        $stmt = $this->pdo->query("SELECT default_value FROM settings WHERE setting_key = 'legacy'");
        $this->assertSame('declared', $stmt->fetchColumn());
    }

    public function testRegisteredSettingIsReadableWithoutClearingTheCache(): void
    {
        // Module settings register after the boot has already primed the
        // cache; they must resolve in the same request without a reload.
        $this->service->get('anything');
        $this->service->register('late_key', 'late_val', 'text', 'L', 'D', 'calendar');

        $this->assertSame('late_val', $this->service->get('late_key', 'calendar'));
    }

    public function testResetAllToDefaultsRestoresEveryChangedValue(): void
    {
        $this->service->register('key_a', 'default_a', 'text', 'L', 'D');
        $this->service->register('key_b', 'default_b', 'text', 'L', 'D');
        $this->repo->updateValue(null, 'key_a', 'changed_a');
        $this->repo->updateValue(null, 'key_b', 'changed_b');

        $this->service->resetAllToDefaults();

        $this->service->clearCache();
        $this->assertSame('default_a', $this->service->get('key_a'));
        $this->assertSame('default_b', $this->service->get('key_b'));
    }

    public function testGetReturnsValue(): void
    {
        $this->service->register('key1', 'value1', 'text', 'L', 'D');
        $this->service->clearCache();
        $this->assertSame('value1', $this->service->get('key1'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('fallback', $this->service->get('nonexistent', null, 'fallback'));
    }

    public function testSetUpdatesValue(): void
    {
        $this->service->register('editable_key', 'old', 'text', 'L', 'D');
        $this->service->clearCache();

        $this->service->set('editable_key', 'new');
        $this->assertSame('new', $this->service->get('editable_key'));
    }

    public function testSetThrowsForNonEditableSetting(): void
    {
        $this->service->register('readonly_key', 'val', 'text', 'L', 'D', null, null, null, false);
        $this->service->clearCache();

        $this->expectException(SettingException::class);
        $this->service->set('readonly_key', 'changed');
    }

    /**
     * SettingException is marked Core\Exception\UserFacingException, and
     * Core\Http\Controller\SettingsController::update() renders its
     * message straight into the settings dialog. It used to say
     * "Setting 'x' is not editable." there — English, to a French-speaking
     * chef d'unité, in violation of AGENTS.md § Language.
     */
    public function testEverySettingRefusalIsAFrenchSentenceNamingTheSetting(): void
    {
        $this->service->register('readonly_key', 'val', 'text', 'L', 'D', null, null, null, false);
        $this->service->register('email_key', '', 'email', 'L', 'D');
        $this->service->clearCache();

        $messages = [];
        foreach ([
            ['unknown_key', 'whatever'],
            ['readonly_key', 'changed'],
            ['email_key', 'not-an-email'],
        ] as [$key, $value]) {
            try {
                $this->service->set($key, $value);
                $this->fail("set('{$key}') was expected to be refused");
            } catch (SettingException $e) {
                $messages[$key] = $e->getMessage();
            }
        }

        foreach ($messages as $key => $message) {
            $this->assertStringNotContainsString('Setting', $message);
            $this->assertStringNotContainsString('not editable', $message);
            $this->assertStringNotContainsString('Invalid value', $message);
            // The key stays visible: it is the label the chef just clicked.
            $this->assertStringContainsString($key, $message);
            $this->assertStringEndsWith('.', $message);
        }

        $this->assertStringContainsString('introuvable', $messages['unknown_key']);
        $this->assertStringContainsString('modifiable', $messages['readonly_key']);
        $this->assertStringContainsString('invalide', $messages['email_key']);
    }

    /**
     * setInternal() bypasses the `editable` guard, not the message rule —
     * its refusals reach the same dialog through the same class.
     */
    public function testSetInternalRefusalsAreFrenchToo(): void
    {
        $this->expectException(SettingException::class);
        $this->expectExceptionMessage('Le réglage « no_such_key » est introuvable');
        $this->service->setInternal('no_such_key', 'value');
    }

    public function testValidateEmail(): void
    {
        $this->service->register('email_key', '', 'email', 'L', 'D');
        $this->assertTrue($this->service->validate('email_key', 'test@example.com'));
        $this->assertFalse($this->service->validate('email_key', 'not-an-email'));
        $this->assertTrue($this->service->validate('email_key', '')); // empty allowed
    }

    public function testValidateUrl(): void
    {
        $this->service->register('url_key', '', 'url', 'L', 'D');
        $this->assertTrue($this->service->validate('url_key', 'https://example.com'));
        $this->assertFalse($this->service->validate('url_key', 'not a url'));
    }

    public function testValidateNumber(): void
    {
        $this->service->register('num_key', '0', 'number', 'L', 'D');
        $this->assertTrue($this->service->validate('num_key', '42'));
        $this->assertTrue($this->service->validate('num_key', '3.14'));
        $this->assertFalse($this->service->validate('num_key', 'abc'));
    }

    public function testValidateBoolean(): void
    {
        $this->service->register('bool_key', '0', 'boolean', 'L', 'D');
        $this->assertTrue($this->service->validate('bool_key', '0'));
        $this->assertTrue($this->service->validate('bool_key', '1'));
        $this->assertFalse($this->service->validate('bool_key', 'yes'));
    }

    public function testValidateSelect(): void
    {
        $this->service->register('sel_key', 'a', 'select', 'L', 'D', null, null, ['a', 'b', 'c']);
        $this->service->clearCache();
        $this->assertTrue($this->service->validate('sel_key', 'a'));
        $this->assertTrue($this->service->validate('sel_key', 'b'));
        $this->assertFalse($this->service->validate('sel_key', 'd'));
    }

    public function testValidateWithRegex(): void
    {
        $this->service->register('regex_key', '', 'text', 'L', 'D', null, '^[A-Z]{3}$');
        $this->assertTrue($this->service->validate('regex_key', 'ABC'));
        $this->assertFalse($this->service->validate('regex_key', 'abc'));
        $this->assertFalse($this->service->validate('regex_key', 'ABCD'));
    }

    public function testCacheIsUsed(): void
    {
        $this->service->register('cached', 'val', 'text', 'L', 'D');
        $this->service->clearCache();

        // First call loads cache
        $this->assertSame('val', $this->service->get('cached'));

        // Modify directly in DB (bypassing service)
        $this->repo->updateValue(null, 'cached', 'changed');

        // Should still return cached value
        $this->assertSame('val', $this->service->get('cached'));

        // After clear, should reflect change
        $this->service->clearCache();
        $this->assertSame('changed', $this->service->get('cached'));
    }

    public function testGetAllGroupedReturnsGroupedSettings(): void
    {
        $this->service->register('core_a', 'v1', 'text', 'Core A', 'Desc A');
        $this->service->register('mod_a', 'v2', 'text', 'Mod A', 'Desc A', 'calendar');
        $this->service->register('mod_b', 'v3', 'text', 'Mod B', 'Desc B', 'calendar');

        $groups = $this->service->getAllGrouped();
        $this->assertArrayHasKey('core', $groups);
        $this->assertArrayHasKey('calendar', $groups);
        $this->assertCount(1, $groups['core']['settings']);
        $this->assertCount(2, $groups['calendar']['settings']);
    }

    public function testModuleIdIsolation(): void
    {
        $this->service->register('same_key', 'core_val', 'text', 'L', 'D');
        $this->service->register('same_key', 'mod_val', 'text', 'L', 'D', 'mymodule');
        $this->service->clearCache();

        $this->assertSame('core_val', $this->service->get('same_key'));
        $this->assertSame('mod_val', $this->service->get('same_key', 'mymodule'));
    }

    public function testClaimIfEmptyWritesOnlyWhileTheValueIsEmpty(): void
    {
        $this->service->register('claimable', '', 'text', 'L', 'D');

        $this->assertTrue($this->service->claimIfEmpty('claimable', 'first'));
        $this->assertSame('first', $this->service->get('claimable'));

        $this->assertFalse($this->service->claimIfEmpty('claimable', 'second'));
        $this->assertSame('first', $this->service->get('claimable'));
    }

    /**
     * Two requests booting the same module at the same moment.
     *
     * register() decides « the row exists » from a cache loaded at the
     * start of the request; `settings` has a unique index on
     * (module_id, setting_key), so the second INSERT gets a 23000. It used
     * to escape — register() runs from the composition root, so the loser
     * of the race 500ed the whole page. Seen in production on
     * `fees-fees_federal_scale_url`, in a support archive's event journal
     * as an uncaught PDOException.
     *
     * The row the loser wanted exists with the values it wanted, so the
     * race has no loser: absorbed, cache dropped, page served.
     */
    public function testARowInsertedByAConcurrentRequestIsNotAFatalError(): void
    {
        // The second request loads its snapshot first — the row does not
        // exist yet, so its register() will decide to INSERT.
        $stale = new SettingService(new SettingRepository($this->pdo));
        $this->assertNull($stale->get('race_key', 'fees'));

        // The first request wins the race and creates the row.
        $winner = new SettingService(new SettingRepository($this->pdo));
        $winner->register('race_key', 'v', 'text', 'L', 'D', 'fees');

        // The loser now INSERTs against a unique index that is taken.
        $stale->register('race_key', 'v', 'text', 'L', 'D', 'fees');

        $this->assertSame('v', $stale->get('race_key', 'fees'));
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'race_key'")->fetchColumn()
        );
    }

    /**
     * A genuine SQL fault must still be loud — the absorption above is
     * for the duplicate key and for nothing else.
     */
    public function testAnySqlFaultOtherThanTheDuplicateStillThrows(): void
    {
        $this->pdo->exec('DROP TABLE settings');

        $this->expectException(\PDOException::class);

        $this->service->register('anything', 'v', 'text', 'L', 'D');
    }

    public function testClaimIfEmptyReportsFalseForAnUnknownSetting(): void
    {
        $this->assertFalse($this->service->claimIfEmpty('never_registered', 'value'));
        $this->assertNull($this->service->get('never_registered'));
    }

    public function testClaimIfEmptyTreatsNullAsEmpty(): void
    {
        $this->service->register('claimable_null', '', 'text', 'L', 'D');
        $this->pdo->exec("UPDATE settings SET setting_value = NULL WHERE setting_key = 'claimable_null'");
        $this->service->clearCache();

        $this->assertTrue($this->service->claimIfEmpty('claimable_null', 'value'));
        $this->assertSame('value', $this->service->get('claimable_null'));
    }
}
