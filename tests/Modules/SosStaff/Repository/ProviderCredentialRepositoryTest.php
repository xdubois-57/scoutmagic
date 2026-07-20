<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Repository;

use Core\Security\EncryptionService;
use Modules\SosStaff\Repository\ProviderCredentialRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * @group database
 */
class ProviderCredentialRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ProviderCredentialRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repo = new ProviderCredentialRepository($this->pdo, $encryption);
    }

    public function testSaveCreatesNewRowWithEncryptedConfig(): void
    {
        $this->repo->save('ovh', ['application_key' => 'ak123']);

        $stmt = $this->pdo->query('SELECT config_encrypted FROM sos_provider_credentials');
        $raw = $stmt->fetchColumn();
        $this->assertStringNotContainsString('ak123', $raw);
    }

    public function testFindByProviderReturnsDecryptedConfig(): void
    {
        $this->repo->save('ovh', ['application_key' => 'ak123', 'application_secret' => 'as456']);

        $credential = $this->repo->findByProvider('ovh');

        $this->assertNotNull($credential);
        $this->assertSame('ovh', $credential->provider);
        $this->assertFalse($credential->isActive);
        $this->assertSame('ak123', $credential->config['application_key']);
        $this->assertSame('as456', $credential->config['application_secret']);
    }

    public function testFindByProviderReturnsNullForUnknownProvider(): void
    {
        $this->assertNull($this->repo->findByProvider('twilio'));
    }

    public function testSaveUpdatesExistingConfigInPlace(): void
    {
        $this->repo->save('ovh', ['application_key' => 'first']);
        $this->repo->save('ovh', ['application_key' => 'second']);

        $credentials = $this->repo->findAll();

        $this->assertCount(1, $credentials);
        $this->assertSame('second', $credentials[0]->config['application_key']);
    }

    public function testSetActiveMarksOnlyOneProviderActive(): void
    {
        $this->repo->save('ovh', []);
        $this->repo->save('twilio', []);

        $this->repo->setActive('ovh');

        $ovh = $this->repo->findByProvider('ovh');
        $twilio = $this->repo->findByProvider('twilio');
        $this->assertTrue($ovh->isActive);
        $this->assertFalse($twilio->isActive);

        $this->repo->setActive('twilio');

        $ovh = $this->repo->findByProvider('ovh');
        $twilio = $this->repo->findByProvider('twilio');
        $this->assertFalse($ovh->isActive);
        $this->assertTrue($twilio->isActive);
    }

    public function testFindActiveReturnsTheActiveProvider(): void
    {
        $this->repo->save('ovh', ['sos_number' => '+3212345678']);
        $this->repo->setActive('ovh');

        $active = $this->repo->findActive();

        $this->assertNotNull($active);
        $this->assertSame('ovh', $active->provider);
        $this->assertSame('+3212345678', $active->config['sos_number']);
    }

    public function testFindActiveReturnsNullWhenNoneActive(): void
    {
        $this->repo->save('ovh', []);

        $this->assertNull($this->repo->findActive());
    }

    public function testFindAllReturnsEveryProviderOrderedByName(): void
    {
        $this->repo->save('twilio', []);
        $this->repo->save('ovh', []);

        $all = $this->repo->findAll();

        $this->assertCount(2, $all);
        $this->assertSame('ovh', $all[0]->provider);
        $this->assertSame('twilio', $all[1]->provider);
    }
}
