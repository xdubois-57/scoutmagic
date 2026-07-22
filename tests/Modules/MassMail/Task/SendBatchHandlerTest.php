<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\Recipient;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Task\SendBatchHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * @group database
 */
class SendBatchHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RecipientRepository $recipientRepository;
    private int $emailId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->recipientRepository = new RecipientRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES ('Sujet', '<p>Corps</p>', {$sectionId}, 'default_active_members', '" . Email::STATUS_SENDING . "')"
        );
        $this->emailId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_1')");
        $memberId = (int) $this->pdo->lastInsertId();

        for ($i = 0; $i < 3; $i++) {
            $this->recipientRepository->create($this->emailId, $memberId, $scoutYearId, "member{$i}@test.be", Recipient::STATUS_PENDING, null);
        }

        $settingRepository = new SettingRepository($this->pdo);
        $settingService = new SettingService($settingRepository);
        $settingService->register('batch_size', '2', 'number', 'label', 'desc', 'mass_mail');
        $settingService->register('batch_interval_minutes', '5', 'number', 'label', 'desc', 'mass_mail');
    }

    private function buildContext(MailService $mailService): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }

    public function testProcessesOnlyBatchSizeRecipientsAndReschedulesWhenPendingRemain(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->exactly(2))->method('send');

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $counts = $this->recipientRepository->countGroupedByStatus($this->emailId);
        $this->assertSame(2, $counts['sent']);
        $this->assertSame(1, $counts['pending']);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail' AND task_key = 'send_batch' AND status = 'pending'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testDoesNotRescheduleWhenNoPendingRemain(): void
    {
        // Shrink to 1 recipient so a single batch (size 2) drains everything.
        $this->pdo->exec("DELETE FROM mass_mail_recipients WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_recipients)");

        $mailService = $this->createMock(MailService::class);
        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'mass_mail' AND task_key = 'send_batch' AND status = 'pending'");
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testMailExceptionMarksRecipientAsErrorWithoutLeakingAddress(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willThrowException(new MailException('550 relay denied'));

        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $recipients = $this->recipientRepository->findByEmailId($this->emailId);
        $errored = array_values(array_filter($recipients, fn(Recipient $r) => $r->status === Recipient::STATUS_ERROR));
        $this->assertNotEmpty($errored);
        foreach ($errored as $recipient) {
            $this->assertStringNotContainsString('@test.be', (string) $recipient->errorMessage);
        }
    }

    public function testMarksParentEmailSentOnceAllRecipientsProcessed(): void
    {
        $this->pdo->exec("DELETE FROM mass_mail_recipients WHERE id NOT IN (SELECT MIN(id) FROM mass_mail_recipients)");

        $mailService = $this->createMock(MailService::class);
        $handler = new SendBatchHandler();
        $handler->handle([], $this->buildContext($mailService));

        $stmt = $this->pdo->prepare('SELECT status FROM mass_mail_emails WHERE id = ?');
        $stmt->execute([$this->emailId]);
        $this->assertSame(Email::STATUS_SENT, $stmt->fetchColumn());
    }
}
