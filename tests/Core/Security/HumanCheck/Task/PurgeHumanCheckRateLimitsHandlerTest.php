<?php

declare(strict_types=1);

namespace Tests\Core\Security\HumanCheck\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\HumanCheck\Task\PurgeHumanCheckRateLimitsHandler;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class PurgeHumanCheckRateLimitsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private HumanCheckRateLimitRepository $repository;
    private SchedulerService $schedulerService;
    private SettingService $settings;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $this->repository = new HumanCheckRateLimitRepository($this->pdo);
        $this->schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(
            'human_check_rate_limit_window_minutes',
            '10',
            'number',
            'Fenêtre de limitation par IP (minutes)',
            'Taille de la fenêtre glissante.'
        );

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    public function testDeletesRowsOlderThanTheConfiguredWindow(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO human_check_rate_limits (ip_hash, form_key, created_at) VALUES (?, ?, ?)');
        $stmt->execute(['old-hash', 'news_form_response', (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')]);
        $this->repository->record('recent-hash', 'news_form_response');

        (new PurgeHumanCheckRateLimitsHandler())->handle([], $this->context);

        $this->assertSame(0, $this->repository->countSince('old-hash', 'news_form_response', '2000-01-01 00:00:00'));
        $this->assertSame(1, $this->repository->countSince('recent-hash', 'news_form_response', '2000-01-01 00:00:00'));
    }

    public function testReschedulesItselfForTomorrow(): void
    {
        (new PurgeHumanCheckRateLimitsHandler())->handle([], $this->context);

        $scheduled = $this->schedulerService->find('core', 'purge_human_check_rate_limits', 'daily');
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }
}
