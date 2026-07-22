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
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ConfigRuleController;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountTransferCategoryService;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\Finance\Service\FinanceService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * @group database
 */
class ConfigRuleControllerTest extends TestCase
{
    private \PDO $pdo;
    private ConfigRuleController $controller;
    private CategoryRepository $categoryRepository;
    private CategoryRuleRepository $categoryRuleRepository;
    private FinanceService $financeService;
    private AccountRepository $accountRepository;
    private \Modules\Finance\Service\BulkCategorizationService $bulkCategorizationService;
    private SchedulerService $schedulerService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $fiscalYearRepository = new FiscalYearRepository($this->pdo, new \Core\Config\ScoutYearService($this->pdo));
        $checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $balanceService = new BalanceService($checkpointRepository, $transactionRepository);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $accountTransferCategoryService = new AccountTransferCategoryService(
            $this->categoryRepository, $this->categoryRuleRepository, $transactionRepository
        );

        $this->financeService = new FinanceService(
            $this->accountRepository, $this->categoryRepository, $fiscalYearRepository, $sectionService, $transactionRepository, $balanceService,
            $settingService, $this->categoryRuleRepository, $accountTransferCategoryService
        );

        $ruleEngine = new CategoryRuleEngine($transactionRepository, $this->categoryRuleRepository);
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $aiSuggestionRepository = new \Modules\Finance\Repository\AiCategorySuggestionRepository($this->pdo);
        $aiCategorizationService = new \Modules\Finance\Service\AiCategorizationService(
            null, $this->categoryRepository, $aiSuggestionRepository, $journalService
        );
        $this->schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $this->bulkCategorizationService = new \Modules\Finance\Service\BulkCategorizationService(
            $transactionRepository, $ruleEngine, $aiCategorizationService, $settingService, $this->schedulerService
        );

        $this->controller = new ConfigRuleController(
            new Environment(new ArrayLoader([])), $this->categoryRuleRepository, $ruleEngine, $journalService, $this->financeService,
            $this->bulkCategorizationService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/finance/rules', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    public function testCreateWithAllThreeConditionsAtOnce(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'create',
            'category_id' => $categoryId,
            'keyword_pattern' => 'delhaize',
            'counterparty_account_pattern' => 'BE71096123456769',
            'amount_range' => '10-50',
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $rule = $this->categoryRuleRepository->findById($data['rule_id']);
        $this->assertSame('delhaize', $rule->keywordPattern);
        $this->assertSame('BE71096123456769', $rule->counterpartyAccountPattern);
        $this->assertSame('10-50', $rule->amountRange);
    }

    public function testCreateSavesKeywordPatternLowercased(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'create',
            'category_id' => $categoryId,
            'keyword_pattern' => 'DELHAIZE|Colruyt',
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $data = json_decode($response->getBody(), true);
        $rule = $this->categoryRuleRepository->findById($data['rule_id']);
        $this->assertSame('delhaize|colruyt', $rule->keywordPattern);
    }

    public function testCreateNormalizesCounterpartyAccountPattern(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'create',
            'category_id' => $categoryId,
            'counterparty_account_pattern' => 'be71 0961 2345 6769',
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $data = json_decode($response->getBody(), true);
        $rule = $this->categoryRuleRepository->findById($data['rule_id']);
        $this->assertSame('BE71096123456769', $rule->counterpartyAccountPattern);
    }

