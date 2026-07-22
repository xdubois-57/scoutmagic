<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ImportController;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ImportService;
use Modules\Finance\Service\ReceiptMatchingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class ImportControllerTest extends TestCase
{
    private \PDO $pdo;
    private ImportController $controller;
    private AccountRepository $accountRepository;
    private BalanceCheckpointRepository $checkpointRepository;
    private int $accountId;
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $statementImportRepository = new StatementImportRepository($this->pdo);
        $fiscalYearRepository = new FiscalYearRepository($this->pdo, new \Core\Config\ScoutYearService($this->pdo));
        $categoryRepository = new CategoryRepository($this->pdo);
        $categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $ruleEngine = new CategoryRuleEngine($transactionRepository, $categoryRuleRepository);
        $balanceService = new BalanceService($this->checkpointRepository, $transactionRepository);
        $parserFactory = new BankStatementParserFactory();

        $settingService = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $accountTransferCategoryService = new \Modules\Finance\Service\AccountTransferCategoryService(
            $categoryRepository, $categoryRuleRepository, $transactionRepository
        );
        $financeService = new FinanceService(
            $this->accountRepository, $categoryRepository, $fiscalYearRepository, $sectionService, $transactionRepository, $balanceService,
            $settingService, $categoryRuleRepository, $accountTransferCategoryService
        );
        $receiptMatchingService = new ReceiptMatchingService(
            new AttachmentRepository($this->pdo, $encryption), $transactionRepository, new TransactionAttachmentRepository($this->pdo),
            new JournalService(new JournalRepository($this->pdo))
        );
        $importService = new ImportService(
            $this->pdo, $encryption, $parserFactory, $transactionRepository,
            $this->checkpointRepository, $statementImportRepository, $fiscalYearRepository, $ruleEngine, $balanceService,
            $receiptMatchingService
        );

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
        $twig->addGlobal('current_path', '/finance/import');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $this->controller = new ImportController($twig, $financeService, $importService, $parserFactory, $this->checkpointRepository);

        $accountId = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, 'BE00000000000001', 'Titulaire', 'intendant');
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$accountId]);
        $this->accountId = $accountId;

        FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');

        $this->fixturePath = dirname(__DIR__, 3) . '/fixtures/finance/bnp_statement_sample.csv';

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'intendant@test.be', 'intendant');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testFormMarksAccountAsFirstImport(): void
    {
        $response = $this->controller->form(new Request('GET', '/finance/import', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
    }

    private function tmpCopyOfFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'finance_upload_');
        copy($this->fixturePath, $path);
        return $path;
    }

    private function uploadRequest(int $accountId, ?float $balance, string $tmpFilePath): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/finance/import', [], [
                'account_id' => (string) $accountId,
                'bank_code' => 'bnp',
                'balance' => $balance !== null ? (string) $balance : '',
            ], [], []])
            ->onlyMethods(['getFile'])
            ->getMock();
        $request->method('getFile')->willReturn([
            'tmp_name' => $tmpFilePath,
            'name' => 'releve.csv',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFilePath),
        ]);
        return $request;
    }

    public function testUploadSucceedsAndDeletesTemporaryFile(): void
    {
        $tmp = $this->tmpCopyOfFixture();

        $response = $this->controller->upload($this->uploadRequest($this->accountId, 1000.0, $tmp), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('nouvelle', $response->getBody());
        $this->assertFileDoesNotExist($tmp);
    }

    public function testUploadRejectsIbanMismatch(): void
    {
        $otherAccountId = $this->accountRepository->create('Autre compte', Account::TYPE_BANK, null, 'BE99999999999999', 'Titulaire', 'intendant');
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$otherAccountId]);

        $tmp = $this->tmpCopyOfFixture();
        $response = $this->controller->upload($this->uploadRequest($otherAccountId, 1000.0, $tmp), []);

        $this->assertStringContainsString('ne correspond pas', $response->getBody());
    }

    public function testUploadRejectsMissingBalanceOnFirstImport(): void
    {
        $tmp = $this->tmpCopyOfFixture();
        $response = $this->controller->upload($this->uploadRequest($this->accountId, null, $tmp), []);

        $this->assertStringContainsString('obligatoire', $response->getBody());
    }

    public function testUploadRejectsUnknownAccount(): void
    {
        $tmp = $this->tmpCopyOfFixture();
        $response = $this->controller->upload($this->uploadRequest(9999, 1000.0, $tmp), []);

        $this->assertStringContainsString('introuvable', $response->getBody());
    }
}
