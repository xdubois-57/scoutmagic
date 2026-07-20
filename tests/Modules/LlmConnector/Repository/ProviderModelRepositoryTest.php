<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Repository;

use Core\Security\EncryptionService;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class ProviderModelRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ProviderRepository $providerRepo;
    private ProviderModelRepository $modelRepo;
    private int $providerId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->createLlmTables();

        $encryption = new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );

        $this->providerRepo = new ProviderRepository($this->pdo, $encryption);
        $this->modelRepo = new ProviderModelRepository($this->pdo);

        $this->providerId = $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
    }

    public function testUpsertInsertsNewModel(): void
    {
        $this->modelRepo->upsert($this->providerId, 'claude-3-haiku', 'Claude 3 Haiku');

        $models = $this->modelRepo->findByProvider($this->providerId);
        $this->assertCount(1, $models);
        $this->assertSame('claude-3-haiku', $models[0]['model_id']);
        $this->assertSame('Claude 3 Haiku', $models[0]['display_name']);
        $this->assertFalse($models[0]['is_tier_cheap']);
        $this->assertFalse($models[0]['is_tier_capable']);
    }

    public function testUpsertUpdatesExistingModel(): void
    {
        $this->modelRepo->upsert($this->providerId, 'claude-3-haiku', 'Claude 3 Haiku');
        $this->modelRepo->upsert($this->providerId, 'claude-3-haiku', 'Claude 3 Haiku (Updated)');

        $models = $this->modelRepo->findByProvider($this->providerId);
        $this->assertCount(1, $models);
        $this->assertSame('Claude 3 Haiku (Updated)', $models[0]['display_name']);
    }

    public function testFindByProviderReturnsEmptyForNoModels(): void
    {
        $models = $this->modelRepo->findByProvider($this->providerId);
        $this->assertSame([], $models);
    }

    public function testFindByProviderAndTierReturnsNullWhenUnassigned(): void
    {
        $this->modelRepo->upsert($this->providerId, 'claude-3-haiku', 'Claude 3 Haiku');

        $result = $this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP);
        $this->assertNull($result);
    }

    public function testAssignTierCheap(): void
    {
        $this->modelRepo->upsert($this->providerId, 'claude-3-haiku', 'Claude 3 Haiku');
        $models = $this->modelRepo->findByProvider($this->providerId);

        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CHEAP);

        $cheapModel = $this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP);
        $this->assertNotNull($cheapModel);
        $this->assertSame('claude-3-haiku', $cheapModel['model_id']);
    }

    public function testAssignTierCapable(): void
    {
        $this->modelRepo->upsert($this->providerId, 'claude-3-sonnet', 'Claude 3 Sonnet');
        $models = $this->modelRepo->findByProvider($this->providerId);

        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CAPABLE);

        $capableModel = $this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CAPABLE);
        $this->assertNotNull($capableModel);
        $this->assertSame('claude-3-sonnet', $capableModel['model_id']);
    }

    public function testAssignTierClearsOtherModelsOfSameTier(): void
    {
        $this->modelRepo->upsert($this->providerId, 'model-a', 'Model A');
        $this->modelRepo->upsert($this->providerId, 'model-b', 'Model B');

        $models = $this->modelRepo->findByProvider($this->providerId);
        $modelAId = $models[0]['id'];
        $modelBId = $models[1]['id'];

        // Assign CHEAP to model A
        $this->modelRepo->assignTier($modelAId, LlmTier::CHEAP);
        $cheapModel = $this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP);
        $this->assertSame($modelAId, $cheapModel['id']);

        // Reassign CHEAP to model B — model A should lose CHEAP
        $this->modelRepo->assignTier($modelBId, LlmTier::CHEAP);
        $cheapModel = $this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP);
        $this->assertSame($modelBId, $cheapModel['id']);

        // Verify model A no longer has CHEAP
        $modelsAfter = $this->modelRepo->findByProvider($this->providerId);
        $modelAAfter = array_filter($modelsAfter, fn($m) => $m['id'] === $modelAId);
        $modelAAfter = array_values($modelAAfter)[0];
        $this->assertFalse($modelAAfter['is_tier_cheap']);
    }

    public function testAssignTierDoesNotAffectOtherTier(): void
    {
        $this->modelRepo->upsert($this->providerId, 'model-a', 'Model A');
        $models = $this->modelRepo->findByProvider($this->providerId);
        $modelId = $models[0]['id'];

        // Assign both tiers to same model
        $this->modelRepo->assignTier($modelId, LlmTier::CHEAP);
        $this->modelRepo->assignTier($modelId, LlmTier::CAPABLE);

        $model = $this->modelRepo->findByProvider($this->providerId)[0];
        $this->assertTrue($model['is_tier_cheap']);
        $this->assertTrue($model['is_tier_capable']);
    }

    public function testUnassignTier(): void
    {
        $this->modelRepo->upsert($this->providerId, 'model-a', 'Model A');
        $models = $this->modelRepo->findByProvider($this->providerId);
        $modelId = $models[0]['id'];

        $this->modelRepo->assignTier($modelId, LlmTier::CHEAP);
        $this->assertNotNull($this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP));

        $this->modelRepo->unassignTier($modelId, LlmTier::CHEAP);
        $this->assertNull($this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP));
    }

    public function testAutoAssignTiers(): void
    {
        $this->modelRepo->upsert($this->providerId, 'claude-3-5-haiku-20241022', 'Claude 3.5 Haiku');
        $this->modelRepo->upsert($this->providerId, 'claude-sonnet-4-20250514', 'Claude Sonnet 4');
        $this->modelRepo->upsert($this->providerId, 'claude-3-opus-20240229', 'Claude 3 Opus');

        $this->modelRepo->autoAssignTiers($this->providerId, [
            'cheap' => 'claude-3-5-haiku-20241022',
            'capable' => 'claude-sonnet-4-20250514',
        ]);

        $cheap = $this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP);
        $capable = $this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CAPABLE);

        $this->assertNotNull($cheap);
        $this->assertSame('claude-3-5-haiku-20241022', $cheap['model_id']);
        $this->assertNotNull($capable);
        $this->assertSame('claude-sonnet-4-20250514', $capable['model_id']);

        // Opus should have no tier
        $models = $this->modelRepo->findByProvider($this->providerId);
        $opus = array_values(array_filter($models, fn($m) => $m['model_id'] === 'claude-3-opus-20240229'));
        $this->assertFalse($opus[0]['is_tier_cheap']);
        $this->assertFalse($opus[0]['is_tier_capable']);
    }

    public function testAutoAssignTiersClearsPreviousAssignments(): void
    {
        $this->modelRepo->upsert($this->providerId, 'old-haiku', 'Old Haiku');
        $this->modelRepo->upsert($this->providerId, 'new-haiku', 'New Haiku');

        // First assignment
        $this->modelRepo->autoAssignTiers($this->providerId, ['cheap' => 'old-haiku', 'capable' => null]);
        $this->assertSame('old-haiku', $this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP)['model_id']);

        // Reassign — old-haiku should lose its tier
        $this->modelRepo->autoAssignTiers($this->providerId, ['cheap' => 'new-haiku', 'capable' => null]);
        $cheap = $this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP);
        $this->assertSame('new-haiku', $cheap['model_id']);

        $models = $this->modelRepo->findByProvider($this->providerId);
        $old = array_values(array_filter($models, fn($m) => $m['model_id'] === 'old-haiku'));
        $this->assertFalse($old[0]['is_tier_cheap']);
    }

    public function testAutoAssignTiersWithNulls(): void
    {
        $this->modelRepo->upsert($this->providerId, 'some-model', 'Some Model');

        $this->modelRepo->autoAssignTiers($this->providerId, ['cheap' => null, 'capable' => null]);

        $this->assertNull($this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP));
        $this->assertNull($this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CAPABLE));
    }

    public function testMultipleProviderIsolation(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $provider2Id = $this->providerRepo->create('Other', 'anthropic', 'https://other.example.com', 'key2', true);

        $this->modelRepo->upsert($this->providerId, 'shared-model', 'Shared Model');
        $this->modelRepo->upsert($provider2Id, 'shared-model', 'Shared Model');

        $modelsP1 = $this->modelRepo->findByProvider($this->providerId);
        $modelsP2 = $this->modelRepo->findByProvider($provider2Id);

        // Assign CHEAP on provider 1
        $this->modelRepo->assignTier($modelsP1[0]['id'], LlmTier::CHEAP);

        // Provider 2 should NOT have CHEAP assigned
        $this->assertNull($this->modelRepo->findByProviderAndTier($provider2Id, LlmTier::CHEAP));
        $this->assertNotNull($this->modelRepo->findByProviderAndTier($this->providerId, LlmTier::CHEAP));
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

        $this->pdo->exec('CREATE TABLE llm_provider_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL,
            model_id TEXT NOT NULL,
            display_name TEXT NOT NULL,
            is_tier_cheap INTEGER NOT NULL DEFAULT 0,
            is_tier_capable INTEGER NOT NULL DEFAULT 0,
            last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(provider_id, model_id),
            FOREIGN KEY (provider_id) REFERENCES llm_providers(id) ON DELETE CASCADE
        )');
    }
}
