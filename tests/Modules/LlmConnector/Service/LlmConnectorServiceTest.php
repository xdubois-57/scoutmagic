<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Service;

use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\LlmConnectorService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class LlmConnectorServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ProviderRepository $providerRepo;
    private ProviderModelRepository $modelRepo;
    private JournalService $journalService;
    private LlmConnectorService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->createLlmTables();

        $this->encryption = new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );

        $this->providerRepo = new ProviderRepository($this->pdo, $this->encryption);
        $this->modelRepo = new ProviderModelRepository($this->pdo);
        $this->journalService = $this->createMock(JournalService::class);

        $this->service = new LlmConnectorService(
            $this->providerRepo,
            $this->modelRepo,
            $this->journalService
        );
    }

    public function testIsAvailableReturnsFalseWhenNoProvider(): void
    {
        $this->assertFalse($this->service->isAvailable());
    }

    public function testIsAvailableReturnsFalseWhenNoModelAssigned(): void
    {
        $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $this->assertFalse($this->service->isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenCheapModelAssigned(): void
    {
        $providerId = $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $this->modelRepo->upsert($providerId, 'claude-haiku', 'Claude Haiku');

        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CHEAP);

        $this->assertTrue($this->service->isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenCapableModelAssigned(): void
    {
        $providerId = $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $this->modelRepo->upsert($providerId, 'claude-sonnet', 'Claude Sonnet');

        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CAPABLE);

        $this->assertTrue($this->service->isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenOnlyOcrModelAssigned(): void
    {
        $providerId = $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $this->modelRepo->upsert($providerId, 'claude-haiku', 'Claude Haiku');

        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::OCR);

        $this->assertTrue($this->service->isAvailable());
    }

    public function testCompleteThrowsNoProviderWhenNoneConfigured(): void
    {
        $request = new LlmRequest(LlmTier::CHEAP, 'Hello');

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::NO_PROVIDER);

        $this->service->complete($request);
    }

    public function testCompleteThrowsNoModelWhenTierUnassigned(): void
    {
        $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $request = new LlmRequest(LlmTier::CHEAP, 'Hello');

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::NO_MODEL);

        $this->service->complete($request);
    }

    public function testCompleteThrowsApiErrorForUnknownDriver(): void
    {
        $providerId = $this->providerRepo->create('Unknown', 'unknown_driver', 'https://example.com', 'key', true);
        $this->modelRepo->upsert($providerId, 'model-1', 'Model 1');

        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CHEAP);

        $request = new LlmRequest(LlmTier::CHEAP, 'Hello');

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::API_ERROR);

        $this->service->complete($request);
    }

    public function testIsAvailableIgnoresInactiveProviders(): void
    {
        $providerId = $this->providerRepo->create('Inactive', 'anthropic', 'https://api.anthropic.com', 'sk-test', false);
        $this->modelRepo->upsert($providerId, 'claude-haiku', 'Claude Haiku');

        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CHEAP);

        $this->assertFalse($this->service->isAvailable());
    }

    private function extractJson(string $content): string
    {
        $method = new \ReflectionMethod(LlmConnectorService::class, 'extractJson');
        $method->setAccessible(true);

        return $method->invoke($this->service, $content);
    }

    public function testExtractJsonReturnsBareJsonUnchanged(): void
    {
        $this->assertSame('{"amount": 12.5}', $this->extractJson('{"amount": 12.5}'));
    }

    public function testExtractJsonStripsMarkdownCodeFence(): void
    {
        $content = "```json\n{\"amount\": 12.5}\n```";

        $this->assertSame('{"amount": 12.5}', $this->extractJson($content));
    }

    public function testExtractJsonStripsCodeFenceWithoutLanguageTag(): void
    {
        $content = "```\n{\"amount\": 12.5}\n```";

        $this->assertSame('{"amount": 12.5}', $this->extractJson($content));
    }

    public function testExtractJsonFindsObjectAmongSurroundingText(): void
    {
        $content = "Voici le résultat :\n{\"amount\": 12.5}\nMerci.";

        $this->assertSame('{"amount": 12.5}', $this->extractJson($content));
    }

    public function testExtractJsonReturnsOriginalContentWhenNoObjectFound(): void
    {
        $this->assertSame('not json at all', $this->extractJson('not json at all'));
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
            is_tier_ocr INTEGER NOT NULL DEFAULT 0,
            last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(provider_id, model_id),
            FOREIGN KEY (provider_id) REFERENCES llm_providers(id) ON DELETE CASCADE
        )');
    }
}
