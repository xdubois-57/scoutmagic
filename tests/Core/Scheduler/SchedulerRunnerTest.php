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

#[\PHPUnit\Framework\Attributes\Group('database')]
class SchedulerRunnerTest extends TestCase
{
    private SchedulerRunner $runner;
    private SchedulerRepository $repo;
    private JournalService $journal;
    private \PDO $pdo;
    private TaskContext $taskContext;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new SchedulerRepository($this->pdo);
        $journalRepo = new JournalRepository($this->pdo);
        $this->journal = new JournalService($journalRepo);
        $this->runner = new SchedulerRunner($this->repo, $this->journal);

        // Create a mock TaskContext for handler calls
        $connection = $this->createStub(Connection::class);
        $encryption = $this->createStub(EncryptionService::class);
        $mailService = $this->createStub(MailService::class);
        $settingRepo = new SettingRepository($this->pdo);
        $settingService = new SettingService($settingRepo);
        $userAccounts = $this->createStub(UserAccountRepository::class);
        $taskContext = new TaskContext($connection, $encryption, $mailService, $this->journal, $settingService, $userAccounts, sys_get_temp_dir());
        $this->runner->setTaskContext($taskContext);
        $this->taskContext = $taskContext;
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
        // Both reserved keys reach the handler whatever it was scheduled
        // with: the requester, so any handler can notify without the
        // caller threading it through, and the row's own id, so a handler
        // that creates something the site must not destroy while it runs
        // can say so in its own payload (Core\Maintenance\Task\
        // RestoreBackupHandler and its safety copy).
        $this->assertSame('value', $handler->payload['key']);
        $this->assertNull($handler->payload['requested_by_user_account_id']);
        $this->assertIsInt($handler->payload['scheduled_action_id']);
        $this->assertGreaterThan(0, $handler->payload['scheduled_action_id']);
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

