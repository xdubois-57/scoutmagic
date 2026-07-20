<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\Request;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\DashboardController;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpoint;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceService;
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
class DashboardControllerTest extends TestCase
{
    private \PDO $pdo;
    private DashboardController $controller;
    private AccountRepository $accountRepository;
    private FiscalYearRepository $fiscalYearRepository;
    private CategoryRepository $categoryRepository;
    private TransactionRepository $transactionRepository;
    private BalanceCheckpointRepository $checkpointRepository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->fiscalYearRepository = new FiscalYearRepository($this->pdo);
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $balanceService = new BalanceService($this->checkpointRepository, $this->transactionRepository);
        $financeService = new FinanceService(
            $this->accountRepository, $this->categoryRepository, $this->fiscalYearRepository, $sectionService, $this->transactionRepository, $balanceService
        );

        $attachmentRepository = new AttachmentRepository($this->pdo);
        $transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, sys_get_temp_dir() . '/finance_dashboard_test_' . uniqid());
        $receiptService = new ReceiptService($attachmentRepository, $transactionAttachmentRepository, $fileStorage);

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
        $twig->addGlobal('current_path', '/finance');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $this->controller = new DashboardController($twig, $financeService, $balanceService, $this->transactionRepository, $receiptService);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'intendant@test.be', 'intendant');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testShowsEmptyStateWhenNoAccountsVisible(): void
    {
        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aucun compte visible', $response->getBody());
    }

    private function createAccount(string $roleMinView = 'intendant'): int
    {
        $id = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, 'BE00000000000001', 'Titulaire', $roleMinView);
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$id]);
        return $id;
    }

    public function testRendersAccountAndFiscalYearPickers(): void
    {
        $accountId = $this->createAccount();
        $this->fiscalYearRepository->create('2026-2027', '2026-09-01', '2027-08-31');

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertStringContainsString('Compte', $response->getBody());
        $this->assertStringContainsString('2026-2027', $response->getBody());
    }

    public function testDefaultsToCurrentFiscalYear(): void
    {
        $this->createAccount();
        $this->fiscalYearRepository->create('2025-2026', '2025-09-01', '2026-08-31');
        $currentId = $this->fiscalYearRepository->create('2026-2027', '2026-09-01', '2027-08-31');
        $this->fiscalYearRepository->setCurrent($currentId);

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertStringContainsString('btn-primary', $response->getBody());
    }

    public function testShowsCategorySummaryAndBilan(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->create('2026-2027', '2026-09-01', '2027-08-31');
        $category = $this->categoryRepository->create('Alimentation');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, $category, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r2', '2026-10-02', 'x', 100.0, $category, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Alimentation', $body);
        $this->assertStringContainsString('Bilan', $body);
        // Charts are only rendered when there is category data.
        $this->assertStringContainsString('chart-expenses-pie', $body);
    }

    public function testShowsUncategorizedAndPendingReceiptAlerts(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->create('2026-2027', '2026-09-01', '2027-08-31');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);

        $this->assertStringContainsString('non catégorisé', $response->getBody());
    }

    public function testShowsRecentMovementsAndBalance(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->create('2026-2027', '2026-09-01', '2027-08-31');
        $this->checkpointRepository->create($accountId, '2026-10-01', 500.0, BalanceCheckpoint::SOURCE_MANUAL);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'Achat spécifique', -20.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);

        $this->assertStringContainsString('Achat spécifique', $response->getBody());
    }

    public function testFiltersRespectAccountRoleFloor(): void
    {
        // Only role_min_view=admin accounts exist — an intendant should
        // see the empty state, not this account.
        $this->createAccount('admin');

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertStringContainsString('Aucun compte visible', $response->getBody());
    }

    public function testInvalidAccountIdFallsBackToFirstVisibleAccount(): void
    {
        $accountId = $this->createAccount();

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => '9999'], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
    }
}
