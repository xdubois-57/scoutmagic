<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Controller\SchedulerContinuationController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerContinuation;
use Core\Scheduler\SchedulerContinuationRoute;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * The endpoint is public and session-free by necessity — the caller is this
 * installation's own PHP process — so the shared secret is the entire
 * authorisation. These tests are the RBAC boundary for it.
 */
class SchedulerContinuationControllerTest extends TestCase
{
    private \PDO $pdo;
    private SchedulerRepository $repo;
    private SchedulerRunner $runner;
    private SchedulerContinuationController $controller;

    private const SECRET = 'a-secret-nobody-else-has';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new SchedulerRepository($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->runner = new SchedulerRunner($this->repo, $journal);

        $settings = new SettingService(new SettingRepository($this->pdo));
        foreach ([
            [SchedulerContinuation::BUDGET_SETTING, '75'],
            [SchedulerContinuation::MAX_HOPS_SETTING, '30'],
            [SchedulerContinuation::HOPS_SETTING, '0'],
        ] as [$key, $default]) {
            $settings->register($key, $default, 'number', $key, 'test', null, null, null, false, 900);
        }

        $this->runner->setTaskContext(new TaskContext(
            $this->createMock(Connection::class),
            $this->createMock(EncryptionService::class),
            $this->createMock(MailService::class),
            $journal,
            $settings,
            $this->createMock(UserAccountRepository::class),
            sys_get_temp_dir()
        ));

        $this->controller = new SchedulerContinuationController(
            $this->createMock(Environment::class),
            new SchedulerContinuation($this->runner, $this->repo, $settings, $journal, $this->pdo, self::SECRET)
        );
    }

    private function request(?string $secret): Request
    {
        $server = [];
        if ($secret !== null) {
            $server[SchedulerContinuationRoute::SECRET_HEADER] = $secret;
        }
        $server['REMOTE_ADDR'] = '203.0.113.7';

        return new Request('POST', SchedulerContinuationRoute::PATH, [], [], [], $server);
    }

    private function queueOne(): TaskHandlerInterface
    {
        $handler = new class implements TaskHandlerInterface {
            public int $calls = 0;

            public function handle(array $payload, TaskContext $context): void
            {
                $this->calls++;
            }
        };
        $this->runner->registerHandler('core', 'test_task', $handler);
        $this->repo->create(
            'core',
            'test_task',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            null,
            null
        );

        return $handler;
    }

    public function testTheRightSecretRunsASlice(): void
    {
        $handler = $this->queueOne();

        $response = $this->controller->continue($this->request(self::SECRET), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $handler->calls);
        $this->assertSame(1, json_decode($response->getBody(), true)['processed']);
    }

    public function testAWrongSecretIsRefusedAndRunsNothing(): void
    {
        $handler = $this->queueOne();

        $response = $this->controller->continue($this->request('wrong'), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $handler->calls);
    }

    /**
     * Absent must be refused exactly like wrong — an endpoint that
     * distinguishes them tells an unauthenticated caller which half of the
     * guess was right.
     */
    public function testAMissingSecretIsRefusedTheSameWayAsAWrongOne(): void
    {
        $handler = $this->queueOne();

        $missing = $this->controller->continue($this->request(null), []);
        $wrong = $this->controller->continue($this->request('wrong'), []);

        $this->assertSame(403, $missing->getStatusCode());
        $this->assertSame($wrong->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($wrong->getBody(), $missing->getBody());
        $this->assertSame(0, $handler->calls);
    }

    /**
     * A refusal is a `security` journal entry — and the presented secret
     * never appears in it. A token in a journal is a token in every
     * backup and every support package (CapabilityToken, contract point 2).
     */
    public function testARefusalIsJournaledWithoutEverRecordingTheSecret(): void
    {
        $this->controller->continue($this->request('the-secret-somebody-guessed'), []);

        $row = $this->pdo->query(
            "SELECT level, event_type, description, context FROM event_log WHERE event_type = 'scheduler_continuation_refused'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'a refused continuation call must be journaled');
        $this->assertSame('security', $row['level']);
        $this->assertStringNotContainsString('the-secret-somebody-guessed', (string) $row['description']);
        $this->assertStringNotContainsString('the-secret-somebody-guessed', (string) ($row['context'] ?? ''));
    }
}
