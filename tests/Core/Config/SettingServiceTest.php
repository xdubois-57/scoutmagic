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
}
