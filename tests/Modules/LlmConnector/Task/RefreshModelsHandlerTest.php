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
                'info',
                $this->stringContains('Unreachable'),
                $this->isType('array'),
                null
            );

        $context = $this->createTaskContext();
        $handler = new RefreshModelsHandler();
        $handler->handle([], $context);

        // Verify no models were inserted
        $models = $this->modelRepo->findByProvider(1);
        $this->assertSame([], $models);
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
                'info',
                $this->stringContains('UnknownDriver'),
                $this->callback(function (array $ctx): bool {
                    return isset($ctx['error']) && str_contains($ctx['error'], 'Unknown driver');
                }),
                null
            );

        $context = $this->createTaskContext();
        $handler = new RefreshModelsHandler();
        $handler->handle([], $context);
    }

    private function createTaskContext(): TaskContext
    {
        $connection = Connection::withPdo($this->pdo);
        $mailService = $this->createMock(MailService::class);
        $settingService = $this->createMock(SettingService::class);
        $userAccountRepo = $this->createMock(UserAccountRepository::class);

        return new TaskContext(
            $connection,
            $this->encryption,
            $mailService,
            $this->journalService,
            $settingService,
            $userAccountRepo
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
