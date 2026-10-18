<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Service;

use Core\Attention\AttentionPoint;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\LastFailureStore;
use Modules\LlmConnector\Service\LlmAttentionProvider;
use Modules\LlmConnector\Service\LlmConnectorService;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\InMemorySettingService;
use Tests\DatabaseTestHelper;
use Tests\Modules\LlmConnector\StubHttpServer;

/**
 * Issue #718 § C point 9, the AI provider: not configured while the module
 * is on, and the last call that reached the provider failed — kept by the
 * connector, cleared by the next success, read without calling anybody.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class LlmAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private ProviderRepository $providers;
    private ProviderModelRepository $models;
    private InMemorySettingService $settings;
    private LastFailureStore $store;
    private LlmConnectorService $connector;
    private ?StubHttpServer $server = null;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->createLlmTables();
        $this->providers = new ProviderRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->models = new ProviderModelRepository($this->pdo);
        $this->settings = new InMemorySettingService([LastFailureStore::SETTING_AT => '', LastFailureStore::SETTING_CODE => '']);
        $this->store = new LastFailureStore($this->settings);
        $this->connector = new LlmConnectorService(
            $this->providers,
            $this->models,
            $this->createMock(JournalService::class),
            $this->store
        );
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    /** @return AttentionPoint[] */
    private function points(): array
    {
        return (new LlmAttentionProvider($this->connector, $this->store))->collect(1);
    }

    /** @param array<int, array{status?: int, body: string}> $responses */
    private function configuredProvider(array $responses): void
    {
        $this->server = StubHttpServer::start($responses);
        $id = $this->providers->create('Scaleway', 'scaleway', $this->server->baseUrl(), 'key', true);
        $this->models->upsert($id, 'model-1', 'Model 1');
        $this->models->assignTier($this->models->findByProvider($id)[0]['id'], LlmTier::CHEAP);
    }

    private static function answer(): array
    {
        return ['body' => (string) json_encode([
            'choices' => [['message' => ['content' => 'Bonjour'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])];
    }

    public function testNoProviderIsAnAttentionPoint(): void
    {
        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertSame("L'intelligence artificielle n'est pas configurée", $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame('/config/llm', $points[0]->actionUrl);
    }

    public function testAWorkingProviderRaisesNothing(): void
    {
        $this->configuredProvider([self::answer()]);
        $this->connector->complete(new LlmRequest(LlmTier::CHEAP, 'Bonjour'));

        $this->assertSame([], $this->points());
    }

    public function testAFailedCallIsUrgentUntilTheNextSuccess(): void
    {
        $this->configuredProvider([['status' => 401, 'body' => '{"error":"invalid key"}'], self::answer()]);

        try {
            $this->connector->complete(new LlmRequest(LlmTier::CHEAP, 'Bonjour'));
            $this->fail('a refused key must throw');
        } catch (LlmException) {
        }

        $points = $this->points();
        $this->assertCount(1, $points);
        $this->assertSame("Le dernier appel à l'intelligence artificielle a échoué", $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertStringContainsString('clé refusée', $points[0]->why);
        $this->assertStringNotContainsString('invalid key', $points[0]->why, "the provider's words stay in the journal");

        $this->connector->complete(new LlmRequest(LlmTier::CHEAP, 'Bonjour'));
        $this->assertSame([], $this->points());
    }

    /** A configuration gap is the « not configured » point, never a failure. */
    public function testARefusalBeforeAnyProviderIsNotRecordedAsAFailure(): void
    {
        try {
            $this->connector->complete(new LlmRequest(LlmTier::CHEAP, 'Bonjour'));
        } catch (LlmException) {
        }

        $this->assertNull($this->store->lastFailure());
    }

    public function testTheCauseIsNamed(): void
    {
        $this->configuredProvider([self::answer()]);
        $this->store->recordFailure(LlmException::TIMEOUT, new \DateTimeImmutable('2026-10-18 09:30:00'));

        $why = $this->points()[0]->why;

        $this->assertStringContainsString('Le 18/10/2026 à 09:30', $why);
        $this->assertStringContainsString("n'a pas répondu à temps", $why);
    }

    /** A success on a clean site writes nothing: no settings row per request. */
    public function testASuccessOnACleanSiteWritesNothing(): void
    {
        $settings = new class ([LastFailureStore::SETTING_AT => '']) extends InMemorySettingService {
            public int $writes = 0;

            public function setInternal(string $key, string $value, ?string $moduleId = null): void
            {
                $this->writes++;
                parent::setInternal($key, $value, $moduleId);
            }

            /** @param array<string, string> $values */
            public function setManyInternal(array $values, ?string $moduleId = null): void
            {
                $this->writes++;
                parent::setManyInternal($values, $moduleId);
            }
        };

        (new LastFailureStore($settings))->clear();

        $this->assertSame(0, $settings->writes);
    }

    /** A failure is kept as one write, so the date never travels with a stale cause. */
    public function testAFailureWritesItsDateAndItsCauseTogether(): void
    {
        $settings = new class ([LastFailureStore::SETTING_AT => '', LastFailureStore::SETTING_CODE => '']) extends InMemorySettingService {
            /** @var list<list<string>> */
            public array $batches = [];

            /** @param array<string, string> $values */
            public function setManyInternal(array $values, ?string $moduleId = null): void
            {
                $this->batches[] = array_keys($values);
                parent::setManyInternal($values, $moduleId);
            }
        };

        (new LastFailureStore($settings))->recordFailure(LlmException::TIMEOUT, new \DateTimeImmutable('2026-10-18 09:30:00'));

        $this->assertSame([[LastFailureStore::SETTING_AT, LastFailureStore::SETTING_CODE]], $settings->batches);
    }

    /** Bookkeeping never turns a provider's failure into a database one. */
    public function testAFailureToRecordIsSwallowed(): void
    {
        $this->settings->refuseWrites = true;
        $this->store->recordFailure(LlmException::API_ERROR, new \DateTimeImmutable());

        $this->assertNull($this->store->lastFailure());
    }

    /** A passing test of the provider in use clears it; of another one, not. */
    public function testAPassingConnectionTestClearsOnlyForTheActiveProvider(): void
    {
        $active = $this->providers->create('Mistral', 'mistral', 'https://api.mistral.ai', 'key', true);
        $other = $this->providers->create('Scaleway', 'scaleway', 'https://api.scaleway.ai', 'key', false);
        $this->store->recordFailure(LlmException::API_ERROR, new \DateTimeImmutable('2026-10-18 09:30:00'));

        $this->store->clearAfterPassingTest($other, $this->providers);
        $this->assertNotNull($this->store->lastFailure());

        $this->store->clearAfterPassingTest($active, $this->providers);
        $this->assertNull($this->store->lastFailure());
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
