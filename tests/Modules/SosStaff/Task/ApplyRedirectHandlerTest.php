<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\SosStaff\Task\ApplyRedirectHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * @group database
 */
class ApplyRedirectHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SchedulerRunner $runner;
    private SchedulerRepository $schedulerRepository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('transition_hour', '10:00', 'text', 'Heure', 'desc', 'sos_staff');
        $settingService->register('email_notifications_enabled', '1', 'boolean', 'Emails', 'desc', 'sos_staff');
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $this->runner = new SchedulerRunner($this->schedulerRepository, $journalService);
        $this->runner->registerHandler('sos_staff', 'apply_redirect', new ApplyRedirectHandler());

        $mailService = $this->createMock(MailService::class);
        $userAccounts = new UserAccountRepository($this->pdo, $encryption);

        $this->runner->setTaskContext(new TaskContext(
            $connection,
            $encryption,
            $mailService,
            $journalService,
            $settingService,
            $userAccounts,
            sys_get_temp_dir()
        ));
    }

    public function testHandleBuildsFullServiceGraphAndFailsGracefullyWithNoProviderConfigured(): void
    {
        // A fresh install has no provider configured — apply() throws
        // SosException before any network call, so this is safe to run
        // without touching real infrastructure. What this test actually
        // proves is that ApplyRedirectHandler's internal service wiring
        // (every `new X(...)` inside handle()) is correct and doesn't
        // fatal — a wiring mistake would surface as an uncaught TypeError
        // instead of the expected, clean "failed" task status.
        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->schedulerRepository->create(
            'sos_staff',
            'apply_redirect',
            $pastTime,
            json_encode(['date' => '2026-07-05', 'member_id' => 1, 'previous_member_id' => null, 'scout_year_id' => 1]),
            '2026-07-05'
        );

        $this->runner->processOverdue();

        $action = $this->schedulerRepository->findById($id);
        $this->assertSame('failed', $action['status']);
        $this->assertStringContainsString('Aucun fournisseur', $action['last_error']);
    }

    public function testHandleAcceptsNullMemberIdPayloadForDefaultNumberTarget(): void
    {
        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $id = $this->schedulerRepository->create(
            'sos_staff',
            'apply_redirect',
            $pastTime,
            json_encode(['date' => '2026-07-06', 'member_id' => null, 'previous_member_id' => 1, 'scout_year_id' => 1]),
            '2026-07-06'
        );

        $this->runner->processOverdue();

        // Still fails (no provider), but proves the null member_id branch
        // doesn't crash the payload decoding / handler wiring either.
        $action = $this->schedulerRepository->findById($id);
        $this->assertSame('failed', $action['status']);
    }
}
