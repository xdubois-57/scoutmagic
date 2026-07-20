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
use Modules\Finance\Controller\ConfigFiscalYearController;
use Modules\Finance\Controller\ConfigRuleController;
use Modules\Finance\Controller\DashboardController;
use Modules\Finance\Controller\ImportController;
use Modules\Finance\Controller\MovementController;
use Modules\Finance\Controller\ReceiptController;
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
class FinanceRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private FinanceService $financeService;
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
    private ReceiptService $receiptService;
    private ReceiptExtractionService $receiptExtractionService;

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
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->fiscalYearRepository = new FiscalYearRepository($this->pdo);
        $this->categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $statementImportRepository = new StatementImportRepository($this->pdo);
        $this->attachmentRepository = new AttachmentRepository($this->pdo);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);

        $this->financeService = new FinanceService($accountRepository, $this->categoryRepository, $this->fiscalYearRepository, $this->sectionService);
        $this->balanceService = new BalanceService($this->checkpointRepository, $this->transactionRepository);
        $this->categoryRuleEngine = new CategoryRuleEngine($this->transactionRepository, $this->categoryRuleRepository);
        $parserFactory = new BankStatementParserFactory();
        $importService = new ImportService(
            $this->pdo, $encryption, $parserFactory, $this->transactionRepository, $this->checkpointRepository,
            $statementImportRepository, $this->fiscalYearRepository, $this->categoryRuleEngine, $this->balanceService
        );
        $fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, sys_get_temp_dir() . '/finance_rbac_test_' . uniqid());
        $this->receiptService = new ReceiptService($this->attachmentRepository, $this->transactionAttachmentRepository, $fileStorage);
        $this->receiptExtractionService = new ReceiptExtractionService($this->schedulerService, null);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/finance/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'finance');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
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
            'config index' => ['/config/finance', 'ConfigController', 'index', 'superadmin', 'admin'],
            'config accounts' => ['/config/finance/accounts', 'ConfigAccountController', 'index', 'superadmin', 'admin'],
            'config categories' => ['/config/finance/categories', 'ConfigCategoryController', 'index', 'superadmin', 'admin'],
            'config rules' => ['/config/finance/rules', 'ConfigRuleController', 'index', 'superadmin', 'admin'],
            'config fiscal years' => ['/config/finance/fiscal-years', 'ConfigFiscalYearController', 'index', 'superadmin', 'admin'],
        ];
    }

    /**
     * @dataProvider routeProvider
     */
    public function testAllowedRoleGetsPage(string $path, string $controllerName, string $action, string $allowedRole, string $deniedRole): void
    {
        AuthSession::login(1, 'allowed@test.be', $allowedRole);

        $response = $this->buildFrontController($path, $controllerName, $action, $allowedRole)->handle(new Request('GET', $path, [], [], [], []));

        $this->assertSame(200, $response->getStatusCode(), "Expected 200 for role {$allowedRole} on {$path}, got {$response->getStatusCode()}: " . $response->getBody());
    }

    /**
     * @dataProvider routeProvider
     */
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
            'DashboardController' => new DashboardController($this->twig, $this->financeService, $this->balanceService),
            'MovementController' => new MovementController(
                $this->twig, $this->financeService, $this->transactionRepository, $this->categoryRepository, $this->fiscalYearRepository,
                $this->attachmentRepository, $this->transactionAttachmentRepository, $this->receiptService, $this->journalService
            ),
            'ImportController' => new ImportController($this->twig, $this->financeService, $this->importService, $this->parserFactory, $this->checkpointRepository),
            'ReceiptController' => new ReceiptController(
                $this->twig, $this->attachmentRepository, $this->transactionAttachmentRepository,
                $this->receiptService, $this->receiptExtractionService, $this->journalService
            ),
            'ConfigController' => new ConfigController($this->twig, $this->financeService, $this->schedulerService),
            'ConfigAccountController' => new ConfigAccountController($this->twig, $this->financeService, $this->sectionService, $this->journalService),
            'ConfigCategoryController' => new ConfigCategoryController($this->twig, $this->financeService, $this->journalService),
            'ConfigRuleController' => new ConfigRuleController($this->twig, $this->categoryRuleRepository, $this->categoryRepository, $this->categoryRuleEngine, $this->journalService),
            'ConfigFiscalYearController' => new ConfigFiscalYearController($this->twig, $this->financeService, $this->journalService),
            default => throw new \RuntimeException("Unknown controller {$name}"),
        };
    }
}
