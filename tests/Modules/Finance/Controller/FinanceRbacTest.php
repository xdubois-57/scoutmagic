<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ConfigAccountController;
use Modules\Finance\Controller\ConfigCategoryController;
use Modules\Finance\Controller\ConfigController;
use Modules\Finance\Controller\ConfigRuleController;
use Modules\Finance\Controller\DashboardController;
use Modules\Finance\Controller\ImportController;
use Modules\Finance\Controller\MovementController;
use Modules\Finance\Controller\ReceiptController;
use Modules\Finance\Controller\ReceivablesController;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\ReceivablesOverviewService;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ImportService;
use Modules\Finance\Service\ReceiptExtractionService;
use Modules\Finance\Service\ReceiptMatchingService;
use Modules\Finance\Service\ReceiptService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * RBAC boundary for every GET route declared in module.json — espace_chefs
 * routes (role_min intendant): identified -> 403, intendant -> 200.
 * Configuration routes (role_min superadmin, the menu's own floor — see
 * Core\Module\ModuleManifest::MENU_MIN_ROLES): admin -> 403, superadmin -> 200.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FinanceRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private FinanceService $financeService;
    private \Modules\Finance\Service\AccountVisibility $accountVisibility;
    private \Modules\Finance\Service\TreasurerScopeService $treasurerScopeService;
    /** @var \Closure(\Modules\Finance\Service\AccountVisibility): FinanceService */
    private \Closure $financeServiceFactory;
    private ExpectedReceivableRepository $expectedReceivableRepository;
    private ExpectedReceivableService $expectedReceivableService;
    private BalanceService $balanceService;
    private TransactionRepository $transactionRepository;
    private AttachmentRepository $attachmentRepository;
    private SectionService $sectionService;
    private JournalService $journalService;
    private CategoryRuleRepository $categoryRuleRepository;
    private CategoryRepository $categoryRepository;
    private FiscalYearRepository $fiscalYearRepository;
    private BalanceCheckpointRepository $checkpointRepository;
    private CategoryRuleEngine $categoryRuleEngine;
    private SchedulerService $schedulerService;
    private ImportService $importService;
    private BankStatementParserFactory $parserFactory;
    private TransactionAttachmentRepository $transactionAttachmentRepository;
    private StatementImportRepository $statementImportRepository;
    private ReceiptService $receiptService;
    private ReceiptExtractionService $receiptExtractionService;
    private AccountRepository $accountRepository;
    private FileRepository $fileRepository;
    private \Modules\Finance\Service\BulkCategorizationService $bulkCategorizationService;
    private \Modules\Finance\Repository\AiCategorySuggestionRepository $aiSuggestionRepository;
    private \Modules\Finance\Service\FirstReceiptResolver $firstReceiptResolver;
    private ReceivablesOverviewService $receivablesOverviewService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $this->sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $this->journalService = new JournalService(new JournalRepository($this->pdo));
        $this->schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));

        $accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->accountRepository = $accountRepository;
        $this->accountRepository = $accountRepository;
        $this->fileRepository = new FileRepository($this->pdo);
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->fiscalYearRepository = new FiscalYearRepository($this->pdo, new \Core\Config\ScoutYearService($this->pdo));
        $this->categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $statementImportRepository = new StatementImportRepository($this->pdo);
        $this->statementImportRepository = $statementImportRepository;
        $this->attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $this->firstReceiptResolver = new \Modules\Finance\Service\FirstReceiptResolver($this->transactionAttachmentRepository, $this->attachmentRepository);

        $this->balanceService = new BalanceService($this->checkpointRepository, $this->transactionRepository);
        $settingService = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $accountTransferCategoryService = new \Modules\Finance\Service\AccountTransferCategoryService(
            $this->categoryRepository, $this->categoryRuleRepository, $this->transactionRepository
        );
        // Rebuildable, because the treasurer-rule tests at the bottom of
        // this file need the SAME wiring with a different scope, and
        // instantiateController() below reads $this->financeService.
        $this->financeServiceFactory = fn(\Modules\Finance\Service\AccountVisibility $visibility): FinanceService => new FinanceService(
            $accountRepository, $this->categoryRepository, $this->fiscalYearRepository, $this->sectionService, $this->transactionRepository, $this->balanceService,
            $settingService, $this->categoryRuleRepository, $accountTransferCategoryService, $visibility
        );

        // No badge is assigned in these fixtures, so the treasurer rule is
        // off and the module behaves exactly as it did before it existed —
        // which is what every test above scopeToTreasurerOf() asserts.
        $this->treasurerScopeService = new \Modules\Finance\Service\TreasurerScopeService(
            Connection::withPdo($this->pdo),
            new \Core\Badge\BadgeRepository($this->pdo),
            new MemberBadgeRepository($this->pdo)
        );
        $this->accountVisibility = new \Modules\Finance\Service\AccountVisibility(
            \Modules\Finance\Service\TreasurerScope::systemCaller()
        );
        $this->financeService = ($this->financeServiceFactory)($this->accountVisibility);
        $this->categoryRuleEngine = new CategoryRuleEngine($this->transactionRepository, $this->categoryRuleRepository);
        $aiSuggestionRepository = new \Modules\Finance\Repository\AiCategorySuggestionRepository($this->pdo);
        $aiCategorizationService = new \Modules\Finance\Service\AiCategorizationService(
            null, $this->categoryRepository, $aiSuggestionRepository, $this->journalService
        );
        $this->bulkCategorizationService = new \Modules\Finance\Service\BulkCategorizationService(
            $this->transactionRepository, $this->categoryRuleEngine, $aiCategorizationService, $settingService, $this->schedulerService
        );
        $this->aiSuggestionRepository = $aiSuggestionRepository;
        $parserFactory = new BankStatementParserFactory();
        $receiptMatchingService = new ReceiptMatchingService(
            $this->attachmentRepository, $this->transactionRepository, $this->transactionAttachmentRepository, $this->journalService
        );
        $importService = new ImportService(
            $this->pdo, $encryption, $parserFactory, $this->transactionRepository, $this->checkpointRepository,
            $statementImportRepository, $this->fiscalYearRepository, $this->categoryRuleEngine, $this->balanceService,
            $receiptMatchingService, $this->bulkCategorizationService,
            FinanceTestHelper::allocationService($this->pdo, $encryption)
        );
        $fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, sys_get_temp_dir() . '/finance_rbac_test_' . uniqid());
        $this->receiptService = new ReceiptService($this->attachmentRepository, $accountRepository, $this->transactionAttachmentRepository, $fileStorage, $this->transactionRepository);
        $this->receiptExtractionService = new ReceiptExtractionService($this->schedulerService, null);

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
        $twig->addGlobal('current_user_email', 'test@test.be');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $this->twig = $twig;

        $this->importService = $importService;
        $this->parserFactory = $parserFactory;

        $this->expectedReceivableRepository = new ExpectedReceivableRepository($this->pdo, $encryption);
        $this->expectedReceivableService = FinanceTestHelper::receivableService($this->pdo, $encryption, $this->expectedReceivableRepository);
        $this->receivablesOverviewService = new ReceivablesOverviewService(
            $this->expectedReceivableRepository, $this->expectedReceivableService, $accountRepository, $this->accountVisibility
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'dashboard' => ['/finance', 'DashboardController', 'index', 'intendant', 'identified'],
            'movements' => ['/finance/movements', 'MovementController', 'list', 'intendant', 'identified'],
            'import form' => ['/finance/import', 'ImportController', 'form', 'intendant', 'identified'],
            'receipts' => ['/finance/receipts', 'ReceiptController', 'list', 'intendant', 'identified'],
            'receivables' => ['/finance/receivables', 'ReceivablesController', 'index', 'intendant', 'identified'],
            'campaigns' => ['/finance/campaigns', 'CampaignController', 'index', 'intendant', 'identified'],
            'campaign form' => ['/finance/campaigns/new', 'CampaignController', 'form', 'intendant', 'identified'],
            'reconciliation' => ['/finance/reconciliation', 'ReconciliationController', 'index', 'intendant', 'identified'],
            'tools' => ['/finance/tools', 'ToolsController', 'index', 'intendant', 'identified'],
            'config index' => ['/config/finance', 'ConfigController', 'index', 'superadmin', 'admin'],
            'config accounts' => ['/config/finance/accounts', 'ConfigAccountController', 'index', 'superadmin', 'admin'],
            'config categories' => ['/config/finance/categories', 'ConfigCategoryController', 'index', 'superadmin', 'admin'],
        ];
    }

    /**
     * @dataProvider routeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testAllowedRoleGetsPage(string $path, string $controllerName, string $action, string $allowedRole, string $deniedRole): void
    {
        AuthSession::login(1, 'allowed@test.be', $allowedRole);

        $response = $this->buildFrontController($path, $controllerName, $action, $allowedRole)->handle(new Request('GET', $path, [], [], [], []));

        $this->assertSame(200, $response->getStatusCode(), "Expected 200 for role {$allowedRole} on {$path}, got {$response->getStatusCode()}: " . $response->getBody());
    }

    /**
     * @dataProvider routeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testDeniedRoleIsRejected(string $path, string $controllerName, string $action, string $allowedRole, string $deniedRole): void
    {
        AuthSession::login(1, 'denied@test.be', $deniedRole);

        $response = $this->buildFrontController($path, $controllerName, $action, $allowedRole)->handle(new Request('GET', $path, [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    private function buildFrontController(string $path, string $controllerName, string $action, string $roleMin): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', $path, $this->controllerClass($controllerName), $action, $roleMin);

        $configFile = sys_get_temp_dir() . '/test_finance_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController($this->controllerClass($controllerName), $this->instantiateController($controllerName));

        return $fc;
    }

    private function controllerClass(string $name): string
    {
        return "Modules\\Finance\\Controller\\{$name}";
    }

    private function instantiateController(string $name): object
    {
        return match ($name) {
            'DashboardController' => new DashboardController(
                $this->twig, $this->financeService, $this->balanceService, $this->transactionRepository, $this->receiptService,
                $this->categoryRepository, $this->attachmentRepository, $this->transactionAttachmentRepository, $this->statementImportRepository,
                $this->firstReceiptResolver, $this->reconciliationServiceForDashboard(), new \Core\Config\ScoutYearService($this->pdo)
            ),
            'MovementController' => new MovementController(
                $this->twig, $this->financeService, $this->transactionRepository, $this->categoryRepository, $this->fiscalYearRepository,
                $this->attachmentRepository, $this->transactionAttachmentRepository, $this->receiptService, $this->receiptExtractionService,
                $this->firstReceiptResolver, $this->journalService
            ),
            'ImportController' => new ImportController($this->twig, $this->financeService, $this->importService, $this->parserFactory, $this->checkpointRepository),
            'ReceiptController' => new ReceiptController(
                $this->twig, $this->attachmentRepository, $this->transactionAttachmentRepository, $this->transactionRepository, $this->financeService,
                $this->receiptService, $this->receiptExtractionService, $this->firstReceiptResolver, $this->journalService
            ),
            'ConfigController' => new ConfigController($this->twig, $this->financeService, $this->schedulerService),
            'ConfigAccountController' => new ConfigAccountController(
                $this->twig, $this->financeService, $this->sectionService, $this->attachmentRepository, $this->fileRepository, $this->journalService
            ),
            'ConfigCategoryController' => new ConfigCategoryController(
                $this->twig, $this->financeService, $this->categoryRuleRepository, $this->journalService,
                $this->aiSuggestionRepository, $this->bulkCategorizationService, $this->transactionRepository, false
            ),
            'ConfigRuleController' => new ConfigRuleController(
                $this->twig, $this->categoryRuleRepository, $this->categoryRepository, $this->categoryRuleEngine, $this->journalService,
                $this->financeService, $this->bulkCategorizationService
            ),
            'ReceivablesController' => new ReceivablesController($this->twig, $this->receivablesOverviewService),
            'CampaignController' => $this->campaignController(),
            'ReconciliationController' => $this->reconciliationController(),
            'ToolsController' => new \Modules\Finance\Controller\ToolsController(
                $this->twig, $this->financeService, $this->expectedReceivableRepository, $this->journalService,
                new \Modules\Finance\Service\SepaQrCodeService()
            ),
            default => throw new \RuntimeException("Unknown controller {$name}"),
        };
    }

    private function campaignController(): \Modules\Finance\Controller\CampaignController
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $campaignRepository = new \Modules\Finance\Repository\CampaignRepository($this->pdo);
        $campaignRowRepository = new \Modules\Finance\Repository\CampaignRowRepository($this->pdo, $encryption);
        $scoutYearService = new \Core\Config\ScoutYearService($this->pdo);
        $accountVisibility = new \Modules\Finance\Service\AccountVisibility(
            \Modules\Finance\Service\TreasurerScope::systemCaller()
        );

        return new \Modules\Finance\Controller\CampaignController(
            $this->twig,
            new \Modules\Finance\Service\CampaignService(
                $this->pdo,
                $campaignRepository,
                $campaignRowRepository,
                new \Modules\Finance\Service\CampaignImportService(
                    new \Modules\Finance\Repository\MemberLookupRepository($this->pdo)
                ),
                $this->expectedReceivableService,
                new \Modules\Finance\Service\StructuredCommunicationService($this->expectedReceivableRepository),
                $this->accountRepository,
                $accountVisibility,
                new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, sys_get_temp_dir()),
                $this->journalService
            ),
            new \Modules\Finance\Service\CampaignOverviewService(
                $campaignRepository,
                $campaignRowRepository,
                $this->expectedReceivableRepository,
                FinanceTestHelper::allocationService($this->pdo, $encryption, $this->expectedReceivableRepository),
                $this->accountRepository,
                $accountVisibility,
                $this->memberServiceForCampaigns(),
                new \Core\Security\UserAccountRepository($this->pdo, $encryption)
            ),
            new \Modules\Finance\Service\CampaignExportService(),
            new \Modules\Finance\Service\CampaignReminderService(
                $campaignRowRepository,
                $this->expectedReceivableRepository,
                FinanceTestHelper::allocationService($this->pdo, $encryption, $this->expectedReceivableRepository),
                $this->accountRepository,
                $this->memberServiceForCampaigns(),
                new \Modules\Finance\Service\ReceivableQrTokenService($encryption),
                'https://scoutmagic.test',
                null
            ),
            new \Modules\Finance\Service\CampaignNotificationService(
                $campaignRowRepository,
                $this->expectedReceivableRepository,
                FinanceTestHelper::allocationService($this->pdo, $encryption, $this->expectedReceivableRepository),
                new \Core\Member\MemberAccountResolver(
                    new \Core\Import\MemberYearRepository($this->pdo),
                    new \Core\Member\MemberEmailRepository($this->pdo, $encryption),
                    new \Core\Security\UserAccountRepository($this->pdo, $encryption),
                    $encryption
                ),
                $this->memberServiceForCampaigns(),
                new \Core\Import\MemberYearRepository($this->pdo),
                null
            ),
            $this->financeService,
            FinanceTestHelper::allocationService($this->pdo, $encryption, $this->expectedReceivableRepository),
            new \Modules\Finance\Service\PaymentLabelService(
                $campaignRowRepository,
                $this->expectedReceivableRepository,
                FinanceTestHelper::allocationService($this->pdo, $encryption, $this->expectedReceivableRepository),
                $this->accountRepository,
                $this->memberServiceForCampaigns(),
                new \Modules\Finance\Service\SepaQrCodeService(),
                new \Core\Pdf\DocumentPdfService(),
                $this->twig
            ),
            $scoutYearService
        );
    }

    /**
     * The sheet of payment labels is a download rather than a page, so it
     * cannot join routeProvider — which asserts a 200 and would get the
     * 404 of a campaign that does not exist. Its boundary is asserted by
     * the two tests below instead, and their two answers are deliberately
     * different: the denied role is stopped by the guard BEFORE the
     * controller runs, while the allowed one reaches it and is told the
     * campaign does not exist. A 404 on both sides would prove nothing.
     */
    public function testThePaymentLabelSheetIsRefusedOneRoleBelowIntendant(): void
    {
        AuthSession::login(1, 'denied@test.be', 'identified');

        $response = $this->buildFrontController('/finance/campaigns/{id}/labels', 'CampaignController', 'labels', 'intendant')
            ->handle(new Request('GET', '/finance/campaigns/9999/labels', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testThePaymentLabelSheetLetsAnIntendantThrough(): void
    {
        AuthSession::login(1, 'allowed@test.be', 'intendant');

        $response = $this->buildFrontController('/finance/campaigns/{id}/labels', 'CampaignController', 'labels', 'intendant')
            ->handle(new Request('GET', '/finance/campaigns/9999/labels', [], [], [], []));

        $this->assertSame(404, $response->getStatusCode(), 'a 403 here would mean the guard stopped the intendant');
    }

    /**
     * The dashboard's "À rapprocher" tile reads the reconciliation
     * screen's own counts, so it needs the same service.
     */
    private function reconciliationServiceForDashboard(): \Modules\Finance\Service\ReconciliationService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new \Modules\Finance\Service\ReconciliationService(
            $this->expectedReceivableRepository,
            new \Modules\Finance\Repository\ReceivableAllocationRepository($this->pdo),
            $this->transactionRepository,
            $this->accountRepository,
            new \Modules\Finance\Service\AccountVisibility(\Modules\Finance\Service\TreasurerScope::systemCaller()),
            FinanceTestHelper::allocationService($this->pdo, $encryption, $this->expectedReceivableRepository),
            $this->memberServiceForCampaigns(),
            new \Core\Member\Household\HouseholdService(
                new \Core\Member\Household\HouseholdRepository($this->pdo, $encryption),
                $encryption
            )
        );
    }

    private function reconciliationController(): \Modules\Finance\Controller\ReconciliationController
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accountVisibility = new \Modules\Finance\Service\AccountVisibility(
            \Modules\Finance\Service\TreasurerScope::systemCaller()
        );
        $allocations = FinanceTestHelper::allocationService($this->pdo, $encryption, $this->expectedReceivableRepository);

        return new \Modules\Finance\Controller\ReconciliationController(
            $this->twig,
            new \Modules\Finance\Service\ReconciliationService(
                $this->expectedReceivableRepository,
                new \Modules\Finance\Repository\ReceivableAllocationRepository($this->pdo),
                $this->transactionRepository,
                $this->accountRepository,
                $accountVisibility,
                $allocations,
                $this->memberServiceForCampaigns(),
                new \Core\Member\Household\HouseholdService(
                    new \Core\Member\Household\HouseholdRepository($this->pdo, $encryption),
                    $encryption
                )
            ),
            $allocations,
            $this->expectedReceivableRepository,
            $this->financeService,
            $this->memberServiceForCampaigns(),
            new \Core\Config\ScoutYearService($this->pdo),
            new \Modules\Finance\Service\SepaQrCodeService()
        );
    }

    private function memberServiceForCampaigns(): \Core\Member\MemberService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new \Core\Member\MemberService(
            new \Core\Import\MemberYearRepository($this->pdo),
            $encryption,
            Connection::withPdo($this->pdo)
        );
    }

    // ------------------------------------------------------------------
    // The section boundary — an intendant is a treasurer of the sections
    // they animate, and of no others.
    //
    // Every test below is a ROUTE, not a list: filtering the account
    // picker is UI, and the picker is not what an attacker uses. Each of
    // these endpoints receives an account id from the client, which is
    // exactly where "the list was filtered" stops being an answer
    // (SECURITY.md §3). The pairs are deliberate — the same request is run
    // once as the section's own treasurer (expected through) and once as
    // the treasurer of the other section (expected refused), so a check
    // that simply always denied would fail just as loudly as one that
    // always allowed.
    // ------------------------------------------------------------------

    public function testTheAccountPickerOffersOnlyTheSectionsThisTreasurerAnimates(): void
    {
        [$mineAccount, $theirsAccount] = $this->twoSectionAccounts();
        $this->scopeToTreasurerOf(self::SECTION_MINE);

        $visible = array_map(
            static fn($account) => $account->id,
            $this->financeService->getAccountsForUser(\Core\Security\Role::INTENDANT)
        );

        $this->assertContains($mineAccount, $visible);
        $this->assertNotContains($theirsAccount, $visible);
    }

    public function testTheUnitsOwnAccountStaysVisibleToEveryIntendant(): void
    {
        $this->twoSectionAccounts();
        $unitAccount = $this->createAccount('Unité', null);
        $this->scopeToTreasurerOf(self::SECTION_MINE);

        $visible = array_map(
            static fn($account) => $account->id,
            $this->financeService->getAccountsForUser(\Core\Security\Role::INTENDANT)
        );

        $this->assertContains($unitAccount, $visible);
    }

    public function testTheChefDUniteStillSeesEverySectionsAccount(): void
    {
        [$mineAccount, $theirsAccount] = $this->twoSectionAccounts();
        $this->scopeToTreasurerOf(self::SECTION_MINE);

        $visible = array_map(
            static fn($account) => $account->id,
            $this->financeService->getAccountsForUser(\Core\Security\Role::ADMIN)
        );

        $this->assertContains($mineAccount, $visible);
        $this->assertContains($theirsAccount, $visible);
    }

    public function testWithNoBadgeAssignedAnywhereNothingChangesAtAll(): void
    {
        [$mineAccount, $theirsAccount] = $this->twoSectionAccounts();
        // Deliberately no scopeToTreasurerOf(): no badge holder, rule off.
        $visible = array_map(
            static fn($account) => $account->id,
            $this->financeService->getAccountsForUser(\Core\Security\Role::INTENDANT)
        );

        $this->assertContains($mineAccount, $visible);
        $this->assertContains($theirsAccount, $visible);
    }

    public function testImportRefusesAnAccountThisTreasurerDoesNotHold(): void
    {
        [$mineAccount, $theirsAccount] = $this->twoSectionAccounts();
        $this->scopeToTreasurerOf(self::SECTION_MINE);
        AuthSession::login(1, 'tresorier@test.be', 'intendant');

        $allowed = $this->importInto($mineAccount);
        $refused = $this->importInto($theirsAccount);

        // Not the same message: "no file" means the account check passed.
        $this->assertStringNotContainsString('Accès refusé.', $allowed->getBody());
        $this->assertStringContainsString('Accès refusé.', $refused->getBody());
    }

    public function testUpdatingAMovementRefusesAnAccountThisTreasurerDoesNotHold(): void
    {
        [$mineAccount, $theirsAccount] = $this->twoSectionAccounts();
        $mineMovement = $this->createMovement($mineAccount);
        $theirsMovement = $this->createMovement($theirsAccount);
        $this->scopeToTreasurerOf(self::SECTION_MINE);
        AuthSession::login(1, 'tresorier@test.be', 'intendant');

        $this->assertSame(200, $this->patchMovement($mineMovement)->getStatusCode());
        $this->assertSame(403, $this->patchMovement($theirsMovement)->getStatusCode());
    }

    public function testReadingAMovementsAttachmentsRefusesAnAccountThisTreasurerDoesNotHold(): void
    {
        [$mineAccount, $theirsAccount] = $this->twoSectionAccounts();
        $mineMovement = $this->createMovement($mineAccount);
        $theirsMovement = $this->createMovement($theirsAccount);
        $this->scopeToTreasurerOf(self::SECTION_MINE);
        AuthSession::login(1, 'tresorier@test.be', 'intendant');

        $controller = $this->instantiateController('MovementController');

        $this->assertSame(200, $controller->attachments(new Request('GET', '/x', [], [], [], []), ['id' => (string) $mineMovement])->getStatusCode());
        $this->assertSame(403, $controller->attachments(new Request('GET', '/x', [], [], [], []), ['id' => (string) $theirsMovement])->getStatusCode());
    }

    public function testTheMovementSearchNeverReachesOutsideThisTreasurersSections(): void
    {
        [$mineAccount, $theirsAccount] = $this->twoSectionAccounts();
        $mineMovement = $this->createMovement($mineAccount, 'Achat foulards');
        $this->createMovement($theirsAccount, 'Achat foulards');
        $this->scopeToTreasurerOf(self::SECTION_MINE);
        AuthSession::login(1, 'tresorier@test.be', 'intendant');

        $controller = $this->instantiateController('MovementController');

        // Asking explicitly for the other section's account must not widen
        // the search: an id the caller may not use falls back to their own
        // accounts rather than being honoured. The two movements carry the
        // same label on purpose — only the account tells them apart, and
        // the payload does not name it, so the id is what is asserted.
        $body = $controller->search(
            new Request('GET', '/finance/movements/search', ['q' => 'foulards', 'account_id' => (string) $theirsAccount], [], [], []),
            []
        )->getBody();
        $decoded = json_decode($body, true);

        $this->assertSame(
            [$mineMovement],
            array_map(static fn(array $movement): int => $movement['id'], $decoded['movements'])
        );
    }

    public function testReceivablesOnAnotherSectionsAccountDisappearFromTheOverview(): void
    {
        [$mineAccount, $theirsAccount] = $this->twoSectionAccounts();
        $this->createReceivable($mineAccount, 'MINE');
        $this->createReceivable($theirsAccount, 'THEIRS');
        $this->scopeToTreasurerOf(self::SECTION_MINE);

        // The reconciliation page decided visibility on its own before the
        // shared predicate existed — the exact defect that made it list
        // accounts no other page would show.
        $body = json_encode($this->receivablesOverviewService->buildOverview(\Core\Security\Role::INTENDANT));

        $this->assertStringContainsString('MINE', (string) $body);
        $this->assertStringNotContainsString('THEIRS', (string) $body);
    }

    // --- treasurer-rule fixtures ---

    private const SECTION_MINE = 1;
    private const SECTION_THEIRS = 2;

    /**
     * Rebuilds the finance service — and therefore every controller
     * instantiateController() hands out — with the rule ON and this
     * session holding the badge for $sectionId.
     */
    private function scopeToTreasurerOf(int $sectionId): void
    {
        $memberId = $this->createTreasurer($sectionId);
        $this->accountVisibility = new \Modules\Finance\Service\AccountVisibility(
            \Modules\Finance\Service\TreasurerScope::forSession($this->treasurerScopeService, [$memberId], 1)
        );
        $this->financeService = ($this->financeServiceFactory)($this->accountVisibility);
        $this->receivablesOverviewService = new ReceivablesOverviewService(
            $this->expectedReceivableRepository,
            $this->expectedReceivableService,
            $this->accountRepository,
            $this->accountVisibility
        );
    }

    /** @return array{int, int} the section accounts, mine then theirs */
    private function twoSectionAccounts(): array
    {
        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 20), (2, 'ECL', 'Éclaireurs', 30)");
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES (1, 1, 'LOU01', 'Louveteaux'), (2, 2, 'ECL01', 'Éclaireurs')");
        $this->pdo->exec("INSERT INTO functions (id, desk_code, label, role) VALUES (1, 'ANIM', 'Animateur', 'chief')");
        $this->pdo->exec("INSERT INTO badges (name, is_default, is_active) VALUES ('" . \Core\Badge\BadgeService::BADGE_TREASURER . "', 1, 1)");

        return [
            $this->createAccount('Louveteaux', self::SECTION_MINE),
            $this->createAccount('Éclaireurs', self::SECTION_THEIRS),
        ];
    }

    private function createAccount(string $name, ?int $sectionId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO finance_accounts (name, account_type, section_id, role_min_view, status) VALUES (?, 'bank', ?, 'intendant', 'active')"
        );
        $stmt->execute([$name, $sectionId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createTreasurer(int $sectionId): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk-" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active) VALUES (?, 1, ?, ?, 1)'
        );
        $stmt->execute([$memberId, 'x', 'y']);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, 1, ?)');
        $stmt->execute([$memberYearId, $sectionId]);

        $badgeId = (int) $this->pdo->query("SELECT id FROM badges WHERE name = '" . \Core\Badge\BadgeService::BADGE_TREASURER . "'")->fetchColumn();
        $stmt = $this->pdo->prepare('INSERT INTO member_badges (member_year_id, badge_id) VALUES (?, ?)');
        $stmt->execute([$memberYearId, $badgeId]);

        return $memberId;
    }

    private function createMovement(int $accountId, string $label = 'Mouvement'): int
    {
        return $this->transactionRepository->create(
            $accountId,
            1,
            null,
            '2026-01-15',
            $label,
            -10.0,
            null,
            null,
            \Modules\Finance\Repository\Transaction::SOURCE_MANUAL,
            null
        );
    }

    private function createReceivable(int $accountId, string $label): void
    {
        $this->expectedReceivableRepository->create('news', 1, $accountId, 1000, $label, $label);
    }

    private function importInto(int $accountId): \Core\Http\Response
    {
        return $this->instantiateController('ImportController')->upload(
            new Request('POST', '/finance/import', [], ['account_id' => (string) $accountId, '_csrf_token' => $this->csrfToken()], [], []),
            []
        );
    }

    private function patchMovement(int $movementId): \Core\Http\Response
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['PATCH', '/finance/movements/' . $movementId, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(
            (string) json_encode(['comment' => 'x', '_csrf_token' => $this->csrfToken()])
        );

        return $this->instantiateController('MovementController')->update($request, ['id' => (string) $movementId]);
    }

    private function csrfToken(): string
    {
        $_SESSION['_csrf_token'] ??= bin2hex(random_bytes(32));
        return (string) $_SESSION['_csrf_token'];
    }
}