    public function testCreateRejectsInvalidFullLengthIban(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'create',
            'category_id' => $categoryId,
            'counterparty_account_pattern' => 'BE71096123456760',
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateAllowsShortPartialCounterpartyFragment(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'create',
            'category_id' => $categoryId,
            'counterparty_account_pattern' => 'be71 0000',
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $rule = $this->categoryRuleRepository->findById($data['rule_id']);
        $this->assertSame('BE710000', $rule->counterpartyAccountPattern);
    }

    public function testCreateRejectsWhenNoConditionSet(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'create',
            'category_id' => $categoryId,
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateRejectsInvalidRegex(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'create',
            'category_id' => $categoryId,
            'keyword_pattern' => '(unclosed[',
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdateRejectsSystemRule(): void
    {
        $account = $this->financeService->createAccount('Louveteaux', Account::TYPE_BANK, null, 'BE71096123456769', 'Titulaire', 'intendant');
        $category = $this->categoryRepository->findByAccountId($account->id);
        $systemRule = $this->categoryRuleRepository->findSystemRuleForCategory($category->id);

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'update',
            'id' => $systemRule->id,
            'category_id' => $category->id,
            'keyword_pattern' => 'hacked',
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($this->categoryRuleRepository->findById($systemRule->id)->keywordPattern);
    }

    public function testDeleteRejectsSystemRule(): void
    {
        $account = $this->financeService->createAccount('Louveteaux', Account::TYPE_BANK, null, 'BE71096123456769', 'Titulaire', 'intendant');
        $category = $this->categoryRepository->findByAccountId($account->id);
        $systemRule = $this->categoryRuleRepository->findSystemRuleForCategory($category->id);

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'delete',
            'id' => $systemRule->id,
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNotNull($this->categoryRuleRepository->findById($systemRule->id));
    }

    public function testResetDefaultsRecreatesSeededRules(): void
    {
        $this->financeService->ensureDefaultCategories();
        foreach ($this->categoryRuleRepository->findAllOrderedByPriority() as $rule) {
            $this->categoryRuleRepository->delete($rule->id);
        }
        $this->assertSame([], $this->categoryRuleRepository->findAllOrderedByPriority());

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'reset_defaults',
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($this->categoryRuleRepository->findAllOrderedByPriority());
    }

    public function testSetAiEnabledPersistsFlag(): void
    {
        $response = $this->controller->save($this->jsonRequest([
            'action' => 'set_ai_enabled',
            'enabled' => true,
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $this->assertSame('1', $settingService->get('ai_categorization_enabled', 'finance', '0'));
    }

    public function testRunOnUncategorizedSchedulesInBackgroundAndMarksRunning(): void
    {
        $response = $this->controller->save($this->jsonRequest([
            'action' => 'run_on_uncategorized',
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($this->bulkCategorizationService->isRunning());

        $scheduled = $this->schedulerService->findAllForTask('finance', 'run_categorization_rules');
        $this->assertCount(1, $scheduled);
    }

    public function testRunOnUncategorizedRejectsWhenAlreadyRunning(): void
    {
        $this->bulkCategorizationService->markRunning();

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'run_on_uncategorized',
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testRunStatusReflectsRunningAndLastResult(): void
    {
        $notRunning = $this->controller->save($this->jsonRequest([
            'action' => 'run_status',
            '_csrf_token' => $this->csrfToken(),
        ]), []);
        $data = json_decode($notRunning->getBody(), true);
        $this->assertFalse($data['running']);
        $this->assertNull($data['last_result']);

        $this->bulkCategorizationService->markRunning();
        $running = $this->controller->save($this->jsonRequest([
            'action' => 'run_status',
            '_csrf_token' => $this->csrfToken(),
        ]), []);
        $this->assertTrue(json_decode($running->getBody(), true)['running']);
    }

    public function testRunInBackgroundEndToEndCategorizesAndClearsRunningFlag(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);
        $transactionRepository = new TransactionRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $accountId = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'VIR Delhaize', -20.0, null, null, 'manual', null);

        $this->bulkCategorizationService->markRunning();
        $this->bulkCategorizationService->runInBackground();

        $this->assertFalse($this->bulkCategorizationService->isRunning());
        $result = $this->bulkCategorizationService->getLastResult();
        $this->assertSame(1, $result['categorized_by_rules']);
        $this->assertSame(0, $result['categorized_by_ai']);
        $this->assertSame(0, $result['still_uncategorized']);
    }

    public function testReorderExcludesSystemRulesFromTheOrdering(): void
    {
        $account = $this->financeService->createAccount('Louveteaux', Account::TYPE_BANK, null, 'BE71096123456769', 'Titulaire', 'intendant');
        $category = $this->categoryRepository->findByAccountId($account->id);
        $systemRule = $this->categoryRuleRepository->findSystemRuleForCategory($category->id);
        $originalPriority = $systemRule->priority;

        $otherCategoryId = $this->categoryRepository->create('Alimentation');
        $id1 = $this->categoryRuleRepository->create($otherCategoryId, 0, 'A', null, null);
        $id2 = $this->categoryRuleRepository->create($otherCategoryId, 1, 'B', null, null);

        $response = $this->controller->save($this->jsonRequest([
            'action' => 'reorder',
            'ordered_ids' => [$id2, $systemRule->id, $id1],
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($originalPriority, $this->categoryRuleRepository->findById($systemRule->id)->priority);
    }
}
