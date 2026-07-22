<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ReceiptController;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ReceiptExtractionService;
use Modules\Finance\Service\ReceiptService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class ReceiptControllerTest extends TestCase
{
    private \PDO $pdo;
    private ReceiptController $controller;
    private AccountRepository $accountRepository;
    private AttachmentRepository $attachmentRepository;
    private TransactionAttachmentRepository $transactionAttachmentRepository;
    private TransactionRepository $transactionRepository;
    private string $storagePath;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $fiscalYearRepository = new FiscalYearRepository($this->pdo, new \Core\Config\ScoutYearService($this->pdo));
        $balanceService = new BalanceService($checkpointRepository, $this->transactionRepository);
        $categoryRepository = new CategoryRepository($this->pdo);
        $categoryRuleRepository = new \Modules\Finance\Repository\CategoryRuleRepository($this->pdo);
        $settingService = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $accountTransferCategoryService = new \Modules\Finance\Service\AccountTransferCategoryService(
            $categoryRepository, $categoryRuleRepository, $this->transactionRepository
        );
        $financeService = new FinanceService(
            $this->accountRepository, $categoryRepository, $fiscalYearRepository, $sectionService, $this->transactionRepository, $balanceService,
            $settingService, $categoryRuleRepository, $accountTransferCategoryService
        );

        $this->storagePath = sys_get_temp_dir() . '/finance_receipt_controller_test_' . uniqid();
        $fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, $this->storagePath);
        $receiptService = new ReceiptService($this->attachmentRepository, $this->accountRepository, $this->transactionAttachmentRepository, $fileStorage);
        $extractionService = new ReceiptExtractionService(new SchedulerService(new SchedulerRepository($this->pdo)), null);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/finance/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'finance');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'intendant');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/finance/receipts');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $this->controller = new ReceiptController(
            $twig, $this->attachmentRepository, $this->transactionAttachmentRepository, $this->transactionRepository, $financeService,
            $receiptService, $extractionService,
            new \Modules\Finance\Service\FirstReceiptResolver($this->transactionAttachmentRepository, $this->attachmentRepository),
            $journalService
        );

        $this->accountId = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $this->accountRepository->updateStatus($this->accountId, Account::STATUS_ACTIVE);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'intendant@test.be', 'intendant');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
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

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    private function tmpPdfFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt_test_');
        file_put_contents($path, '%PDF-1.4 fake receipt content');
        return $path;
    }

    private function uploadRequest(string $tmpPath, string $csrf, ?int $accountId = null): Request
    {
        return $this->uploadRequestMulti([$tmpPath], $csrf, $accountId);
    }

    /**
     * @param string[] $tmpPaths
     */
    private function uploadRequestMulti(array $tmpPaths, string $csrf, ?int $accountId = null): Request
    {
        $files = array_map(fn(string $path) => [
            'name' => basename($path),
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
            'type' => 'application/pdf',
        ], $tmpPaths);

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/finance/receipts/new', [], [
                '_csrf_token' => $csrf,
                'account_id' => (string) ($accountId ?? $this->accountId),
            ], [], []])
            ->onlyMethods(['getFiles'])
            ->getMock();
        $request->method('getFiles')->willReturn($files);
        return $request;
    }

    public function testUploadCreatesAttachmentAndRedirects(): void
    {
        $response = $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $this->attachmentRepository->findActiveOrdered());
    }

    public function testUploadRejectsInvalidCsrfToken(): void
    {
        $this->csrfToken();
        $response = $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), 'bad-token'), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('CSRF', $response->getBody());
        $this->assertCount(0, $this->attachmentRepository->findActiveOrdered());
    }

    public function testUploadRejectsUnknownAccount(): void
    {
        $response = $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken(), 9999), []);

        $this->assertStringContainsString('Compte invalide', $response->getBody());
        $this->assertCount(0, $this->attachmentRepository->findActiveOrdered());
    }

    public function testUploadAcceptsMultipleFilesInOneRequest(): void
    {
        $paths = [$this->tmpPdfFile(), $this->tmpPdfFile(), $this->tmpPdfFile()];

        $response = $this->controller->upload($this->uploadRequestMulti($paths, $this->csrfToken()), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(3, $this->attachmentRepository->findActiveOrdered());
    }

    public function testUploadRejectsMoreThanTenFilesInOneRequest(): void
    {
        $paths = array_map(fn() => $this->tmpPdfFile(), range(1, 11));

        $response = $this->controller->upload($this->uploadRequestMulti($paths, $this->csrfToken()), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('10 reçus', $response->getBody());
        $this->assertCount(0, $this->attachmentRepository->findActiveOrdered());
    }

    public function testUploadContinuesOnPartialFailure(): void
    {
        $goodPath = $this->tmpPdfFile();
        $badPath = tempnam(sys_get_temp_dir(), 'receipt_test_');
        file_put_contents($badPath, 'plain text content, not an allowed receipt format');

        $this->controller->upload($this->uploadRequestMulti([$goodPath, $badPath], $this->csrfToken()), []);

        $this->assertCount(1, $this->attachmentRepository->findActiveOrdered());
    }

    private function jsonRequest(string $method, string $path, array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs([$method, $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    public function testListShowsPendingReceipt(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);

        $response = $this->controller->list(new Request('GET', '/finance/receipts', ['account_id' => (string) $this->accountId], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('En attente', $response->getBody());
    }

    public function testListFiltersToPendingOnly(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $pending = $this->attachmentRepository->findActiveOrdered()[0];
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $attachments = $this->attachmentRepository->findActiveOrdered();
        $associated = $attachments[0]->id === $pending->id ? $attachments[1] : $attachments[0];
        $this->transactionAttachmentRepository->associate($this->createTransaction(), $associated->id);

        $response = $this->controller->list(new Request('GET', '/finance/receipts', ['account_id' => (string) $this->accountId, 'pending' => '1'], [], [], []), []);

        $this->assertStringContainsString('data-receipt-id="' . $pending->id . '"', $response->getBody());
        $this->assertStringNotContainsString('data-receipt-id="' . $associated->id . '"', $response->getBody());
    }

    public function testListSearchFiltersByFilename(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $attachment = $this->attachmentRepository->findActiveOrdered()[0];
        $this->pdo->prepare('UPDATE finance_attachments SET original_filename = ? WHERE id = ?')->execute(['delhaize-facture.pdf', $attachment->id]);

        $matching = $this->controller->list(new Request('GET', '/finance/receipts', ['account_id' => (string) $this->accountId, 'q' => 'delhaize'], [], [], []), []);
        $nonMatching = $this->controller->list(new Request('GET', '/finance/receipts', ['account_id' => (string) $this->accountId, 'q' => 'colruyt'], [], [], []), []);

        $this->assertStringContainsString('delhaize-facture.pdf', $matching->getBody());
        $this->assertStringContainsString('Aucun reçu', $nonMatching->getBody());
    }

    public function testSearchActionReturnsPendingReceiptAsJson(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $attachment = $this->attachmentRepository->findActiveOrdered()[0];
        // Amount is never entered at upload time — simulate what
        // Task\ExtractReceiptDataHandler's AI extraction would write.
        $this->attachmentRepository->updateSuggestedData($attachment->id, 12.5, null, Attachment::SUGGESTED_SOURCE_AI);

        $response = $this->controller->search(new Request('GET', '/finance/receipts/search', ['account_id' => (string) $this->accountId], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['receipts']);
        $this->assertTrue($data['receipts'][0]['is_pending']);
        $this->assertSame(12.5, $data['receipts'][0]['suggested_amount']);
        $this->assertSame(1, $data['total']);
        $this->assertSame(1, $data['total_pages']);
    }

    public function testSearchActionFiltersToPendingOnly(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $attachment = $this->attachmentRepository->findActiveOrdered()[0];
        $this->transactionAttachmentRepository->associate($this->createTransaction(), $attachment->id);

        $response = $this->controller->search(new Request('GET', '/finance/receipts/search', ['account_id' => (string) $this->accountId, 'pending' => '1'], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertSame([], $data['receipts']);
    }

    public function testSearchActionPaginatesThirtyPerPage(): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('a.pdf', 'a.pdf', 'application/pdf', 100)"
        );
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();

        for ($i = 0; $i < 31; $i++) {
            $this->attachmentRepository->create($this->accountId, $fileId, 'application/pdf', "facture-{$i}.pdf", null, null, null, 1);
        }

        $page1 = $this->controller->search(new Request('GET', '/finance/receipts/search', ['account_id' => (string) $this->accountId, 'page' => '1'], [], [], []), []);
        $page2 = $this->controller->search(new Request('GET', '/finance/receipts/search', ['account_id' => (string) $this->accountId, 'page' => '2'], [], [], []), []);

        $data1 = json_decode($page1->getBody(), true);
        $data2 = json_decode($page2->getBody(), true);

        $this->assertCount(30, $data1['receipts']);
        $this->assertCount(1, $data2['receipts']);
        $this->assertSame(2, $data1['total_pages']);
        $this->assertSame(31, $data1['total']);
    }

    public function testMovementsActionReturnsLinkedMovements(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $attachment = $this->attachmentRepository->findActiveOrdered()[0];
        $transactionId = $this->createTransaction();
        $this->transactionAttachmentRepository->associate($transactionId, $attachment->id);

        $response = $this->controller->movements(new Request('GET', '/finance/receipts/' . $attachment->id . '/movements', [], [], [], []), ['id' => (string) $attachment->id]);
        $data = json_decode($response->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['movements']);
        $this->assertSame($transactionId, $data['movements'][0]['id']);
        $this->assertSame('x', $data['movements'][0]['label']);
    }

    public function testMovementsActionReturnsEmptyForPendingReceipt(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $attachment = $this->attachmentRepository->findActiveOrdered()[0];

        $response = $this->controller->movements(new Request('GET', '/finance/receipts/' . $attachment->id . '/movements', [], [], [], []), ['id' => (string) $attachment->id]);
        $data = json_decode($response->getBody(), true);

        $this->assertSame([], $data['movements']);
    }

    public function testDeleteArchivesAttachment(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $attachment = $this->attachmentRepository->findActiveOrdered()[0];

        $token = $this->csrfToken();
        $response = $this->controller->delete($this->jsonRequest('DELETE', '/finance/receipts/' . $attachment->id, ['_csrf_token' => $token]), ['id' => (string) $attachment->id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $this->attachmentRepository->findActiveOrdered());
    }

    public function testDeleteReturns400ForUnknownAttachment(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->delete($this->jsonRequest('DELETE', '/finance/receipts/9999', ['_csrf_token' => $token]), ['id' => '9999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    private function createTransaction(): int
    {
        $fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        return $this->transactionRepository->create($this->accountId, $fiscalYearId, 'ref-' . uniqid(), '2026-10-01', 'x', -1.0, null, null, 'manual', null);
    }

    public function testAssociateLinksReceiptToMovement(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $attachment = $this->attachmentRepository->findActiveOrdered()[0];
        $transactionId = $this->createTransaction();

        $token = $this->csrfToken();
        $response = $this->controller->associate(
            $this->jsonRequest('POST', '/finance/receipts/' . $attachment->id . '/associate', ['transaction_ids' => [$transactionId], '_csrf_token' => $token]),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$transactionId], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachment->id));
    }

    public function testDissociateRemovesLink(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $attachment = $this->attachmentRepository->findActiveOrdered()[0];
        $transactionId = $this->createTransaction();
        $this->transactionAttachmentRepository->associate($transactionId, $attachment->id);

        $token = $this->csrfToken();
        $response = $this->controller->dissociate(
            $this->jsonRequest('POST', '/finance/receipts/' . $attachment->id . '/dissociate', ['transaction_id' => $transactionId, '_csrf_token' => $token]),
            ['id' => (string) $attachment->id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachment->id));
    }

    public function testReplaceArchivesOldCreatesNewAndRedirects(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $original = $this->attachmentRepository->findActiveOrdered()[0];

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/finance/receipts/' . $original->id . '/replace', [], ['_csrf_token' => $this->csrfToken()], [], []])
            ->onlyMethods(['getFile'])
            ->getMock();
        $tmp = $this->tmpPdfFile();
        $request->method('getFile')->willReturn(['tmp_name' => $tmp, 'name' => 'v2.pdf', 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]);

        $response = $this->controller->replace($request, ['id' => (string) $original->id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('archived', $this->attachmentRepository->findById($original->id)->status);
        $this->assertCount(1, $this->attachmentRepository->findActiveOrdered());
    }

    public function testMutationsRejectAttachmentBelowRoleFloor(): void
    {
        $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken()), []);
        $attachment = $this->attachmentRepository->findActiveOrdered()[0];

        $adminAccountId = $this->accountRepository->create('Compte admin', Account::TYPE_BANK, null, null, null, 'admin');
        $this->pdo->prepare('UPDATE finance_attachments SET account_id = ? WHERE id = ?')->execute([$adminAccountId, $attachment->id]);

        $token = $this->csrfToken();
        $response = $this->controller->delete($this->jsonRequest('DELETE', '/finance/receipts/' . $attachment->id, ['_csrf_token' => $token]), ['id' => (string) $attachment->id]);

        $this->assertSame(403, $response->getStatusCode());
    }
}
