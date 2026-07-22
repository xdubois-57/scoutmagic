<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BulkCategorizationService;
use Modules\Finance\Task\RunCategorizationRulesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class RunCategorizationRulesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AccountRepository $accountRepository;
    private CategoryRepository $categoryRepository;
    private CategoryRuleRepository $categoryRuleRepository;
    private TransactionRepository $transactionRepository;
    private SettingService $settingService;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $this->encryption);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));

        $this->accountId = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function createTaskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settingService,
            $this->createMock(UserAccountRepository::class),
            sys_get_temp_dir()
        );
    }

    public function testHandleCategorizesUncategorizedMovementsAndClearsRunningFlag(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);
        $id = $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, 'r1', '2026-10-01', 'VIR Delhaize', -20.0, null, null, Transaction::SOURCE_MANUAL, null
        );

        $bulkCategorizationService = new BulkCategorizationService(
            $this->transactionRepository,
            new \Modules\Finance\Service\CategoryRuleEngine($this->transactionRepository, $this->categoryRuleRepository),
            new \Modules\Finance\Service\AiCategorizationService(null, $this->categoryRepository, new \Modules\Finance\Repository\AiCategorySuggestionRepository($this->pdo), new JournalService(new JournalRepository($this->pdo))),
            $this->settingService,
            new SchedulerService(new SchedulerRepository($this->pdo))
        );
        $bulkCategorizationService->markRunning();

        $handler = new RunCategorizationRulesHandler();
        $handler->handle([], $this->createTaskContext());

        $this->assertSame($categoryId, $this->transactionRepository->findById($id)->categoryId);
        $this->assertFalse($bulkCategorizationService->isRunning());
        $result = $bulkCategorizationService->getLastResult();
        $this->assertSame(1, $result['categorized_by_rules']);
    }

    public function testHandleDegradesGracefullyWhenCalendarModuleIsNotEnabled(): void
    {
        // No module_registry row for 'calendar' at all — the handler must
        // not try to query a calendar_* table that doesn't even exist in
        // this test database (FinanceTestHelper doesn't create them).
        $categoryId = $this->categoryRepository->create('Alimentation', 'Description');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);
        $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, 'r1', '2026-10-01', 'VIR Delhaize', -20.0, null, null, Transaction::SOURCE_MANUAL, null
        );

        $handler = new RunCategorizationRulesHandler();
        $handler->handle([], $this->createTaskContext());

        $this->assertSame(0, $this->transactionRepository->countUncategorized($this->accountId, $this->fiscalYearId));
    }
}
