<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class SchedulerRunnerTest extends TestCase
{
    private SchedulerRunner $runner;
    private SchedulerRepository $repo;
    private JournalService $journal;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new SchedulerRepository($this->pdo);
        $journalRepo = new JournalRepository($this->pdo);
        $this->journal = new JournalService($journalRepo);
        $this->runner = new SchedulerRunner($this->repo, $this->journal);

        // Create a mock TaskContext for handler calls
        $connection = $this->createMock(Connection::class);
        $encryption = $this->createMock(EncryptionService::class);
        $mailService = $this->createMock(MailService::class);
        $settingRepo = new SettingRepository($this->pdo);
        $settingService = new SettingService($settingRepo);
        $userAccounts = $this->createMock(UserAccountRepository::class);
        $taskContext = new TaskContext($connection, $encryption, $mailService, $this->journal, $settingService, $userAccounts, sys_get_temp_dir());
        $this->runner->setTaskContext($taskContext);
    }

    public function testProcessOverdueWithRegisteredHandler(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public bool $called = false;
            /** @var array<string, mixed> */
            public array $payload = [];

            public function handle(array $payload, TaskContext $context): void
            {
                $this->called = true;
                $this->payload = $payload;
            }
        };

        $this->runner->registerHandler('core', 'test_task', $handler);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'test_task', $pastTime, json_encode(['key' => 'value']), null);

        $processed = $this->runner->processOverdue();

        $this->assertSame(1, $processed);
        $this->assertTrue($handler->called);
        $this->assertSame(['key' => 'value', 'requested_by_user_account_id' => null], $handler->payload);
    }

    public function testProcessOverduePropagatesRequestedByUserAccountIdIntoPayload(): void
    {
        $handler = new class implements TaskHandlerInterface {
            /** @var array<string, mixed> */
            public array $payload = [];

            public function handle(array $payload, TaskContext $context): void
            {
                $this->payload = $payload;
            }
        };

        $this->runner->registerHandler('core', 'requester_task', $handler);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'requester_task', $pastTime, json_encode(['key' => 'value']), null, 42);

        $this->runner->processOverdue();

        $this->assertSame(42, $handler->payload['requested_by_user_account_id']);
    }

    public function testProcessOverdueMarksTaskDone(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public function handle(array $payload, TaskContext $context): void
            {
                // success
            }
        };

        $this->runner->registerHandler('core', 'ok_task', $handler);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'ok_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $action = $this->repo->findById($id);
        $this->assertSame('done', $action['status']);
        $this->assertNotNull($action['executed_at']);
    }

    public function testProcessOverdueMarksTaskFailedOnException(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public function handle(array $payload, TaskContext $context): void
            {
                throw new \RuntimeException('Handler error');
            }
        };

        $this->runner->registerHandler('core', 'bad_task', $handler);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'bad_task', $pastTime, null, null);

        $processed = $this->runner->processOverdue();

        $this->assertSame(0, $processed); // failed tasks don't count as processed
        $action = $this->repo->findById($id);
        $this->assertSame('failed', $action['status']);
        // last_error is rendered inside a <pre> on Configuration > Actions
        // planifiées, so a raw \Throwable message must not reach it — see
        // Core\Exception\UserFacingMessage.
        $this->assertStringNotContainsString('Handler error', $action['last_error']);
        $this->assertStringContainsString('bad_task', $action['last_error']);
    }

    /**
     * The shape the write-site gate exists to stop: a handler that lets a
     * library's own words escape. scheduled_actions.last_error is rendered
     * inside a <pre> on Configuration > Actions planifiées, so a SQL error
     * naming a table, or an SMTP transcript, would land on a config page
     * long after the request that produced it.
     */
    public function testProcessOverdueNeverStoresALibraryMessageAsTheDisplayedError(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public function handle(array $payload, TaskContext $context): void
            {
                throw new \PDOException(
                    "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'foo' in 'member_years'"
                );
            }
        };

        $this->runner->registerHandler('core', 'leaky_task', $handler);
        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'leaky_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $lastError = (string) $this->repo->findById($id)['last_error'];
        $this->assertStringNotContainsString('SQLSTATE', $lastError);
        $this->assertStringNotContainsString('member_years', $lastError);
        $this->assertStringContainsString('leaky_task', $lastError);
        $this->assertStringContainsString('journal des événements', $lastError);

        // Not lost, just moved: the journal keeps the real text.
        $entry = $this->pdo->query(
            "SELECT description FROM event_log WHERE event_type = 'scheduler_task_failed' ORDER BY id DESC LIMIT 1"
        )->fetch();
        $this->assertStringContainsString('SQLSTATE', (string) $entry['description']);
    }

    /**
     * The other half of the rule: a handler that throws an exception
     * written FOR the admin (marked Core\Exception\UserFacingException)
     * still gets its own sentence on the page — the gate substitutes, it
     * does not blanket-replace.
     */
    public function testProcessOverdueKeepsAMessageWrittenForTheAdmin(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public function handle(array $payload, TaskContext $context): void
            {
                throw new \Core\File\UploadException(
                    'Le fichier dépasse la taille maximale autorisée (20 Mo).'
                );
            }
        };

        $this->runner->registerHandler('core', 'speaking_task', $handler);
        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('core', 'speaking_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $this->assertSame(
            'Le fichier dépasse la taille maximale autorisée (20 Mo).',
            $this->repo->findById($id)['last_error']
        );
    }

    public function testProcessOverdueFailsWithNoHandler(): void
    {
        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('unknown', 'missing_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $action = $this->repo->findById($id);
        $this->assertSame('failed', $action['status']);
        $this->assertStringContainsString('No handler registered', $action['last_error']);
    }

    public function testProcessOverdueDoesNotProcessFutureTasks(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public bool $called = false;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->called = true;
            }
        };

        $this->runner->registerHandler('core', 'future_task', $handler);

        $futureTime = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'future_task', $futureTime, null, null);

        $processed = $this->runner->processOverdue();

        $this->assertSame(0, $processed);
        $this->assertFalse($handler->called);
    }

    public function testHandlerReceivesUserAccountsRepositoryViaContext(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public ?UserAccountRepository $seenUserAccounts = null;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->seenUserAccounts = $context->userAccounts;
            }
        };

        $this->runner->registerHandler('core', 'admin_alert_task', $handler);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'admin_alert_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $this->assertInstanceOf(UserAccountRepository::class, $handler->seenUserAccounts);
    }

    // ── Auto-resolution through the ModuleManager ───────────────────────
    //
    // The other way a handler reaches the runner: no registerHandler()
    // call, the class name comes from the enabled module's manifest via
    // ModuleManager::getTaskHandler() and is instantiated with NO
    // arguments (`new $handlerClass()`). That no-argument construction is
    // the root cause behind the §7.5 leaks in task handlers (chantier
    // « dépendances entre modules », C2) — these tests freeze the
    // resolution contract before IT-03 reworks the bootstrap around it.

    public function testProcessOverdueResolvesAHandlerClassThroughTheModuleManager(): void
    {
        $moduleManager = $this->createMock(\Core\Module\ModuleManager::class);
        $moduleManager->expects($this->once())->method('getTaskHandler')
            ->with('some_module', 'auto_task')
            ->willReturn(ModuleResolvedFixtureHandler::class);
        $this->runner->setModuleManager($moduleManager);

        ModuleResolvedFixtureHandler::$payloads = [];
        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('some_module', 'auto_task', $pastTime, json_encode(['a' => 1]), null);

        $processed = $this->runner->processOverdue();

        $this->assertSame(1, $processed);
        $this->assertSame('done', $this->repo->findById($id)['status']);
        $this->assertCount(1, ModuleResolvedFixtureHandler::$payloads);
        $this->assertSame(
            ['a' => 1, 'requested_by_user_account_id' => null],
            ModuleResolvedFixtureHandler::$payloads[0]
        );
    }

    public function testATaskWhoseModuleIsDisabledFailsAsUnregistered(): void
    {
        // A disabled (or unknown) module has no manifest loaded, so
        // getTaskHandler() answers null — the runner must fail the task
        // with the "No handler registered" diagnostic, never crash and
        // never silently drop the row.
        $moduleManager = $this->createMock(\Core\Module\ModuleManager::class);
        $moduleManager->method('getTaskHandler')->willReturn(null);
        $this->runner->setModuleManager($moduleManager);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('disabled_module', 'their_task', $pastTime, null, null);

        $processed = $this->runner->processOverdue();

        $this->assertSame(0, $processed);
        $action = $this->repo->findById($id);
        $this->assertSame('failed', $action['status']);
        $this->assertStringContainsString('No handler registered', $action['last_error']);
    }

    public function testAManifestNamingAMissingClassFailsTheTaskAsUnregistered(): void
    {
        // A manifest can name a class that is not on disk (a module
        // half-deployed over FTP). class_exists() is the guard; the
        // outcome must be the same clean failure as no handler at all.
        $moduleManager = $this->createMock(\Core\Module\ModuleManager::class);
        $moduleManager->method('getTaskHandler')->willReturn('\\Modules\\Nowhere\\Task\\GhostHandler');
        $this->runner->setModuleManager($moduleManager);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->repo->create('half_deployed', 'ghost_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $action = $this->repo->findById($id);
        $this->assertSame('failed', $action['status']);
        $this->assertStringContainsString('No handler registered', $action['last_error']);
    }

    public function testARegisteredFactoryBuildsTheHandlerLazilyWithTheContextAndOnlyOnce(): void
    {
        $builds = 0;
        $seenContext = null;
        $handler = new class implements TaskHandlerInterface {
            public int $calls = 0;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->calls++;
            }
        };
        $this->runner->registerHandlerFactory('some_module', 'lazy_task', function (TaskContext $context) use (&$builds, &$seenContext, $handler): TaskHandlerInterface {
            $builds++;
            $seenContext = $context;

            return $handler;
        });

        // Nothing due yet: the factory must not have run at all.
        $this->runner->processOverdue();
        $this->assertSame(0, $builds);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->create('some_module', 'lazy_task', $pastTime, null, null);
        $this->repo->create('some_module', 'lazy_task', $pastTime, null, null);

        $processed = $this->runner->processOverdue();

        $this->assertSame(2, $processed);
        $this->assertSame(2, $handler->calls);
        $this->assertSame(1, $builds, 'The factory builds once; the instance serves the rest of the pass.');
        $this->assertInstanceOf(TaskContext::class, $seenContext);
    }

    public function testARegisteredFactoryWinsOverModuleManagerResolution(): void
    {
        // The factory carries the wired dependency graph; the manifest's
        // `new $handlerClass()` carries nothing. The wired one must win.
        $handler = new class implements TaskHandlerInterface {
            public bool $called = false;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->called = true;
            }
        };
        $this->runner->registerHandlerFactory('some_module', 'factory_task', static fn (TaskContext $context): TaskHandlerInterface => $handler);

        $moduleManager = $this->createMock(\Core\Module\ModuleManager::class);
        $moduleManager->expects($this->never())->method('getTaskHandler');
        $this->runner->setModuleManager($moduleManager);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->create('some_module', 'factory_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $this->assertTrue($handler->called);
    }

    public function testADirectlyRegisteredHandlerWinsOverModuleManagerResolution(): void
    {
        // The composition roots hand-register a few module handlers WITH
        // their dependencies (inbound_mail's sync, rental's reminders…);
        // the same (module, key) may also be resolvable from the manifest.
        // The hand-registered instance must win, or every carefully wired
        // dependency would be silently replaced by a bare `new`.
        $handler = new class implements TaskHandlerInterface {
            public bool $called = false;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->called = true;
            }
        };
        $this->runner->registerHandler('some_module', 'wired_task', $handler);

        $moduleManager = $this->createMock(\Core\Module\ModuleManager::class);
        $moduleManager->expects($this->never())->method('getTaskHandler');
        $this->runner->setModuleManager($moduleManager);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->create('some_module', 'wired_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $this->assertTrue($handler->called);
    }

    public function testJournalEntriesAreCreatedOnCompletion(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public function handle(array $payload, TaskContext $context): void
            {
                // success
            }
        };

        $this->runner->registerHandler('core', 'logged_task', $handler);

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'logged_task', $pastTime, null, null);

        $this->runner->processOverdue();

        $journalRepo = new JournalRepository($this->pdo);
        $entries = $journalRepo->search();
        $this->assertGreaterThan(0, count($entries));
        $this->assertSame('scheduler_task_done', $entries[0]['event_type']);
    }
}

/**
 * Named (not anonymous) because the runner instantiates it from its class
 * NAME with no arguments — exactly what auto-resolution does to a real
 * module handler.
 */
final class ModuleResolvedFixtureHandler implements TaskHandlerInterface
{
    /** @var list<array<string, mixed>> */
    public static array $payloads = [];

    public function handle(array $payload, TaskContext $context): void
    {
        self::$payloads[] = $payload;
    }
}
