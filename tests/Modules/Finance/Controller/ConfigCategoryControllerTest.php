<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ConfigCategoryController;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AiCategorySuggestionRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountTransferCategoryService;
use Modules\Finance\Service\AiCategorizationService;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\BulkCategorizationService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\Finance\Service\FinanceService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class ConfigCategoryControllerTest extends TestCase
{
    private \PDO $pdo;
    private CategoryRepository $categoryRepository;
    private CategoryRuleRepository $categoryRuleRepository;
    private AiCategorySuggestionRepository $suggestionRepository;
    private FinanceService $financeService;

    private function buildController(bool $aiModuleEnabled): ConfigCategoryController
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $accountRepository = new AccountRepository($this->pdo, $encryption);
        $fiscalYearRepository = new FiscalYearRepository($this->pdo, new \Core\Config\ScoutYearService($this->pdo));
        $transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $balanceService = new BalanceService($checkpointRepository, $transactionRepository);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $accountTransferCategoryService = new AccountTransferCategoryService(
            $this->categoryRepository, $this->categoryRuleRepository, $transactionRepository
        );

        $this->financeService = new FinanceService(
            $accountRepository, $this->categoryRepository, $fiscalYearRepository, $sectionService, $transactionRepository, $balanceService,
            $settingService, $this->categoryRuleRepository, $accountTransferCategoryService
        );

        $ruleEngine = new CategoryRuleEngine($transactionRepository, $this->categoryRuleRepository);
        $aiService = new AiCategorizationService(null, $this->categoryRepository, $this->suggestionRepository, $journalService);
        $bulkService = new BulkCategorizationService($transactionRepository, $ruleEngine, $aiService, $settingService);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/finance/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'finance');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/config/finance/categories');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        return new ConfigCategoryController(
            $twig, $this->financeService, $this->categoryRuleRepository, $journalService,
            $this->suggestionRepository, $bulkService, $aiModuleEnabled
        );
    }

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $this->suggestionRepository = new AiCategorySuggestionRepository($this->pdo);
    }

    public function testIndexRendersRecentAiSuggestionsAsClickableChips(): void
    {
        $this->suggestionRepository->create('Sapins de Noël');
        $controller = $this->buildController(true);

        $response = $controller->index(new Request('GET', '/config/finance/categories', [], [], [], []), []);

        $this->assertStringContainsString('Sapins de Noël', $response->getBody());
        $this->assertStringContainsString('category-suggestion-btn', $response->getBody());
    }

    public function testIndexHidesAiRuleRowWhenModuleDisabled(): void
    {
        $controller = $this->buildController(false);

        $response = $controller->index(new Request('GET', '/config/finance', [], [], [], []), []);

        // The JS wiring for the toggle (harmlessly a no-op via `?.` when
        // the button doesn't exist) is unconditional, so this checks for
        // the actual button element, not just the id string anywhere.
        $this->assertStringNotContainsString('id="toggle-ai-rule-btn"', $response->getBody());
    }

    public function testIndexShowsAiRuleRowWhenModuleEnabled(): void
    {
        $controller = $this->buildController(true);

        $response = $controller->index(new Request('GET', '/config/finance', [], [], [], []), []);

        $this->assertStringContainsString('id="toggle-ai-rule-btn"', $response->getBody());
    }

    public function testResetDefaultsRecreatesDeletedDefaultCategory(): void
    {
        $controller = $this->buildController(false);
        $this->financeService->ensureDefaultCategories();
        $camp = current(array_filter($this->financeService->getAllCategories(), fn($c) => $c->name === 'Camp été'));
        $this->financeService->deleteCategory($camp->id);

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/finance/categories', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $request->method('getRawBody')->willReturn(json_encode(['action' => 'reset_defaults', '_csrf_token' => $token]));

        $response = $controller->save($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertTrue($data['success']);
        $names = array_map(fn($c) => $c->name, $this->financeService->getAllCategories());
        $this->assertContains('Camp été', $names);
    }
}
