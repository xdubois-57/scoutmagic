<?php

declare(strict_types=1);

namespace Tests\Core\Module;

use Core\Module\ModuleRegistryRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class ModuleRegistryRepositoryTest extends TestCase
{
    private ModuleRegistryRepository $repo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new ModuleRegistryRepository($this->pdo);
    }

    public function testUpsertCreatesEntry(): void
    {
        $this->repo->upsert('calendar', true, '1.0.0', 1);

        $entry = $this->repo->findByModuleId('calendar');
        $this->assertNotNull($entry);
        $this->assertSame('calendar', $entry['module_id']);
        $this->assertTrue($entry['enabled']);
        $this->assertSame('1.0.0', $entry['installed_version']);
        $this->assertSame(1, $entry['enabled_by']);
        $this->assertNotNull($entry['enabled_at']);
    }

    public function testUpsertUpdatesExistingEntry(): void
    {
        $this->repo->upsert('calendar', true, '1.0.0', 1);
        $this->repo->upsert('calendar', true, '1.1.0', 2);

        $entry = $this->repo->findByModuleId('calendar');
        $this->assertSame('1.1.0', $entry['installed_version']);
        $this->assertSame(2, $entry['enabled_by']);
    }

    public function testFindByModuleIdReturnsNullForMissing(): void
    {
        $this->assertNull($this->repo->findByModuleId('nonexistent'));
    }

    public function testSetEnabledTogglesFlag(): void
    {
        $this->repo->upsert('test_mod', true, '1.0.0', 1);

        $this->repo->setEnabled('test_mod', false);
        $entry = $this->repo->findByModuleId('test_mod');
        $this->assertFalse($entry['enabled']);
        $this->assertNull($entry['enabled_at']);

        $this->repo->setEnabled('test_mod', true);
        $entry = $this->repo->findByModuleId('test_mod');
        $this->assertTrue($entry['enabled']);
        $this->assertNotNull($entry['enabled_at']);
    }

    public function testFindAllReturnsAllEntries(): void
    {
        $this->repo->upsert('mod_a', true, '1.0.0', null);
        $this->repo->upsert('mod_b', false, '2.0.0', null);
        $this->repo->upsert('mod_c', true, '0.1.0', null);

        $all = $this->repo->findAll();
        $this->assertCount(3, $all);

        $ids = array_map(fn(array $e) => $e['module_id'], $all);
        $this->assertContains('mod_a', $ids);
        $this->assertContains('mod_b', $ids);
        $this->assertContains('mod_c', $ids);
    }

    public function testUpsertAppendsNewEntryAfterCurrentMax(): void
    {
        $this->repo->upsert('mod_a', true, '1.0.0', null);
        $this->repo->upsert('mod_b', true, '1.0.0', null);
        $this->repo->upsert('mod_c', true, '1.0.0', null);

        $this->assertSame(0, $this->repo->findByModuleId('mod_a')['sort_order']);
        $this->assertSame(1, $this->repo->findByModuleId('mod_b')['sort_order']);
        $this->assertSame(2, $this->repo->findByModuleId('mod_c')['sort_order']);
    }

    public function testUpsertOnExistingEntryLeavesSortOrderUntouched(): void
    {
        $this->repo->upsert('mod_a', true, '1.0.0', null);
        $this->repo->upsert('mod_b', true, '1.0.0', null);
        $this->repo->reorder(['mod_b', 'mod_a']);

        $this->repo->upsert('mod_a', false, '1.1.0', null);

        $this->assertSame(1, $this->repo->findByModuleId('mod_a')['sort_order']);
    }

    public function testFindAllOrdersBySortOrderThenModuleId(): void
    {
        $this->repo->upsert('mod_a', true, '1.0.0', null);
        $this->repo->upsert('mod_b', true, '1.0.0', null);
        $this->repo->upsert('mod_c', true, '1.0.0', null);

        $this->repo->reorder(['mod_c', 'mod_a', 'mod_b']);

        $ids = array_map(fn(array $e) => $e['module_id'], $this->repo->findAll());
        $this->assertSame(['mod_c', 'mod_a', 'mod_b'], $ids);
    }

    public function testReorderPersistsPositionsByIndex(): void
    {
        $this->repo->upsert('mod_a', true, '1.0.0', null);
        $this->repo->upsert('mod_b', true, '1.0.0', null);

        $this->repo->reorder(['mod_b', 'mod_a']);

        $this->assertSame(0, $this->repo->findByModuleId('mod_b')['sort_order']);
        $this->assertSame(1, $this->repo->findByModuleId('mod_a')['sort_order']);
    }
}
