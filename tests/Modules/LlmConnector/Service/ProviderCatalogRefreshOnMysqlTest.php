<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Service;

use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Controller\ConfigController;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\ProviderModelCatalog;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Provider\ModelProbeStatus;
use Modules\LlmConnector\Provider\ProviderResponse;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\LastFailureStore;
use Modules\LlmConnector\Service\OcrModelSelector;
use Modules\LlmConnector\Service\ProviderCatalogRefresher;
use Modules\LlmConnector\Task\RefreshModelsHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\LlmConnector\StubHttpServer;
use Tests\TestTwig;
use Tests\UsesProductionEngine;

/** Both entry points must clean installed assignments, not just a selector's return value. */
#[Group('database')]
class ProviderCatalogRefreshOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const BGE = 'bge-multilingual-gemma2';
    private const TEXT = 'mistral-small-24b-instruct-2501';
    private const IMAGE = 'mistral-small-3.2-24b-instruct-2506';
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ProviderRepository $providers;
    private ProviderModelRepository $models;
    private ?StubHttpServer $server = null;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->providers = new ProviderRepository($this->pdo, $this->encryption);
        $this->models = new ProviderModelRepository($this->pdo);
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        AuthSession::logout();
    }

    public static function refreshPaths(): iterable
    {
        foreach (['connection', 'weekly'] as $path) {
            foreach (['valid', 'cheap_model_id', 'capable_model_id', 'ocr_model_id', 'failure', 'classifier_route', 'text', 'unsupported', 'unknown'] as $scenario) {
                yield "{$path} / {$scenario}" => [$path, $scenario];
            }
        }
    }

    #[DataProvider('refreshPaths')]
    public function testRefreshCleansLegacyTiersUsingTheSameVerifiedCatalog(string $path, string $scenario): void
    {
        $noChat = in_array($scenario, ['unsupported', 'unknown'], true);
        $probeVision = !$noChat && $scenario !== 'text';
        $vision = $probeVision && $scenario !== 'classifier_route';
        $ids = match ($scenario) {
            'unsupported' => [self::BGE, 'lightonocr-3-0.8b'],
            'unknown' => ['brand-new-vision-small'],
            'text' => [self::BGE, self::TEXT],
            default => [self::BGE, self::TEXT, self::IMAGE],
        };
        $selection = [
            'cheap_model_id' => $vision ? self::IMAGE : self::TEXT,
            'capable_model_id' => self::TEXT,
            'ocr_model_id' => $vision ? self::IMAGE : null,
        ];
        if (str_ends_with($scenario, '_model_id')) {
            $selection[$scenario] = self::BGE;
        }
        $this->server = StubHttpServer::start([
            ['body' => json_encode(['data' => array_map(static fn (string $id): array => ['id' => $id], $ids)], JSON_THROW_ON_ERROR)],
            ['status' => in_array($scenario, ['failure', 'classifier_route'], true) ? ($scenario === 'failure' ? 503 : 422) : 200, 'body' => in_array($scenario, ['failure', 'classifier_route'], true)
                ? json_encode(['error' => ['message' => 'Sensitive echoed payload',
                    'type' => $scenario === 'classifier_route' ? 'route_not_supported' : 'server_error']], JSON_THROW_ON_ERROR)
                : json_encode(['choices' => [['message' => ['content' => json_encode($selection, JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR)],
        ]);
        $driver = $this->getMockBuilder(ScalewayProvider::class)
            ->setConstructorArgs([$this->server->baseUrl(), 'test-key'])->onlyMethods(['probeVision', 'probeScope'])->getMock();
        $driver->method('probeScope')->willReturn(ScalewayProvider::capabilityScope('https://93.184.216.34', 'test-key'));
        $driver->expects($noChat ? $this->never() : $this->once())->method('probeVision')
            ->willReturn($probeVision ? ModelProbeStatus::VISION_VERIFIED : ModelProbeStatus::UNVERIFIED);
        // A public IP literal satisfies the controller's SSRF check without DNS.
        // Only createDriver is substituted: the real driver talks to the local stub.
        $id = $this->providers->create('Scaleway', 'scaleway', 'https://93.184.216.34', 'test-key', true);
        $this->seedLegacyAssignments($id);
        $other = $this->providers->create('Other', 'anthropic', 'https://api.anthropic.com', 'test-key', false);
        $this->seedLegacyAssignments($other);
        $beforeOther = $this->models->findByProvider($other);
        $journal = $this->createStub(JournalService::class);
        $entries = [];
        $journal->method('log')->willReturnCallback(static function (...$arguments) use (&$entries): void {
            $entries[] = $arguments;
        });
        $response = $path === 'connection' ? $this->runConnection($id, $driver, $journal, !$noChat) : null;
        if ($path === 'weekly') {
            $handler = new class extends RefreshModelsHandler {
                public LlmProviderInterface $testDriver;
                protected function createDriver(string $driver, string $apiEndpoint, string $apiKey): LlmProviderInterface
                {
                    return $this->testDriver;
                }
            };
            $handler->testDriver = $driver;
            $handler->handle([], $this->context($journal));
        }

        $expectedTiers = $noChat ? [null, null, null]
            : [$vision ? self::IMAGE : self::TEXT, self::TEXT, $vision ? self::IMAGE : null];
        $actualTiers = array_map(
            fn (LlmTier $tier): ?string => $this->models->findByProviderAndTier($id, $tier)['model_id'] ?? null,
            [LlmTier::CHEAP, LlmTier::CAPABLE, LlmTier::OCR]
        );
        $actualIds = array_column($this->models->findByProvider($id), 'model_id');
        sort($actualIds);
        $expectedIds = $noChat ? [] : ($vision ? [self::TEXT, self::IMAGE] : [self::TEXT]);
        sort($expectedIds);
        $cache = new \Modules\LlmConnector\Provider\ModelProbeCache(
            $this->models->modelProbeCache($id), $driver->probeScope()
        );
        $persisted = [];
        foreach ($ids as $modelId) {
            $status = $cache->resultFor($modelId);
            if ($status !== null) {
                $persisted[$modelId] = $status->value;
            }
        }
        $expectedEvidence = $noChat ? [] : [($probeVision ? self::IMAGE : self::TEXT)
            => $scenario === 'classifier_route' ? 'chat_unsupported' : ($vision ? 'vision_verified' : 'unverified')];
        self::assertSame([$expectedIds, $expectedTiers, $beforeOther, $expectedEvidence, []], [
            $actualIds, $actualTiers, $this->models->findByProvider($other), $persisted, $this->models->modelProbeCache($other),
        ]);

        $requests = $this->server->requests();
        self::assertSame(
            $noChat ? [['GET', '/v1/models', null]] : [['GET', '/v1/models', null], ['POST', '/v1/chat/completions', $probeVision ? self::IMAGE : self::TEXT]],
            array_map(static fn (array $request): array => [$request['method'], $request['uri'], json_decode($request['body'], true)['model'] ?? null], $requests)
        );
        $checks = array_values(array_filter($entries, static fn (array $entry): bool => $entry[1] === 'model_capabilities_checked'));
        $exclusions = $scenario === 'unknown' ? ['brand-new-vision-small' => 'chat_capability_unverified']
            : [self::BGE => 'chat_endpoint_unsupported'];
        if ($scenario === 'classifier_route') {
            $exclusions[self::IMAGE] = 'chat_endpoint_unsupported';
        }
        if ($scenario === 'unsupported') {
            $exclusions['lightonocr-3-0.8b'] = 'chat_endpoint_unsupported';
        }
        self::assertSame([[
            'provider_id' => $id, 'chat_model_count' => count($expectedIds), 'vision_model_count' => $vision ? 1 : 0,
            'excluded_models' => $exclusions, 'image_probe_count' => $noChat ? 0 : 1,
            'image_probe_results' => $noChat ? [] : [($probeVision ? self::IMAGE : self::TEXT)
                => $scenario === 'classifier_route' ? 'chat_unsupported' : ($vision ? 'vision_verified' : 'unverified')],
        ]], array_column($checks, 4));
        self::assertStringNotContainsString('Sensitive echoed payload', json_encode($entries, JSON_THROW_ON_ERROR));

        if ($response !== null) {
            self::assertSame(!$noChat, $response['success']);
            if ($noChat) {
                self::assertStringContainsString('aucun modèle de conversation compatible', $response['error']);
            } else {
                self::assertSame([$vision ? self::IMAGE : null, false], [$response['ocr_model'], $response['ocr_fallback']]);
                if (!$vision) {
                    self::assertStringContainsString('documents scannés est indisponible', $response['message']);
                }
            }
        }
    }

    private function seedLegacyAssignments(int $id): void
    {
        $this->models->upsert($id, self::BGE, 'BGE');
        $this->models->upsert($id, 'old-removed-model', 'Old');
        $this->models->autoAssignTiers($id, ['cheap' => self::BGE, 'capable' => self::BGE, 'ocr' => self::BGE]);
    }

    private function runConnection(int $id, LlmProviderInterface $driver, JournalService $journal, bool $usable): array
    {
        $failure = $this->createMock(LastFailureStore::class);
        $failure->expects($usable ? $this->once() : $this->never())->method('clearAfterPassingTest');
        $controller = new class(
            TestTwig::create(['llm_connector']), $this->providers, $this->models, new OcrModelSelector(),
            new SchedulerService(new SchedulerRepository($this->pdo)), $journal, $failure
        ) extends ConfigController {
            public LlmProviderInterface $testDriver;
            protected function createDriver(string $driver, string $apiEndpoint, string $apiKey): LlmProviderInterface
            {
                return $this->testDriver;
            }
        };
        $controller->testDriver = $driver;
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        $request = $this->createConfiguredStub(Request::class, [
            'getRawBody' => json_encode(['_csrf_token' => $_SESSION['_csrf_token']], JSON_THROW_ON_ERROR),
        ]);
        return json_decode($controller->testConnection($request, ['id' => (string) $id])->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function context(JournalService $journal): TaskContext
    {
        return new TaskContext(Connection::withPdo($this->pdo), $this->encryption, $this->createStub(MailService::class),
            $journal, $this->createStub(SettingService::class), $this->createStub(UserAccountRepository::class), sys_get_temp_dir());
    }

    public function testAWriteFailureRollsBackTheCatalogAndAllTierChanges(): void
    {
        $id = $this->providers->create('Scaleway', 'scaleway', 'https://api.scaleway.ai', 'test-key', true);
        $this->seedLegacyAssignments($id);
        $before = $this->models->findByProvider($id);
        $failing = new class($this->pdo) extends ProviderModelRepository {
            public function autoAssignTiers(int $providerId, array $tierMap): void
            {
                parent::autoAssignTiers($providerId, $tierMap);
                throw new \RuntimeException('Write failure after tier changes');
            }
        };
        $driver = $this->getMockBuilder(ScalewayProvider::class)->setConstructorArgs(['https://provider.example', 'test-key'])
            ->onlyMethods(['listModels', 'probeVision', 'complete'])->getMock();
        $driver->expects($this->once())->method('probeVision')->willReturn(ModelProbeStatus::UNVERIFIED);
        $driver->method('listModels')->willReturn(new ProviderModelCatalog([
            ['id' => self::TEXT, 'display_name' => 'Text', 'chat' => true, 'vision' => false],
        ]));
        $driver->expects($this->once())->method('complete')->willThrowException(new \RuntimeException('No classification'));
        $message = null;
        try {
            (new ProviderCatalogRefresher($failing, new OcrModelSelector(), $this->createStub(JournalService::class)))->refresh($id, $driver, $this->providers->findById($id)['configuration_token']);
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        }
        self::assertSame(['Write failure after tier changes', $before, [], false], [
            $message, $this->models->findByProvider($id), $this->models->modelProbeCache($id), $this->pdo->inTransaction(),
        ]);
    }

    public function testTheRepositoryLeavesAnExistingTransactionUnderItsCallersControl(): void
    {
        $id = $this->providers->create('Scaleway', 'scaleway', 'https://api.scaleway.ai', 'test-key', true);
        $this->pdo->beginTransaction();
        try {
            $this->models->transactional(fn () => $this->models->upsert($id, self::TEXT, 'Text'));
            self::assertTrue($this->pdo->inTransaction());
            self::assertSame([self::TEXT], array_column($this->models->findByProvider($id), 'model_id'));
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame([], $this->models->findByProvider($id));
    }

    public function testAFailedCacheWriteRollsBackItsEvidenceWithoutLeavingATransaction(): void
    {
        $id = $this->providers->create('Scaleway', 'scaleway', 'https://provider.example', 'test-key', true);
        $this->seedLegacyAssignments($id);
        $before = $this->models->findByProvider($id);
        $failing = new class($this->pdo) extends ProviderModelRepository {
            public function storeModelProbeCache(int $providerId, array $cache): array
            {
                parent::storeModelProbeCache($providerId, $cache);
                throw new \RuntimeException('Failure after storing evidence');
            }
        };
        $driver = $this->getMockBuilder(ScalewayProvider::class)->setConstructorArgs(['https://provider.example', 'test-key'])
            ->onlyMethods(['listModels', 'probeVision', 'complete'])->getMock();
        $driver->method('listModels')->willReturn(new ProviderModelCatalog([
            ['id' => self::TEXT, 'display_name' => 'Text', 'chat' => true, 'vision' => null],
        ]));
        $driver->expects($this->once())->method('probeVision')->willReturn(ModelProbeStatus::VISION_VERIFIED);
        $driver->expects($this->once())->method('complete')->willReturn(new ProviderResponse(json_encode([
            'cheap_model_id' => self::TEXT, 'capable_model_id' => self::TEXT, 'ocr_model_id' => self::TEXT,
        ], JSON_THROW_ON_ERROR), 1, 1));
        $message = null;
        try {
            (new ProviderCatalogRefresher($failing, new OcrModelSelector(), $this->createStub(JournalService::class)))
                ->refresh($id, $driver, $this->providers->findById($id)['configuration_token']);
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        }
        self::assertSame(['Failure after storing evidence', [], false], [
            $message, $this->models->modelProbeCache($id), $this->pdo->inTransaction(),
        ]);
    }

    public function testARefusalRecordedDuringClassificationWinsOverTheRefreshSnapshot(): void
    {
        $id = $this->providers->create('Scaleway', 'scaleway', 'https://provider.example', 'test-key', true);
        $this->seedLegacyAssignments($id);
        $interleaved = new class($this->pdo) extends ProviderModelRepository {
            public function storeModelProbeCache(int $providerId, array $cache): array
            {
                $negative = new \Modules\LlmConnector\Provider\ModelProbeCache([], $cache['scope']);
                $negative->record('mistral-small-24b-instruct-2501', ModelProbeStatus::CHAT_UNSUPPORTED);
                parent::storeModelProbeCache($providerId, $negative->toArray());
                return parent::storeModelProbeCache($providerId, $cache);
            }
        };
        $driver = $this->getMockBuilder(ScalewayProvider::class)->setConstructorArgs(['https://provider.example', 'test-key'])
            ->onlyMethods(['listModels', 'probeVision', 'complete'])->getMock();
        $driver->method('listModels')->willReturn(new ProviderModelCatalog([
            ['id' => self::TEXT, 'display_name' => 'Text', 'chat' => true, 'vision' => null],
        ]));
        $driver->expects($this->once())->method('probeVision')->willReturn(ModelProbeStatus::VISION_VERIFIED);
        $driver->expects($this->once())->method('complete')->willReturn(new ProviderResponse(json_encode([
            'cheap_model_id' => self::TEXT, 'capable_model_id' => self::TEXT, 'ocr_model_id' => self::TEXT,
        ], JSON_THROW_ON_ERROR), 1, 1));
        $catalog = (new ProviderCatalogRefresher($interleaved, new OcrModelSelector(), $this->createStub(JournalService::class)))
            ->refresh($id, $driver, $this->providers->findById($id)['configuration_token']);
        $cache = new \Modules\LlmConnector\Provider\ModelProbeCache($this->models->modelProbeCache($id), $driver->probeScope());
        self::assertSame([[], [], ModelProbeStatus::CHAT_UNSUPPORTED], [
            $catalog->chatModelIds(), $this->models->findByProvider($id), $cache->resultFor(self::TEXT),
        ]);
    }

    public function testCacheMergingPreservesRefusalsAndSeparateCredentialContextsOnTheRealEngine(): void
    {
        $id = $this->providers->create('Scaleway', 'scaleway', 'https://provider.example', 'test-key', true);
        $negative = new \Modules\LlmConnector\Provider\ModelProbeCache([], 'scope-A', 1800000010);
        $negative->record(self::TEXT, ModelProbeStatus::CHAT_UNSUPPORTED);
        $this->models->storeModelProbeCache($id, $negative->toArray());
        $other = new \Modules\LlmConnector\Provider\ModelProbeCache([], 'scope-B', 1800000020);
        $other->record(self::TEXT, ModelProbeStatus::VISION_VERIFIED);
        $this->models->storeModelProbeCache($id, $other->toArray());
        $stale = new \Modules\LlmConnector\Provider\ModelProbeCache([], 'scope-A', 1800000000);
        $stale->record(self::TEXT, ModelProbeStatus::VISION_VERIFIED);
        $this->models->storeModelProbeCache($id, $stale->toArray());
        $stored = $this->models->modelProbeCache($id);
        self::assertSame([ModelProbeStatus::CHAT_UNSUPPORTED, ModelProbeStatus::VISION_VERIFIED], [
            (new \Modules\LlmConnector\Provider\ModelProbeCache($stored, 'scope-A', 1800000030))->resultFor(self::TEXT),
            (new \Modules\LlmConnector\Provider\ModelProbeCache($stored, 'scope-B', 1800000030))->resultFor(self::TEXT),
        ]);
    }

    public function testWeeklyHandlerRenewsAStillFreshImageProof(): void
    {
        $id = $this->providers->create('Scaleway', 'scaleway', 'https://provider.example', 'test-key', true);
        $driver = $this->getMockBuilder(ScalewayProvider::class)->setConstructorArgs(['https://provider.example', 'test-key'])
            ->onlyMethods(['listModels', 'probeVision', 'complete'])->getMock();
        $cache = new \Modules\LlmConnector\Provider\ModelProbeCache([], $driver->probeScope());
        $cache->record(self::IMAGE, ModelProbeStatus::VISION_VERIFIED);
        $this->models->storeModelProbeCache($id, $cache->toArray());
        $driver->method('listModels')->willReturn(new ProviderModelCatalog([
            ['id' => self::IMAGE, 'display_name' => 'Images', 'chat' => true, 'vision' => null],
        ]));
        $driver->expects($this->once())->method('probeVision')->willReturn(ModelProbeStatus::VISION_VERIFIED);
        $driver->expects($this->once())->method('complete')->willReturn(new ProviderResponse(json_encode([
            'cheap_model_id' => self::IMAGE, 'capable_model_id' => self::IMAGE, 'ocr_model_id' => self::IMAGE,
        ], JSON_THROW_ON_ERROR), 1, 1));
        $handler = new class extends RefreshModelsHandler {
            public LlmProviderInterface $testDriver;
            protected function createDriver(string $driver, string $apiEndpoint, string $apiKey): LlmProviderInterface
            {
                return $this->testDriver;
            }
        };
        $handler->testDriver = $driver;
        $handler->handle([], $this->context($this->createStub(JournalService::class)));
        self::assertSame(self::IMAGE, $this->models->findByProviderAndTier($id, LlmTier::OCR)['model_id']);
    }

    public static function configurationChanges(): iterable
    {
        foreach (['endpoint', 'key', 'driver', 'active', 'deleted'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('configurationChanges')]
    public function testAConfigurationChangeDuringDiscoveryCannotOverwriteInstalledModels(string $field): void
    {
        $id = $this->providers->create('Scaleway', 'scaleway', 'https://provider.example', 'test-key', true);
        $token = $this->providers->findById($id)['configuration_token'];
        $this->seedLegacyAssignments($id);
        $before = $this->models->findByProvider($id);
        $driver = $this->getMockBuilder(ScalewayProvider::class)
            ->setConstructorArgs(['https://provider.example', 'test-key'])
            ->onlyMethods(['listModels', 'probeVision', 'complete'])->getMock();
        $driver->expects($this->once())->method('listModels')->willReturnCallback(function () use ($id, $field): ProviderModelCatalog {
            if ($field === 'deleted') {
                $this->providers->delete($id);
            } else {
                $this->providers->update(
                    $id, 'Changed', $field === 'driver' ? 'mistral' : 'scaleway',
                    $field === 'endpoint' ? 'https://changed.example' : 'https://provider.example',
                    $field === 'key' ? 'new-key' : null, $field !== 'active'
                );
            }
            return new ProviderModelCatalog([
                ['id' => self::TEXT, 'display_name' => 'Text', 'chat' => true, 'vision' => null],
            ]);
        });
        $driver->method('probeVision')->willReturn(ModelProbeStatus::UNVERIFIED);
        $driver->method('complete')->willThrowException(new \RuntimeException('Use fallback'));
        $error = null;
        try {
            (new ProviderCatalogRefresher($this->models, new OcrModelSelector(), $this->createStub(JournalService::class)))
                ->refresh($id, $driver, $token);
        } catch (\RuntimeException $exception) {
            $error = $exception->getMessage();
        }
        self::assertSame(['Provider configuration changed during model discovery.', $field === 'deleted' ? [] : $before, [], false], [
            $error, $this->models->findByProvider($id), $this->models->modelProbeCache($id), $this->pdo->inTransaction(),
        ]);
    }

    public function testAConcurrentCacheWriteSurvivesAStoreThatMergesFromAnOlderSnapshot(): void
    {
        $id = $this->providers->create('Scaleway', 'scaleway', 'https://provider.example', 'test-key', true);
        $other = self::productionEngineConnection((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn())->getPdo();
        $positive = new \Modules\LlmConnector\Provider\ModelProbeCache([], 'scope-B', 1800000020);
        $positive->record(self::TEXT, ModelProbeStatus::VISION_VERIFIED);
        $negative = new \Modules\LlmConnector\Provider\ModelProbeCache([], 'scope-A', 1800000010);
        $negative->record(self::TEXT, ModelProbeStatus::CHAT_UNSUPPORTED);
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            $snapshot = $this->pdo->prepare('SELECT id FROM llm_providers WHERE id = ?');
            $snapshot->execute([$id]);
            $snapshot->fetch();
            $update = $other->prepare('UPDATE llm_providers SET model_probe_cache = ? WHERE id = ?');
            $update->execute([json_encode($positive->toArray(), JSON_THROW_ON_ERROR), $id]);
            $this->models->storeModelProbeCache($id, $negative->toArray());
            $stored = $this->models->modelProbeCache($id);
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame([ModelProbeStatus::CHAT_UNSUPPORTED, ModelProbeStatus::VISION_VERIFIED], [
            (new \Modules\LlmConnector\Provider\ModelProbeCache($stored, 'scope-A', 1800000030))->resultFor(self::TEXT),
            (new \Modules\LlmConnector\Provider\ModelProbeCache($stored, 'scope-B', 1800000030))->resultFor(self::TEXT),
        ]);
    }

    public static function mistralRevocations(): iterable
    {
        yield 'explicit text only' => [false];
        yield 'missing capability' => [null];
    }

    #[DataProvider('mistralRevocations')]
    public function testMistralVisionEvidenceSurvivesReloadButIsRevokedByChangedCapabilities(?bool $vision): void
    {
        $id = $this->providers->create('Mistral', 'mistral', 'https://provider.example', 'test-key', true);
        $token = $this->providers->findById($id)['configuration_token'];
        $model = 'mistral-small-latest';
        $this->models->upsert($id, $model, 'Mistral');
        $this->models->autoAssignTiers($id, ['cheap' => $model, 'capable' => $model, 'ocr' => $model]);
        $scope = \Modules\LlmConnector\Provider\ModelProbeCache::scopeFor('mistral', 'https://provider.example', 'test-key');
        $resolver = new \Modules\LlmConnector\Service\TierModelResolver($this->models);
        $before = $resolver->resolve($id, 'mistral', LlmTier::OCR, $scope);
        $driver = $this->getMockBuilder(\Modules\LlmConnector\Provider\MistralProvider::class)
            ->setConstructorArgs(['https://provider.example', 'test-key'])->onlyMethods(['listModels', 'complete'])->getMock();
        $driver->expects($this->exactly(2))->method('listModels')->willReturnOnConsecutiveCalls(
            new ProviderModelCatalog([['id' => $model, 'display_name' => 'Mistral', 'chat' => true, 'vision' => true]]),
            new ProviderModelCatalog([['id' => $model, 'display_name' => 'Mistral', 'chat' => true, 'vision' => $vision]])
        );
        $driver->method('complete')->willThrowException(new \RuntimeException('Use fallback'));
        $refresher = new ProviderCatalogRefresher($this->models, new OcrModelSelector(), $this->createStub(JournalService::class));
        $refresher->refresh($id, $driver, $token);
        $verified = $resolver->resolve($id, 'mistral', LlmTier::OCR, $scope)['model_id'] ?? null;
        $wrongScope = \Modules\LlmConnector\Provider\ModelProbeCache::scopeFor('mistral', 'https://provider.example', 'new-key');
        $wrongKey = $resolver->resolve($id, 'mistral', LlmTier::OCR, $wrongScope);
        $refresher->refresh($id, $driver, $token);
        self::assertSame([null, $model, null, null, ModelProbeStatus::VISION_NOT_VERIFIED], [
            $before, $verified, $wrongKey, $resolver->resolve($id, 'mistral', LlmTier::OCR, $scope),
            (new \Modules\LlmConnector\Provider\ModelProbeCache($this->models->modelProbeCache($id), $scope))->resultFor($model),
        ]);
    }

    public function testWeeklyRenewalIsAnchoredBeforeClassificationAndAdvancesAnOverdueMargin(): void
    {
        $id = $this->providers->create('Scaleway', 'scaleway', 'https://provider.example', 'test-key', true);
        $driver = $this->getMockBuilder(ScalewayProvider::class)
            ->setConstructorArgs(['https://provider.example', 'test-key'])
            ->onlyMethods(['listModels', 'probeVision', 'complete'])->getMock();
        $driver->method('listModels')->willReturn(new ProviderModelCatalog([
            ['id' => self::IMAGE, 'display_name' => 'Images', 'chat' => true, 'vision' => null],
        ]));
        $driver->expects($this->once())->method('probeVision')->willReturn(ModelProbeStatus::VISION_VERIFIED);
        $driver->method('complete')->willReturnCallback(static function (): ProviderResponse {
            sleep(2); // Distinguish verification start from refresh completion.
            return new ProviderResponse('{"cheap_model_id":"mistral-small-3.2-24b-instruct-2506","capable_model_id":"mistral-small-3.2-24b-instruct-2506","ocr_model_id":"mistral-small-3.2-24b-instruct-2506"}', 1, 1);
        });
        $scheduler = new SchedulerService(new SchedulerRepository($this->pdo));
        $scheduler->rearm('llm_connector', 'refresh_models', 'weekly', new \DateTimeImmutable('+7 days'));
        $handler = new class extends RefreshModelsHandler {
            public LlmProviderInterface $testDriver;
            protected function createDriver(string $driver, string $apiEndpoint, string $apiKey): LlmProviderInterface
            {
                return $this->testDriver;
            }
        };
        $handler->testDriver = $driver;
        $handler->handle([], $this->context($this->createStub(JournalService::class)));
        $checkedAt = $this->models->modelProbeCache($id)['results'][self::IMAGE]['checked_at'];
        $next = strtotime($scheduler->find('llm_connector', 'refresh_models', 'weekly')['run_at']);
        self::assertSame([true, true], [
            $next <= $checkedAt + 7 * 86400 - 3600,
            $next > $checkedAt + 6 * 86400,
        ]);
    }
}
