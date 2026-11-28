<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Task;

use Core\Database\Connection;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Task\RefreshModelsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RefreshModelsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ProviderRepository $providerRepo;
    private ProviderModelRepository $modelRepo;
    private JournalService $journalService;

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
    }

    public function testHandleLogsFailureForUnreachableProvider(): void
    {
        // Create a provider with a closed port — connection refused is immediate
        $this->providerRepo->create(
            'Unreachable',
            'anthropic',
            'http://127.0.0.1:19',
            'sk-test',
            true
        );

        $this->journalService->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'models_refresh_failed',
                'warning',
                $this->stringContains('Unreachable'),
                $this->isArray(),
                null
            );

        $context = $this->createTaskContext();
        // The address check passes here, so what fails is the connection
        // itself: with the real check, plain http to 127.0.0.1 is refused
        // before any connection is tried, which is a configuration failure
        // and `error` (issue #1450).
        $handler = new RefreshModelsHandler(static fn (string $url): bool => true);
        $handler->handle([], $context);

        // Verify no models were inserted
        $models = $this->modelRepo->findByProvider(1);
        $this->assertSame([], $models);
        // Unreachable is `warning` above: the retry queued here may get
        // through without anybody stepping in.
        $this->assertNotNull(
            \Core\Scheduler\SchedulerService::forPdo($this->pdo)->find('llm_connector', 'refresh_models', 'retry-1')
        );
    }

    public function testHandleSkipsInactiveProviders(): void
    {
        // Create inactive provider
        $this->providerRepo->create(
            'Inactive',
            'anthropic',
            'http://127.0.0.1:19',
            'sk-test',
            false
        );

        // Journal should NOT be called since no active providers
        $this->journalService->expects($this->never())->method('log');

        $context = $this->createTaskContext();
        $handler = new RefreshModelsHandler();
        $handler->handle([], $context);
    }

    public function testHandleThrowsForUnknownDriver(): void
    {
        $this->providerRepo->create(
            'UnknownDriver',
            'openai',
            'https://api.openai.com',
            'sk-test',
            true
        );

        // The handler catches all throwables and logs them
        $this->journalService->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'models_refresh_failed',
                // A driver this build does not know is the configuration:
                // no retry fixes it (#1559 review).
                'error',
                $this->stringContains('UnknownDriver'),
                $this->callback(function (array $ctx): bool {
                    return $ctx['error_type'] === \RuntimeException::class && $ctx['error_code'] === 0;
                }),
                null
            );

        $context = $this->createTaskContext();
        $handler = new RefreshModelsHandler();
        $handler->handle([], $context);

        // The level changed, not the recovery: the retry is still queued.
        $this->assertNotNull(
            \Core\Scheduler\SchedulerService::forPdo($this->pdo)->find('llm_connector', 'refresh_models', 'retry-1')
        );
    }

    /**
     * **The scheduled refresh checks the stored address again before it
     * calls** (#1269, SECURITY.md §17). Nobody watches this run, and the
     * API key travels on it: an address that now leads inside the hosting
     * network is refused before any driver exists, and the refusal is
     * journalled like any other failed refresh.
     */
    public function testAStoredAddressThatLeadsToAPrivateHostIsRefusedBeforeAnyDriverIsBuilt(): void
    {
        $this->providerRepo->create('Rebound', 'anthropic', 'https://10.0.0.5', 'sk-test', true);

        $this->journalService->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'models_refresh_failed',
                // The stored address is for the superadmin to correct.
                'error',
                $this->stringContains('Rebound'),
                $this->callback(static fn (array $context): bool
                    => $context['error_type'] === \Modules\LlmConnector\Api\LlmException::class),
                null
            );

        $handler = new class extends RefreshModelsHandler {
            public int $driversBuilt = 0;

            protected function createDriver(
                string $driver,
                string $apiEndpoint,
                string $apiKey
            ): \Modules\LlmConnector\Provider\LlmProviderInterface {
                $this->driversBuilt++;

                return parent::createDriver($driver, $apiEndpoint, $apiKey);
            }
        };
        $handler->handle([], $this->createTaskContext());

        $this->assertSame(0, $handler->driversBuilt);
        $this->assertSame([], $this->modelRepo->findByProvider(1));
    }

    /** The check is the one injected, and a passing one lets the driver be built. */
    public function testAnAddressTheCheckAcceptsGoesOnToTheDriver(): void
    {
        $this->providerRepo->create('Accepted', 'anthropic', 'https://10.0.0.5', 'sk-test', true);

        // What fails is the driver this time, not the address check.
        $this->journalService->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'models_refresh_failed',
                // Building a driver contacts nothing: what fails there is
                // the provider's configuration, hence `error`.
                'error',
                $this->stringContains('Accepted'),
                $this->callback(static fn (array $context): bool => $context['error_type'] === \RuntimeException::class),
                null
            );

        $handler = new class (static fn (string $url): bool => true) extends RefreshModelsHandler {
            public int $driversBuilt = 0;

            protected function createDriver(
                string $driver,
                string $apiEndpoint,
                string $apiKey
            ): \Modules\LlmConnector\Provider\LlmProviderInterface {
                $this->driversBuilt++;

                throw new \RuntimeException('No network in this test.');
            }
        };
        $handler->handle([], $this->createTaskContext());

        $this->assertSame(1, $handler->driversBuilt);
    }

    private function createTaskContext(): TaskContext
    {
        $connection = Connection::withPdo($this->pdo);
        $mailService = $this->createStub(MailService::class);
        $settingService = $this->createStub(SettingService::class);
        $userAccountRepo = $this->createStub(UserAccountRepository::class);

        return new TaskContext(
            $connection,
            $this->encryption,
            $mailService,
            $this->journalService,
            $settingService,
            $userAccountRepo,
            sys_get_temp_dir()
        );
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