        // Not lost, just moved: the journal keeps the real text, in the
        // line's context rather than its description (issue #1438).
        $entry = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'scheduler_task_failed' ORDER BY id DESC LIMIT 1"
        )->fetch();
        $context = json_decode((string) $entry['context'], true);
        $this->assertStringContainsString('SQLSTATE', (string) $context['error']);
        $this->assertSame(\PDOException::class, $context['error_class']);
    }

    /**
     * A task's exception can quote a recipient (an SMTP refusal): the
     * journal keeps the cause, not the address (#1522 review).
     */
    public function testAFailedTaskJournalsItsErrorWithoutAddresses(): void
    {
        $this->runner->registerHandler('core', 'mail_task', new class implements TaskHandlerInterface {
            public function handle(array $payload, \Core\Scheduler\TaskContext $context): void
            {
                throw new \RuntimeException('550 Mailbox unavailable for parent.dupont@example.test');
            }
        });
        $this->repo->create('core', 'mail_task', (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), null, null);

        $this->runner->processOverdue();

        $entry = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'scheduler_task_failed' ORDER BY id DESC LIMIT 1"
        )->fetch();
        $error = (string) json_decode((string) $entry['context'], true)['error'];
        $this->assertStringContainsString('550 Mailbox unavailable', $error);
        $this->assertStringNotContainsString('parent.dupont@example.test', $error);
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
        $this->assertSame(1, ModuleResolvedFixtureHandler::$payloads[0]['a']);
        $this->assertNull(ModuleResolvedFixtureHandler::$payloads[0]['requested_by_user_account_id']);
        $this->assertSame($id, ModuleResolvedFixtureHandler::$payloads[0]['scheduled_action_id']);
    }

    public function testATaskWhoseModuleIsDisabledFailsAsUnregistered(): void
    {
        // A disabled (or unknown) module has no manifest loaded, so
        // getTaskHandler() answers null — the runner must fail the task
        // with the "No handler registered" diagnostic, never crash and
        // never silently drop the row.
        $moduleManager = $this->createStub(\Core\Module\ModuleManager::class);
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
        $moduleManager = $this->createStub(\Core\Module\ModuleManager::class);
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

    /**
     * A pass hands back what an earlier pass claimed and never finished,
     * and then runs it — the row freed here must not wait for the next
     * pass, which on a daily crontab is a day.
     *
     * The failure behind this: a handler killed outright takes none of the
     * three ways out of a claim, so its row stays 'processing'; `hasLive()`
     * reads it as a live chain and `SchedulerService::seed()` stops
     * re-arming. Nothing fails and nothing is logged — the task just stops
     * happening.
     */
    public function testAPassHandsBackAndThenRunsATaskAnEarlierPassAbandoned(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public bool $called = false;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->called = true;
            }
        };
        $this->runner->registerHandler('core', 'abandoned_task', $handler);

        $id = $this->repo->create(
            'core',
            'abandoned_task',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            null,
            null
        );
        // Claimed and started, then the process holding it disappears.
        $this->repo->claimOverdue();
        $this->repo->markStarted($id);
        $stmt = $this->pdo->prepare('UPDATE scheduled_actions SET claimed_at = ?, started_at = ? WHERE id = ?');
        $longAgo = (new \DateTimeImmutable('-12 hours'))->format('Y-m-d H:i:s');
        $stmt->execute([$longAgo, $longAgo, $id]);

        $this->runner->processOverdue();

        $this->assertTrue($handler->called, 'the reclaimed task runs in the very pass that freed it');
        $this->assertSame('done', $this->repo->findById($id)['status']);
    }

    /**
     * One task replaces the whole database under its own pass — a restore
     * — and the restored schema may predate `started_at`. The rows after
     * it in the list must still run rather than the stamp killing the pass
     * that just restored the site.
     */
    public function testATaskAfterOneThatReplacedTheSchemaStillRuns(): void
    {
        $pdo = $this->pdo;
        $restorer = new class ($pdo) implements TaskHandlerInterface {
            public function __construct(private \PDO $pdo)
            {
            }

            public function handle(array $payload, TaskContext $context): void
            {
                $this->pdo->exec('ALTER TABLE scheduled_actions DROP COLUMN started_at');
            }
        };
        $after = new class implements TaskHandlerInterface {
            public bool $called = false;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->called = true;
            }
        };
        $this->runner->registerHandler('core', 'restore_like', $restorer);
        $this->runner->registerHandler('core', 'after_restore', $after);
        $past = (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s');
        $this->repo->create('core', 'restore_like', $past, null, null);
        $this->repo->create('core', 'after_restore', $past, null, null);

        $this->runner->processOverdue();

        $this->assertTrue($after->called);
    }

    /**
     * And it says so. A chain that froze and was restarted is a fact an
     * administrator has to be able to find afterwards — silently repairing
     * it would replace one invisible state with another.
     */
    public function testReclaimingATaskIsJournaled(): void
    {
        $id = $this->repo->create(
            'core',
            'abandoned_task',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            null,
            null
        );
        $this->repo->claimOverdue();
        $this->repo->markStarted($id);
        $stmt = $this->pdo->prepare('UPDATE scheduled_actions SET claimed_at = ?, started_at = ? WHERE id = ?');
        $longAgo = (new \DateTimeImmutable('-12 hours'))->format('Y-m-d H:i:s');
        $stmt->execute([$longAgo, $longAgo, $id]);

        $this->runner->processOverdue();

        $entries = $this->pdo->query(
            "SELECT level, description FROM event_log WHERE event_type = 'scheduler_task_reclaimed'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries[0]['level']);
        $this->assertStringContainsString('abandoned_task', $entries[0]['description']);
    }

    /**
     * A task whose exception message passes the journal's 500 characters
     * no longer ends the pass (issue #1438): the next task runs, and the
     * failure line keeps the message in its context, bounded.
     */
    public function testALongFailureMessageNeitherStopsThePassNorIsLost(): void
    {
        $failing = new class implements TaskHandlerInterface {
            public function handle(array $payload, TaskContext $context): void
            {
                throw new \RuntimeException(str_repeat('é', 2000) . ' et la suite');
            }
        };
        $next = new class implements TaskHandlerInterface {
            public bool $called = false;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->called = true;
            }
        };
        $this->runner->registerHandler('core', 'long_failure', $failing);
        $this->runner->registerHandler('core', 'after_it', $next);
        $this->repo->create('core', 'long_failure', (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s'), null, null);
        $nextId = $this->repo->create('core', 'after_it', (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), null, null);

        $this->runner->processOverdue();

        $this->assertTrue($next->called);
        $this->assertSame('done', $this->repo->findById($nextId)['status']);

        $row = $this->pdo->query("SELECT description, context FROM event_log WHERE event_type = 'scheduler_task_failed'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('Tâche planifiée « long_failure » échouée', $row['description']);
        $context = json_decode((string) $row['context'], true);
        $this->assertSame(\RuntimeException::class, $context['error_class']);
        $this->assertSame(2000, mb_strlen($context['error'], 'UTF-8'));
        $this->assertStringStartsWith('éé', $context['error']);
        $this->assertSame('core', $context['module_id']);
    }

    /**
     * A journal that cannot write does not turn a task that succeeded into
     * one marked failed, and does not stop the pass (issue #1438).
     */
    public function testAJournalThatCannotWriteLeavesASuccessfulTaskDone(): void
    {
        $broken = new class ($this->pdo) extends JournalRepository {
            public function insert(
                string $category,
                string $type,
                string $level,
                string $description,
                ?string $contextJson,
                ?int $userId,
                ?string $ipAddress = null
            ): void {
                throw new \PDOException('SQLSTATE[22001]: Data too long for column description');
            }
        };
        $runner = new SchedulerRunner($this->repo, new JournalService($broken));
        $runner->setTaskContext($this->taskContext);
        $handler = new class implements TaskHandlerInterface {
            public int $calls = 0;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->calls++;
            }
        };
        $runner->registerHandler('core', 'fine_task', $handler);
        $first = $this->repo->create('core', 'fine_task', (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s'), null, null);
        $second = $this->repo->create('core', 'fine_task', (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), null, null);
        $logFile = (string) tempnam(sys_get_temp_dir(), 'scheduler-log');
        $log = ini_set('error_log', $logFile);

        try {
            $processed = $runner->processOverdue();
            $written = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', (string) $log);
            @unlink($logFile);
        }

        // What the journal would have kept reaches the PHP error log.
        $this->assertStringContainsString('scheduler_task_done « Tâche planifiée « fine_task » terminée »', $written);
        $this->assertStringContainsString('"task_id":' . $first, $written);
        $this->assertSame(2, $processed);
        $this->assertSame(2, $handler->calls);
        $this->assertSame('done', $this->repo->findById($first)['status']);
        $this->assertSame('done', $this->repo->findById($second)['status']);
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

    /**
     * A start stamp that fails for any reason other than a missing column
     * stops the pass before the handler runs (#1554 review): started
     * without its stamp and then killed, the task would come back as
     * never started, escaping the delay and the attempt count.
     */
    public function testAStartStampThatFailsOtherwiseStopsBeforeTheHandler(): void
    {
        $handler = new class implements TaskHandlerInterface {
            public bool $called = false;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->called = true;
            }
        };
        $repo = new class ($this->pdo) extends SchedulerRepository {
            public function markStarted(int $id): bool
            {
                $e = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
                $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
                throw $e;
            }
        };
        $runner = new SchedulerRunner($repo, $this->journal);
        $runner->setTaskContext($this->taskContext);
        $runner->registerHandler('core', 'stamped_task', $handler);
        $repo->create('core', 'stamped_task', (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), null, null);

        try {
            $runner->processOverdue();
            $this->fail('The stamp failure must stop the pass.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('gone away', $e->getMessage());
        }

        $this->assertFalse($handler->called);
    }
}
