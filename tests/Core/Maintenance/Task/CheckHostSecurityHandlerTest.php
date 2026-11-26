<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\Health\Security\Advisory;
use Core\Maintenance\Health\Security\HostSecurityStore;
use Core\Maintenance\Health\Security\ReferenceSource;
use Core\Maintenance\Health\Security\ReleaseBranch;
use Core\Maintenance\Health\Security\RuntimeVersions;
use Core\Maintenance\Health\Security\WebRuntimeFacts;
use Core\Maintenance\Task\CheckHostSecurityHandler;
use Core\Notification\NotificationService;
use Core\Scheduler\CoreTaskHandlers;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\System\CronExecutionFacts;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Health\Security\FakeReferenceSource;
use Tests\DatabaseTestHelper;

/**
 * Lot 3 of issue #1358: the weekly chain — seeded once, run against the
 * stored measurements, re-armed whatever happens, never doubled.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CheckHostSecurityHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;

    /** @var list<string> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        WebRuntimeFacts::register($this->settings);
        CronExecutionFacts::register($this->settings);
    }

    public function testTheHandlerIsRegisteredForBothEntryPoints(): void
    {
        $this->assertSame(
            CheckHostSecurityHandler::class,
            CoreTaskHandlers::all()[CheckHostSecurityHandler::TASK_KEY] ?? null
        );
    }

    /**
     * It reads what the web and the cron recorded — never its own
     * PHP_VERSION — stores the snapshot, notifies, and queues next week.
     */
    public function testARunEvaluatesTheRecordedMeasurementsNotifiesAndRearmsAWeekAhead(): void
    {
        $this->recordWeb('8.4.1');
        $this->recordCron('8.4.28');

        (new CheckHostSecurityHandler($this->source()))->handle([], $this->context());

        $snapshot = (new HostSecurityStore(new SettingService(new SettingRepository($this->pdo))))->latest();
        $this->assertNotNull($snapshot);
        $this->assertSame('8.4.1', $snapshot->find('php_web')?->version);
        $this->assertSame('8.4.28', $snapshot->find('php_cron')?->version);
        $this->assertSame('vulnerable', $snapshot->find('php_web')?->state);
        $this->assertSame('up_to_date', $snapshot->find('php_cron')?->state);
        $this->assertSame(['core.operational_alert'], $this->dispatched, 'one component in alert, one notification');

        $runAt = strtotime((string) ($this->pending()['run_at'] ?? ''));
        $this->assertGreaterThan(time() + 6 * 86400, $runAt);
        $this->assertLessThan(time() + 9 * 86400, $runAt);
    }

    /** What the NVD's rate limit left is asked for one minute later, not in a week. */
    public function testAnIncompleteRunComesBackInAMinute(): void
    {
        $this->recordWeb('8.4.1', 'OpenSSL 3.0.13', '8.5.0', 'OpenSSL/3.5.1');
        $this->recordCron('8.4.2', 'OpenSSL 3.0.14', '8.6.0', 'OpenSSL/3.5.2');

        (new CheckHostSecurityHandler($this->source()))->handle([], $this->context());

        $runAt = strtotime((string) ($this->pending()['run_at'] ?? ''));
        $this->assertLessThanOrEqual(time() + CheckHostSecurityHandler::CONTINUATION_SECONDS + 5, $runAt);
    }

    /** A failure the service does not absorb still leaves the chain alive. */
    public function testTheChainIsRearmedEvenWhenTheRunFails(): void
    {
        $this->recordWeb('8.4.1');
        $broken = new class implements ReferenceSource {
            public function phpBranches(): array
            {
                throw new \RuntimeException('boom');
            }

            public function endOfLifeBranches(string $product): array
            {
                throw new \RuntimeException('boom');
            }

            public function advisories(string $cpeName): array
            {
                throw new \RuntimeException('boom');
            }
        };

        try {
            (new CheckHostSecurityHandler($broken))->handle([], $this->context());
            $this->fail('the failure is not swallowed: the runner must still record it');
        } catch (\RuntimeException) {
        }

        $this->assertNotNull($this->pending(), 'the next occurrence is queued anyway');
    }

    public function testASecondRunDoesNotQueueASecondChain(): void
    {
        $this->recordWeb('8.4.28');
        $handler = new CheckHostSecurityHandler($this->source());

        $handler->handle([], $this->context());
        $handler->handle([], $this->context());

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = '" . CheckHostSecurityHandler::TASK_KEY
            . "' AND status = 'pending'"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    private function source(): FakeReferenceSource
    {
        return new FakeReferenceSource(
            [new ReleaseBranch('8.4', '8.4.28', true), new ReleaseBranch('8.6', '8.6.0', true)],
            ['openssl' => [new ReleaseBranch('3.0', '3.0.18', true), new ReleaseBranch('3.5', '3.5.4', true)]],
            ['cpe:2.3:a:php:php:8.4.1:*:*:*:*:*:*:*' => [new Advisory('CVE-2026-0001', 9.8, '3.1', 'nvd@nist.gov', 'u')]]
        );
    }

    private function recordWeb(string $php, ?string $openssl = null, ?string $curl = null, ?string $curlSsl = null): void
    {
        $this->settings->setInternal(WebRuntimeFacts::SETTING, (string) json_encode(
            (new WebRuntimeFacts(time() - 60, 'fpm-fcgi', new RuntimeVersions($php, $openssl, $curl, $curlSsl)))->toArray()
        ));
    }

    private function recordCron(string $php, ?string $openssl = null, ?string $curl = null, ?string $curlSsl = null): void
    {
        $facts = new CronExecutionFacts(
            time() - 60, 'cli', true, true, 'exec', 'code 0', null, null, true, 'none',
            new RuntimeVersions($php, $openssl, $curl, $curlSsl)
        );
        $this->settings->setInternal(CronExecutionFacts::SETTING, (string) json_encode($facts->toArray()));
    }

    /** @return array<string, mixed>|null */
    private function pending(): ?array
    {
        return (new SchedulerRepository($this->pdo))->findByModuleAndKey(
            'core',
            CheckHostSecurityHandler::TASK_KEY,
            CheckHostSecurityHandler::REFERENCE
        );
    }

    private function context(): TaskContext
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('recipientsForType')->willReturn([['userAccountId' => 1, 'memberId' => null]]);
        $notifications->method('dispatch')->willReturnCallback(function (string $typeId): void {
            $this->dispatched[] = $typeId;
        });

        return new TaskContext(
            connection: $connection,
            encryption: $this->createStub(EncryptionService::class),
            mailService: $this->createStub(\Core\Mail\MailService::class),
            journal: new JournalService(new JournalRepository($this->pdo)),
            settings: new SettingService(new SettingRepository($this->pdo)),
            userAccounts: $this->createStub(\Core\Security\UserAccountRepository::class),
            storagePath: sys_get_temp_dir(),
            notifications: $notifications
        );
    }
}
