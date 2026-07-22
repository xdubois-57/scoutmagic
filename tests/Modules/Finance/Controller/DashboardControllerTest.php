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
use Modules\Finance\Repository\StatementImportRepository;
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
    private AttachmentRepository $attachmentRepository;
    private TransactionAttachmentRepository $transactionAttachmentRepository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->fiscalYearRepository = new FiscalYearRepository($this->pdo, new \Core\Config\ScoutYearService($this->pdo));
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $balanceService = new BalanceService($this->checkpointRepository, $this->transactionRepository);
        $categoryRuleRepository = new \Modules\Finance\Repository\CategoryRuleRepository($this->pdo);
        $settingService = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $accountTransferCategoryService = new \Modules\Finance\Service\AccountTransferCategoryService(
            $this->categoryRepository, $categoryRuleRepository, $this->transactionRepository
        );
        $financeService = new FinanceService(
            $this->accountRepository, $this->categoryRepository, $this->fiscalYearRepository, $sectionService, $this->transactionRepository, $balanceService,
            $settingService, $categoryRuleRepository, $accountTransferCategoryService
        );

        $this->attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, sys_get_temp_dir() . '/finance_dashboard_test_' . uniqid());
        $receiptService = new ReceiptService($this->attachmentRepository, $this->accountRepository, $this->transactionAttachmentRepository, $fileStorage);

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

        $this->controller = new DashboardController(
            $twig,
            $financeService,
            $balanceService,
            $this->transactionRepository,
            $receiptService,
            $this->categoryRepository,
            $this->attachmentRepository,
            $this->transactionAttachmentRepository,
            new StatementImportRepository($this->pdo),
            new \Modules\Finance\Service\FirstReceiptResolver($this->transactionAttachmentRepository, $this->attachmentRepository)
        );

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
        $this->createAccount();
        $currentLabel = \Core\Config\ScoutYearService::labelForDate(new \DateTimeImmutable('today'));

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertStringContainsString('Compte', $response->getBody());
        $this->assertStringContainsString($currentLabel, $response->getBody());
    }

    public function testDefaultsToCurrentFiscalYear(): void
    {
        $this->createAccount();

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertStringContainsString('btn-primary', $response->getBody());
    }

    public function testShowsCategorySummaryAndBilan(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $category = $this->categoryRepository->create('Alimentation');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, $category, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r2', '2026-10-02', 'x', 100.0, $category, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Alimentation', $body);
        $this->assertStringContainsString('Bilan', $body);
        // Charts are only rendered when there is category data.
        $this->assertStringContainsString('chart-net-category-pie', $body);
        $this->assertStringContainsString('chart-income-expense-bar', $body);
        $this->assertStringContainsString('chart-balance-line', $body);
    }

    public function testCategorySummaryLinksUncategorizedRowToFilteredMovementsPage(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString(
            '/finance/movements?account_id=' . $accountId . '&fiscal_year_id=' . $fiscalYearId . '&category_id=none',
            $body
        );
    }

    public function testCategorySummaryLinksCategorizedRowToItsOwnCategoryId(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, $categoryId, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString(
            '/finance/movements?account_id=' . $accountId . '&fiscal_year_id=' . $fiscalYearId . '&category_id=' . $categoryId,
            $body
        );
    }

    public function testShowsUncategorizedAndPendingReceiptAlerts(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);

        $this->assertStringContainsString('non catégorisé', $response->getBody());
    }

    public function testShowsRecentMovementsAndBalance(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $this->checkpointRepository->create($accountId, '2026-10-01', 500.0, BalanceCheckpoint::SOURCE_MANUAL);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'Achat spécifique', -20.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);

        $this->assertStringContainsString('Achat spécifique', $response->getBody());
    }

    public function testRecentMovementsShowsUncategorizedExpenseWithoutReceipt(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'Dépense sans reçu', -20.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);

        $this->assertStringContainsString('Dépense sans reçu', $response->getBody());
    }

    public function testRecentMovementsHidesCategorizedIncomeWithoutReceipt(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $categoryId = $this->categoryRepository->create('Cotisations', 'Description');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'Recette catégorisée', 20.0, $categoryId, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);

        $this->assertStringNotContainsString('Recette catégorisée', $response->getBody());
    }

    public function testRecentMovementsHidesCategorizedExpenseWithReceipt(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $categoryId = $this->categoryRepository->create('Matériel', 'Description');
        $transactionId = $this->transactionRepository->create(
            $accountId, $fiscalYearId, 'r1', '2026-10-01', 'Dépense avec reçu', -20.0, $categoryId, null, Transaction::SOURCE_MANUAL, null
        );
        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('a.pdf', 'a.pdf', 'application/pdf', 100)"
        );
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $attachmentId = $this->attachmentRepository->create($accountId, $fileId, 'application/pdf', 'facture.pdf', null, null, null, 1);
        $this->transactionAttachmentRepository->associate($transactionId, $attachmentId);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);

        $this->assertStringNotContainsString('Dépense avec reçu', $response->getBody());
    }

    public function testRecentMovementsShowsOrdinaryFilteredListWhenACategoryFilterIsActive(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $categoryId = $this->categoryRepository->create('Cotisations', 'Description');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'Recette catégorisée', 20.0, $categoryId, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request(
            'GET', '/finance',
            ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId, 'category_id' => (string) $categoryId],
            [], [], []
        ), []);

        $this->assertStringContainsString('Recette catégorisée', $response->getBody());
        $this->assertStringContainsString('Mouvements filtrés', $response->getBody());
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

    public function testCategoryFilterNoneShowsOnlyUncategorizedMovements(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $category = $this->categoryRepository->create('Alimentation');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'Catégorisé', -20.0, $category, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r2', '2026-10-02', 'Non catégorisé achat', -5.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', [
            'account_id' => (string) $accountId,
            'fiscal_year_id' => (string) $fiscalYearId,
            'category_id' => 'none',
        ], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Non catégorisé achat', $body);
        $this->assertStringNotContainsString('Catégorisé<', $body);
    }

    public function testCategoryFilterByIdShowsOnlyThatCategory(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $categoryA = $this->categoryRepository->create('Alimentation');
        $categoryB = $this->categoryRepository->create('Transport');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'Achat nourriture', -20.0, $categoryA, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r2', '2026-10-02', 'Achat essence', -30.0, $categoryB, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', [
            'account_id' => (string) $accountId,
            'fiscal_year_id' => (string) $fiscalYearId,
            'category_id' => (string) $categoryA,
        ], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Achat nourriture', $body);
        $this->assertStringNotContainsString('Achat essence', $body);
        $this->assertStringContainsString('Mouvements filtrés', $body);
    }

    public function testFreeTextSearchMatchesLabelAndComment(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $transactionId = $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'Achat divers', -20.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->updateEditableFields($transactionId, null, 'Commentaire secret piscine', $fiscalYearId);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r2', '2026-10-02', 'Autre mouvement', -5.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', [
            'account_id' => (string) $accountId,
            'fiscal_year_id' => (string) $fiscalYearId,
            'q' => 'piscine',
        ], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Achat divers', $body);
        $this->assertStringNotContainsString('Autre mouvement', $body);
    }

    public function testFreeTextSearchMatchesLinkedReceiptMerchant(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $transactionId = $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'Achat divers', -20.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r2', '2026-10-02', 'Autre mouvement', -5.0, null, null, Transaction::SOURCE_MANUAL, null);

        $fileId = $this->createFile();
        $attachmentId = $this->attachmentRepository->create($accountId, $fileId, 'application/pdf', 'recu.pdf', null, null, null, null);
        $this->attachmentRepository->updateSuggestedLabel($attachmentId, 'Boulangerie Dupont');
        $this->transactionAttachmentRepository->associate($transactionId, $attachmentId);

        $response = $this->controller->index(new Request('GET', '/finance', [
            'account_id' => (string) $accountId,
            'fiscal_year_id' => (string) $fiscalYearId,
            'q' => 'Boulangerie',
        ], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Achat divers', $body);
        $this->assertStringNotContainsString('Autre mouvement', $body);
    }

    public function testShowsLowestBalance18MonthsMetric(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $today = new \DateTimeImmutable('today');
        $this->checkpointRepository->create($accountId, $today->format('Y-m-d'), 500.0, BalanceCheckpoint::SOURCE_MANUAL);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', $today->format('Y-m-d'), 'Grosse dépense', -450.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);

        $this->assertStringContainsString('Solde le plus bas', $response->getBody());
        $this->assertStringContainsString('50,00', $response->getBody());
    }

    public function testShowsLastImportDateAndImportLink(): void
    {
        $accountId = $this->createAccount();
        (new \Modules\Finance\Repository\StatementImportRepository($this->pdo))->create($accountId, 'bnp', 'releve.csv', 10, 10, 0, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringNotContainsString('Aucun import', $body);
        $this->assertStringContainsString('/finance/import?account_id=' . $accountId, $body);
    }

    public function testShowsMovementsAndReceiptsCountMetrics(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r2', '2026-10-02', 'x', -5.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('/finance/movements?account_id=' . $accountId . '&fiscal_year_id=all', $body);
        $this->assertStringContainsString('/finance/receipts?account_id=' . $accountId, $body);
    }

    public function testShowsPendingReceiptsSectionLimitedToThree(): void
    {
        $accountId = $this->createAccount();
        $fileId = $this->createFile();
        $attachmentRepository = $this->attachmentRepository;
        for ($i = 0; $i < 4; $i++) {
            $attachmentRepository->create($accountId, $fileId, 'application/pdf', "recu-{$i}.pdf", null, null, null, null);
        }

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId], [], [], []), []);
        $body = $response->getBody();

        $this->assertSame(3, substr_count($body, 'En attente</span>'));
    }

    private function createFile(): int
    {
        $this->pdo->exec(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('a.pdf', 'a.pdf', 'application/pdf', 100)"
        );
        return (int) $this->pdo->lastInsertId();
    }
}
