<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Task\BackfillReceiptReadingsHandler;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The receipts filed while the scheduled path had no AI reading (#1342 §A)
 * are given it once, a few an hour (decision 9).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class BackfillReceiptReadingsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AttachmentRepository $attachments;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->attachments = new AttachmentRepository($this->pdo, $this->encryption);
    }

    public function testAReceiptNeverReadIsQueuedForItsReading(): void
    {
        $id = $this->receipt();

        $this->runCatchUp(available: true);

        $this->assertSame([$id], $this->queuedReadings());
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'receipt_readings_backfilled'")->fetchColumn(),
            'the batch is said in the journal'
        );
    }

    /** Not what a person filled in, not what was deleted, not what was already read. */
    public function testWhatWasFilledInByHandDeletedOrAlreadyReadIsLeftAlone(): void
    {
        $byHand = $this->receipt();
        $this->attachments->updateSuggestedData($byHand, 12.5, '2026-09-01', Attachment::SUGGESTED_SOURCE_MANUAL);
        $deleted = $this->receipt();
        $this->attachments->archive($deleted);
        $read = $this->receipt();
        $this->attachments->markExtractionAttempted($read);

        $this->runCatchUp(available: true);

        $this->assertSame([], $this->queuedReadings());
    }

    public function testABatchAtATime(): void
    {
        foreach (range(1, BackfillReceiptReadingsHandler::BATCH_SIZE + 3) as $n) {
            $this->receipt();
        }

        $this->runCatchUp(available: true);

        $this->assertCount(BackfillReceiptReadingsHandler::BATCH_SIZE, $this->queuedReadings());
    }

    /** An hour later, a reading still waiting is not queued a second time. */
    public function testAReadingStillWaitingIsNotQueuedTwice(): void
    {
        $this->receipt();

        $this->runCatchUp(available: true);
        $this->runCatchUp(available: true);

        $this->assertCount(1, $this->queuedReadings());
    }

    /** No provider: nothing queued, and nothing marked — the catch-up resumes when one answers. */
    public function testNothingWhileNoProviderAnswers(): void
    {
        $this->receipt();

        $this->runCatchUp(available: false);

        $this->assertSame([], $this->queuedReadings());
        $this->assertCount(1, $this->attachments->findIdsNeverRead(10));
    }

    public function testTheChainRearmsItself(): void
    {
        $this->runCatchUp(available: false);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'finance' AND task_key = ? AND status = 'pending'"
        );
        $stmt->execute([BackfillReceiptReadingsHandler::TASK_KEY]);

        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    private function receipt(): int
    {
        static $file = 0;
        $file++;
        $this->pdo->prepare(
            'INSERT INTO files (id, relative_path, original_name, mime_type, size_bytes, role_min, module_id)
             VALUES (?, ?, ?, ?, 10, ?, ?)'
        )->execute([1000 + $file, 'finance/receipts/r' . $file, 'r.pdf', 'application/pdf', 'intendant', 'finance']);

        return $this->attachments->create(null, 1000 + $file, 'application/pdf', 'r.pdf', null, null, null, 1);
    }

    /** @return list<int> the receipts an extraction task is pending for */
    private function queuedReadings(): array
    {
        $ids = [];
        foreach ($this->pdo->query(
            "SELECT payload FROM scheduled_actions WHERE module_id = 'finance' AND task_key = 'extract_receipt_data' AND status = 'pending'"
        )->fetchAll(\PDO::FETCH_COLUMN) as $payload) {
            $ids[] = (int) (json_decode((string) $payload, true)['attachment_id'] ?? 0);
        }
        sort($ids);

        return $ids;
    }

    private function runCatchUp(bool $available): void
    {
        $connector = $this->createStub(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn($available);

        $moduleManager = $this->createStub(\Core\Module\ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn(['finance', 'llm_connector']);
        $capabilities = new \Core\Scheduler\TaskCapabilities($moduleManager);
        $capabilities->register(LlmConnectorInterface::class, 'llm_connector', static fn (): object => $connector);

        (new BackfillReceiptReadingsHandler())->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(UserAccountRepository::class),
            sys_get_temp_dir(),
            null,
            $capabilities
        ));
    }
}
