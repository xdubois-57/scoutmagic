<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ReceiptController;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
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
    private AttachmentRepository $attachmentRepository;
    private TransactionAttachmentRepository $transactionAttachmentRepository;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->attachmentRepository = new AttachmentRepository($this->pdo);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/finance_receipt_controller_test_' . uniqid();
        $fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, $this->storagePath);
        $receiptService = new ReceiptService($this->attachmentRepository, $this->transactionAttachmentRepository, $fileStorage);
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
            $twig, $this->attachmentRepository, $this->transactionAttachmentRepository, $receiptService, $extractionService, $journalService
        );

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

    private function uploadRequest(string $tmpPath, string $csrf, ?string $amount = null, ?string $date = null): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/finance/receipts/new', [], [
                '_csrf_token' => $csrf,
                'amount' => $amount ?? '',
                'date' => $date ?? '',
            ], [], []])
            ->onlyMethods(['getFile'])
            ->getMock();
        $request->method('getFile')->willReturn([
            'tmp_name' => $tmpPath,
            'name' => 'facture.pdf',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpPath),
        ]);
        return $request;
    }

    public function testUploadCreatesAttachmentAndRedirects(): void
    {
        $response = $this->controller->upload($this->uploadRequest($this->tmpPdfFile(), $this->csrfToken(), '12,50', '2026-10-01'), []);

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

        $response = $this->controller->list(new Request('GET', '/finance/receipts', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('En attente', $response->getBody());
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

        $this->assertSame(400, $response->getStatusCode());
    }

    private function createTransaction(): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $accountId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare("INSERT INTO finance_fiscal_years (label, start_date, end_date) VALUES ('2026-2027', '2026-09-01', '2027-08-31')");
        $stmt->execute();
        $fiscalYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO finance_transactions (account_id, fiscal_year_id, transaction_date, label, amount, source) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$accountId, $fiscalYearId, '2026-10-01', 'x', -1.0, 'manual']);
        return (int) $this->pdo->lastInsertId();
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
}
