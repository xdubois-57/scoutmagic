<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Task\ExtractReceiptDataHandler;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class ExtractReceiptDataHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AttachmentRepository $attachmentRepository;
    private ProviderRepository $providerRepository;
    private ProviderModelRepository $modelRepository;
    private EncryptedFileStorageService $fileStorage;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->createLlmTables();
        $this->createFinanceAttachmentTables();

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->attachmentRepository = new AttachmentRepository($this->pdo);
        $this->providerRepository = new ProviderRepository($this->pdo, $this->encryption);
        $this->modelRepository = new ProviderModelRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/finance_extraction_test_' . uniqid();
        $this->fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $this->encryption, $this->storagePath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $this->removeDirectory($this->storagePath);
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createTaskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createMock(UserAccountRepository::class),
            $this->storagePath
        );
    }

    private function createAttachment(): int
    {
        $fileId = $this->fileStorage->store('%PDF fake content', 'application/pdf', 'facture.pdf', 'finance/receipts', 'intendant');
        return $this->attachmentRepository->create(null, $fileId, 'application/pdf', 'facture.pdf', null, null, null, 1);
    }

    public function testNoOpWhenPayloadHasNoAttachmentId(): void
    {
        $handler = new ExtractReceiptDataHandler();
        $handler->handle([], $this->createTaskContext());

        $this->addToAssertionCount(1);
    }

    public function testNoOpWhenAttachmentDoesNotExist(): void
    {
        $handler = new ExtractReceiptDataHandler();
        $handler->handle(['attachment_id' => 9999], $this->createTaskContext());

        $this->addToAssertionCount(1);
    }

    public function testNoOpWhenAttachmentIsArchived(): void
    {
        $attachmentId = $this->createAttachment();
        $this->attachmentRepository->archive($attachmentId);

        // Configure an available provider — if the handler didn't bail out
        // early on the archived status check, this would attempt a real
        // (unreachable) network call and the test would hang/fail slowly.
        $providerId = $this->providerRepository->create('Test', 'anthropic', 'http://127.0.0.1:19', 'sk-test', true);
        $this->modelRepository->upsert($providerId, 'test-model', 'Test Model');

        $handler = new ExtractReceiptDataHandler();
        $handler->handle(['attachment_id' => $attachmentId], $this->createTaskContext());

        $attachment = $this->attachmentRepository->findById($attachmentId);
        $this->assertNull($attachment->suggestedAmount);
    }

    public function testNoOpWhenNoProviderIsConfigured(): void
    {
        $attachmentId = $this->createAttachment();

        $handler = new ExtractReceiptDataHandler();
        $handler->handle(['attachment_id' => $attachmentId], $this->createTaskContext());

        $attachment = $this->attachmentRepository->findById($attachmentId);
        $this->assertNull($attachment->suggestedAmount);
        $this->assertNull($attachment->suggestedDate);
        $this->assertNull($attachment->suggestedSource);
    }

    public function testJournalsFailureAndLeavesAttachmentUnchangedWhenProviderUnreachable(): void
    {
        $attachmentId = $this->createAttachment();

        $providerId = $this->providerRepository->create('Unreachable', 'anthropic', 'http://127.0.0.1:19', 'sk-test', true);
        $this->modelRepository->upsert($providerId, 'test-model', 'Test Model');
        $models = $this->modelRepository->findByProvider($providerId);
        $this->modelRepository->assignTier((int) $models[0]['id'], LlmTier::CHEAP);

        $handler = new ExtractReceiptDataHandler();
        $handler->handle(['attachment_id' => $attachmentId], $this->createTaskContext());

        $attachment = $this->attachmentRepository->findById($attachmentId);
        $this->assertNull($attachment->suggestedAmount);

        $stmt = $this->pdo->prepare("SELECT * FROM event_log WHERE category = 'finance' AND event_type = 'receipt_extraction_failed'");
        $stmt->execute();
        $this->assertNotFalse($stmt->fetch());
    }

    public function testRequestsTheOcrTierNotCheap(): void
    {
        $attachmentId = $this->createAttachment();

        $providerId = $this->providerRepository->create('Unreachable', 'anthropic', 'http://127.0.0.1:19', 'sk-test', true);
        $this->modelRepository->upsert($providerId, 'test-model', 'Test Model');
        $models = $this->modelRepository->findByProvider($providerId);
        $this->modelRepository->assignTier((int) $models[0]['id'], LlmTier::OCR);

        $handler = new ExtractReceiptDataHandler();
        $handler->handle(['attachment_id' => $attachmentId], $this->createTaskContext());

        $stmt = $this->pdo->prepare("SELECT context FROM event_log WHERE category = 'llm_connector' AND event_type = 'llm_request_failed'");
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $context = json_decode((string) $row['context'], true);
        $this->assertSame('ocr', $context['tier']);
    }

    private function normalizeDate(string $rawDate): ?string
    {
        $method = new \ReflectionMethod(ExtractReceiptDataHandler::class, 'normalizeDate');
        $method->setAccessible(true);

        return $method->invoke(new ExtractReceiptDataHandler(), $rawDate);
    }

    public function testNormalizeDateAcceptsIsoDate(): void
    {
        $this->assertSame('2026-10-27', $this->normalizeDate('2026-10-27'));
    }

    public function testNormalizeDateAcceptsIsoDatetimeWithTimeComponent(): void
    {
        $this->assertSame('2026-10-27', $this->normalizeDate('2026-10-27T00:00:00'));
    }

    public function testNormalizeDateAcceptsEuropeanSlashFormat(): void
    {
        $this->assertSame('2026-10-27', $this->normalizeDate('27/10/2026'));
    }

    public function testNormalizeDateAcceptsEuropeanDashFormat(): void
    {
        $this->assertSame('2026-10-27', $this->normalizeDate('27-10-2026'));
    }

    public function testNormalizeDateAcceptsEuropeanDotFormat(): void
    {
        $this->assertSame('2026-10-27', $this->normalizeDate('27.10.2026'));
    }

    public function testNormalizeDateAcceptsSingleDigitDayAndMonth(): void
    {
        $this->assertSame('2026-01-05', $this->normalizeDate('5/1/2026'));
    }

    public function testNormalizeDateRejectsAnImpossibleCalendarDate(): void
    {
        $this->assertNull($this->normalizeDate('30/02/2026'));
    }

    public function testNormalizeDateRejectsUnrecognizableText(): void
    {
        $this->assertNull($this->normalizeDate('27 octobre 2026'));
    }

    public function testNormalizeDateRejectsEmptyString(): void
    {
        $this->assertNull($this->normalizeDate(''));
    }

    private function stripMerchantName(string $description, string $merchant): string
    {
        $method = new \ReflectionMethod(ExtractReceiptDataHandler::class, 'stripMerchantName');
        $method->setAccessible(true);

        return $method->invoke(new ExtractReceiptDataHandler(), $description, $merchant);
    }

    public function testStripMerchantNameRemovesExactOccurrence(): void
    {
        $result = $this->stripMerchantName('Achat de nourriture chez Delhaize', 'Delhaize');

        $this->assertSame('Achat de nourriture chez', $result);
    }

    public function testStripMerchantNameIsCaseInsensitive(): void
    {
        $result = $this->stripMerchantName('Achat de nourriture chez DELHAIZE', 'Delhaize');

        $this->assertStringNotContainsStringIgnoringCase('delhaize', $result);
    }

    public function testStripMerchantNameLeavesDescriptionUntouchedWhenAbsent(): void
    {
        $result = $this->stripMerchantName('Achat de fournitures de bureau', 'Delhaize');

        $this->assertSame('Achat de fournitures de bureau', $result);
    }

    public function testStripMerchantNameFallsBackToOriginalWhenNothingWouldRemain(): void
    {
        $result = $this->stripMerchantName('Delhaize', 'Delhaize');

        $this->assertSame('Delhaize', $result);
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

    private function createFinanceAttachmentTables(): void
    {
        $this->pdo->exec('CREATE TABLE finance_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER,
            file_id INTEGER NOT NULL,
            mime_type TEXT NOT NULL,
            original_filename TEXT NOT NULL,
            suggested_amount REAL,
            suggested_date TEXT,
            suggested_label TEXT,
            suggested_description TEXT,
            suggested_source TEXT,
            matching_ai_attempted_at TEXT,
            status TEXT NOT NULL DEFAULT \'active\',
            parent_attachment_id INTEGER,
            uploaded_by INTEGER,
            uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
