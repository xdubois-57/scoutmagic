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
#[\PHPUnit\Framework\Attributes\Group('database')]
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
            $settingService, $categoryRuleRepository, $accountTransferCategoryService,
            new \Modules\Finance\Service\AccountVisibility(
                // No badge assigned in these fixtures, so the treasurer
                // rule is off and the module behaves exactly as it did
                // before it existed — which is what these tests assert.
                \Modules\Finance\Service\TreasurerScope::systemCaller()
            )
        );

        $this->attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, sys_get_temp_dir() . '/finance_dashboard_test_' . uniqid());
        $receiptService = new ReceiptService($this->attachmentRepository, $this->accountRepository, $this->transactionAttachmentRepository, $fileStorage, $this->transactionRepository);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/finance/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'finance');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        // The shared French format filters (core/View/TwigFactory.php) used by
        // the templates under test - same rendering as the shipped ones.
        $twig->addFilter(new \Twig\TwigFilter('date_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')));
        $twig->addFilter(new \Twig\TwigFilter('datetime_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')));
        $twig->addFilter(new \Twig\TwigFilter('money', fn($a) => $a === null || $a === '' ? '' : number_format((float) $a, 2, ',', ' ') . ' €'));
        $twig->addFilter(new \Twig\TwigFilter('money_cents', fn($c) => $c === null || $c === '' ? '' : number_format(((int) $c) / 100, 2, ',', ' ') . ' €'));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'intendant');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/finance');
        $twig->addGlobal('route_breadcrumb', ['label' => 'Finances', 'parents' => ['Espace animateurs']]);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $expectedReceivableRepository = new \Modules\Finance\Repository\ExpectedReceivableRepository($this->pdo, $encryption);

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
            new \Modules\Finance\Service\FirstReceiptResolver($this->transactionAttachmentRepository, $this->attachmentRepository),
            new \Modules\Finance\Service\ReconciliationService(
                $expectedReceivableRepository,
                new \Modules\Finance\Repository\ReceivableAllocationRepository($this->pdo),
                $this->transactionRepository,
                $this->accountRepository,
                new \Modules\Finance\Service\AccountVisibility(\Modules\Finance\Service\TreasurerScope::systemCaller()),
                \Tests\Modules\Finance\FinanceTestHelper::allocationService($this->pdo, $encryption, $expectedReceivableRepository),
                new \Core\Member\MemberService(new \Core\Import\MemberYearRepository($this->pdo), $encryption, $connection),
                new \Core\Member\Household\HouseholdService(
                    new \Core\Member\Household\HouseholdRepository($this->pdo, $encryption),
                    $encryption
                )
            ),
            new \Core\Config\ScoutYearService($this->pdo)
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

    public function testBreadcrumbReflectsTheSelectedAccount(): void
    {
        // The account picker (_nav.html.twig) changes what this page shows
        // without changing its URL — the breadcrumb's own active segment
        // must reflect the currently selected account, not just the
        // static "Finances" label.
        $this->createAccount();

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Finances · Compte\s*</',
            $response->getBody()
        );
    }

    public function testDefaultsToCurrentFiscalYear(): void
    {
        $this->createAccount();

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertStringContainsString('btn-primary', $response->getBody());
    }

    public function testPagePickerUsesTheNavRailNotTheOldHorizontalScrollRow(): void
    {
        // modules/finance/views/_nav.html.twig used to be a raw
        // overflow-x-auto button row with no scroll affordance on mobile.
        // The finance pages are a fixed set declared in code with short
        // labels, so they are a nav rail (through
        // partials/page_picker.html.twig) — see docs/module-development.md.
        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $body = $response->getBody();
        $this->assertStringContainsString('id="finance-page-picker"', $body);
        $this->assertStringContainsString('nav nav-underline', $body);
        $this->assertStringNotContainsString('overflow-x-auto', $body);
        $this->assertMatchesRegularExpression(
            '/href="\/finance"[^>]*aria-current="page"/s',
            $body
        );
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

        // The tile links are built as Twig strings (stat_tiles partial),
        // so the & separator is HTML-escaped in the rendered attribute.
        $this->assertStringContainsString('/finance/movements?account_id=' . $accountId . '&amp;fiscal_year_id=all', $body);
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

    /**
     * The reconciliation tile is the only figure on this page that is a
     * thing to DO. It has to be zero when there is nothing to do, or it
     * becomes a nag the treasurer learns to ignore.
     */
    public function testTheReconciliationTileIsZeroWhenThereIsNothingToReconcile(): void
    {
        $accountId = $this->createAccount();

        $body = $this->controller->index(
            new Request('GET', '/finance', ['account_id' => (string) $accountId], [], [], []),
            []
        )->getBody();

        $this->assertStringContainsString('À rapprocher', $body);
        $this->assertStringContainsString('Rien en attente', $body);
        $this->assertStringContainsString('/finance/reconciliation?account_id=' . $accountId, $body);
    }

    /**
     * A credit whose communication names nothing the site knows is the
     * "non imputés" situation — one of the four the reconciliation screen
     * exists for, and the tile must say so rather than just show a
     * number.
     */
    public function testACreditNamingNoReceivableShowsUpOnTheTile(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        FinanceTestHelper::createScoutYear($this->pdo, \Core\Config\ScoutYearService::labelForDate(new \DateTimeImmutable('today')), '2025-09-01', '2026-08-31', true);

        // A receivable so the account has something to reconcile against,
        // and a credit that names nothing — the orphan case.
        $receivables = new \Modules\Finance\Repository\ExpectedReceivableRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $receivables->create('finance', 1, $accountId, 4500, '+++123/4567/89012+++', null, null);
        $this->transactionRepository->create(
            $accountId, $fiscalYearId, 'orphan-1', '2026-02-18', 'Virement sans communication',
            30.0, null, null, Transaction::SOURCE_IMPORT, null
        );

        $body = $this->controller->index(
            new Request('GET', '/finance', ['account_id' => (string) $accountId], [], [], []),
            []
        )->getBody();

        $this->assertStringContainsString('1 non imputé', $body);
        $this->assertStringNotContainsString('Rien en attente', $body);
    }

    private function createFile(): int
    {
        $this->pdo->exec(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('a.pdf', 'a.pdf', 'application/pdf', 100)"
        );
        return (int) $this->pdo->lastInsertId();
    }
}
