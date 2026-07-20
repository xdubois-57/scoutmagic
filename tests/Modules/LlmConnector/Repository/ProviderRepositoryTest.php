<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Repository;

use Core\Security\EncryptionService;
use Modules\LlmConnector\Repository\ProviderRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class ProviderRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ProviderRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->createLlmTables();

        $this->encryption = new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );

        $this->repo = new ProviderRepository($this->pdo, $this->encryption);
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-secret-key', true);

        $this->assertGreaterThan(0, $id);

        $provider = $this->repo->findById($id);
        $this->assertNotNull($provider);
        $this->assertSame('Anthropic', $provider['name']);
        $this->assertSame('anthropic', $provider['driver']);
        $this->assertSame('https://api.anthropic.com', $provider['api_endpoint']);
        $this->assertSame('sk-secret-key', $provider['api_key']);
        $this->assertTrue($provider['is_active']);
    }

    public function testApiKeyIsEncryptedInDatabase(): void
    {
        $plainKey = 'sk-ant-very-secret-api-key-12345';
        $id = $this->repo->create('Test', 'anthropic', 'https://api.anthropic.com', $plainKey, true);

        // Read raw value from DB
        $stmt = $this->pdo->prepare('SELECT api_key FROM llm_providers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertNotSame($plainKey, $row['api_key']);

        // Verify decryption returns original
        $provider = $this->repo->findById($id);
        $this->assertSame($plainKey, $provider['api_key']);
    }

    public function testFindAll(): void
    {
        $this->repo->create('Provider A', 'anthropic', 'https://a.example.com', 'key-a', true);
        $this->repo->create('Provider B', 'anthropic', 'https://b.example.com', 'key-b', false);

        $all = $this->repo->findAll();
        $this->assertCount(2, $all);

        // findAll does NOT include api_key
        $this->assertArrayNotHasKey('api_key', $all[0]);
    }

    public function testFindFirstActive(): void
    {
        $this->repo->create('Inactive', 'anthropic', 'https://inactive.example.com', 'key-1', false);
        $id2 = $this->repo->create('Active', 'anthropic', 'https://active.example.com', 'key-2', true);

        $active = $this->repo->findFirstActive();
        $this->assertNotNull($active);
        $this->assertSame($id2, $active['id']);
        $this->assertSame('Active', $active['name']);
        $this->assertSame('key-2', $active['api_key']);
    }

    public function testFindFirstActiveReturnsNullWhenNoneActive(): void
    {
        $this->repo->create('Inactive', 'anthropic', 'https://example.com', 'key', false);

        $this->assertNull($this->repo->findFirstActive());
    }

    public function testFindAllActive(): void
    {
        $this->repo->create('Active1', 'anthropic', 'https://a.example.com', 'key-a', true);
        $this->repo->create('Inactive', 'anthropic', 'https://b.example.com', 'key-b', false);
        $this->repo->create('Active2', 'anthropic', 'https://c.example.com', 'key-c', true);

        $active = $this->repo->findAllActive();
        $this->assertCount(2, $active);
        $this->assertSame('Active1', $active[0]['name']);
        $this->assertSame('Active2', $active[1]['name']);
    }

    public function testUpdate(): void
    {
        $id = $this->repo->create('Old Name', 'anthropic', 'https://old.example.com', 'old-key', true);

        $this->repo->update($id, 'New Name', 'anthropic', 'https://new.example.com', 'new-key', false);

        $provider = $this->repo->findById($id);
        $this->assertSame('New Name', $provider['name']);
        $this->assertSame('https://new.example.com', $provider['api_endpoint']);
        $this->assertSame('new-key', $provider['api_key']);
        $this->assertFalse($provider['is_active']);
    }

    public function testUpdateWithNullApiKeyKeepsExistingKey(): void
    {
        $id = $this->repo->create('Provider', 'anthropic', 'https://api.example.com', 'original-key', true);

        $this->repo->update($id, 'Provider Updated', 'anthropic', 'https://api.example.com', null, true);

        $provider = $this->repo->findById($id);
        $this->assertSame('Provider Updated', $provider['name']);
        $this->assertSame('original-key', $provider['api_key']);
    }

    public function testDelete(): void
    {
        $id = $this->repo->create('ToDelete', 'anthropic', 'https://example.com', 'key', true);
        $this->assertNotNull($this->repo->findById($id));

        $this->repo->delete($id);
        $this->assertNull($this->repo->findById($id));
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    private function createLlmTables(): void
    {
        $this->pdo->exec('CREATE TABLE llm_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            driver TEXT NOT NULL,
            api_endpoint TEXT NOT NULL,
            api_key BLOB NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
