<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\AiCategorySuggestionRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AiCategorizationService;
use Modules\Finance\Service\BulkCategorizationService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class BulkCategorizationServiceTest extends TestCase
{
    private \PDO $pdo;
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

        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function service(?LlmConnectorInterface $llmConnector = null): BulkCategorizationService
    {
        $ruleEngine = new CategoryRuleEngine($this->transactionRepository, $this->categoryRuleRepository);
        $aiService = new AiCategorizationService(
            $llmConnector, $this->categoryRepository, new AiCategorySuggestionRepository($this->pdo),
            new JournalService(new JournalRepository($this->pdo))
        );
        return new BulkCategorizationService(
            $this->transactionRepository, $ruleEngine, $aiService, $this->settingService,
            new SchedulerService(new SchedulerRepository($this->pdo))
        );
    }

    private function createTransaction(string $label): int
    {
        return $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, 'ref-' . uniqid(), '2026-10-01', $label, -20.0, null, null, Transaction::SOURCE_MANUAL, null
        );
    }

    public function testAiRuleDisabledByDefault(): void
    {
        $this->assertFalse($this->service()->isAiRuleEnabled());
    }

    public function testSetAiRuleEnabledPersists(): void
    {
        $service = $this->service();
        $service->setAiRuleEnabled(true);

        $this->assertTrue($this->service()->isAiRuleEnabled());
    }

    public function testRunOnUncategorizedAppliesMatchingRule(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);
        $id = $this->createTransaction('VIR Delhaize Bruxelles');

        $result = $this->service()->runOnUncategorized();

        $this->assertSame(1, $result->categorizedByRules);
        $this->assertSame(0, $result->categorizedByAi);
        $this->assertSame(0, $result->stillUncategorized);
        $this->assertSame($categoryId, $this->transactionRepository->findById($id)->categoryId);
    }

    public function testRunOnUncategorizedLeavesUnmatchedMovementsUncategorizedWhenAiDisabled(): void
    {
        $this->createTransaction('Achat mystère');

        $result = $this->service()->runOnUncategorized();

        $this->assertSame(0, $result->categorizedByRules);
        $this->assertSame(0, $result->categorizedByAi);
        $this->assertSame(1, $result->stillUncategorized);
    }

    public function testRunOnUncategorizedUsesAiWhenEnabledAndRulesDontMatch(): void
    {
        $categoryId = $this->categoryRepository->create('Fournitures');
        $this->createTransaction('Achat mystère');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse('{}', ['category' => 'Fournitures', 'new_category_suggestion' => null], 10, 5));

        $service = $this->service($llmConnector);
        $service->setAiRuleEnabled(true);

        $result = $service->runOnUncategorized();

        $this->assertSame(0, $result->categorizedByRules);
        $this->assertSame(1, $result->categorizedByAi);
        $this->assertSame(0, $result->stillUncategorized);
    }

    public function testRunOnUncategorizedNeverCallsAiWhenDisabled(): void
    {
        $this->createTransaction('Achat mystère');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->expects($this->never())->method('complete');

        $result = $this->service($llmConnector)->runOnUncategorized();

        $this->assertSame(1, $result->stillUncategorized);
    }

    public function testRunOnUncategorizedNeverTouchesAlreadyCategorizedMovements(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $id = $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, 'ref-x', '2026-10-01', 'Achat', -20.0, $categoryId, null, Transaction::SOURCE_MANUAL, null
        );

        $result = $this->service()->runOnUncategorized();

        $this->assertSame(0, $result->categorizedByRules + $result->categorizedByAi + $result->stillUncategorized);
        $this->assertSame($categoryId, $this->transactionRepository->findById($id)->categoryId);
    }

    public function testIsRunningFalseByDefault(): void
    {
        $this->assertFalse($this->service()->isRunning());
    }

    public function testMarkRunningSetsRunningFlag(): void
    {
        $service = $this->service();
        $service->markRunning();

        $this->assertTrue($this->service()->isRunning());
    }

    public function testGetLastResultNullBeforeAnyRun(): void
    {
        $this->assertNull($this->service()->getLastResult());
    }

    public function testRunInBackgroundStoresResultAndClearsRunningFlag(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);
        $this->createTransaction('VIR Delhaize');

        $service = $this->service();
        $service->markRunning();
        $service->runInBackground();

        $this->assertFalse($service->isRunning());
        $result = $service->getLastResult();
        $this->assertSame(1, $result['categorized_by_rules']);
        $this->assertSame(0, $result['categorized_by_ai']);
        $this->assertSame(0, $result['still_uncategorized']);
    }
    /**
     * Regression: the marker was a bare '1' cleared only by
     * runInBackground()'s finally block. If the scheduled task was never
     * picked up (the poor man's cron needs a page load), the process died
     * first, or — the most likely case — the batch was killed by PHP's
     * max_execution_time (a fatal error, which skips finally entirely), it
     * stayed set for ever and the config page's "Exécuter les règles" button
     * was permanently disabled, with no way to reset it.
     */
    public function testAStaleRunningMarkerIsNotReportedAsRunning(): void
    {
        $this->service()->markRunning();
        $this->assertTrue($this->service()->isRunning());

        $this->settingService->setInternal(
            'bulk_categorization_started_at',
            (string) (time() - 7200),
            'finance'
        );

        $this->assertFalse($this->service()->isRunning());
        $this->assertTrue($this->service()->scheduleBackgroundRun(), 'a stale marker must not block a new run');
    }

    public function testAFreshRunningMarkerStillBlocksASecondRun(): void
    {
        $this->assertTrue($this->service()->scheduleBackgroundRun());

        $this->assertTrue($this->service()->isRunning());
        $this->assertFalse($this->service()->scheduleBackgroundRun());
    }

    /**
     * A marker written before the timestamp existed is a bare '1' under the
     * old key — it must still read as running rather than crashing or
     * silently resetting, for as long as the task it refers to is queued.
     */
    public function testALegacyBareOneMarkerIsStillReportedAsRunning(): void
    {
        $this->settingService->register('bulk_categorization_running', '0', 'text', 'x', 'x', 'finance', null, null, false);
        $this->settingService->setInternal('bulk_categorization_running', '1', 'finance');
        (new SchedulerService(new SchedulerRepository($this->pdo)))->scheduleAfter('finance', 'run_categorization_rules', 0);

        $this->assertTrue($this->service()->isRunning());
    }

    /**
     * ...but it carries no expiry of its own, so once the task it referred to
     * is gone it must not block a new run for ever either.
     */
    public function testALegacyMarkerWithNoQueuedTaskDoesNotBlockANewRun(): void
    {
        $this->settingService->register('bulk_categorization_running', '0', 'text', 'x', 'x', 'finance', null, null, false);
        $this->settingService->setInternal('bulk_categorization_running', '1', 'finance');

        $this->assertFalse($this->service()->isRunning());
        $this->assertTrue($this->service()->scheduleBackgroundRun());
    }

    /**
     * The upgrade path the legacy tests above cannot reach: a site that ran
     * the previous version carries 'bulk_categorization_running' registered
     * as a `boolean` setting. SettingRepository::upsert() never re-types an
     * existing row and SettingService::setInternal() validates against the
     * stored type, so writing a timestamp into that key throws on exactly the
     * sites that have one. The timestamp lives under its own numeric key for
     * that reason.
     */
    public function testWorksOnAnInstallThatStillCarriesTheOldBooleanFlag(): void
    {
        $this->settingService->register('bulk_categorization_running', '0', 'boolean', 'Ancien indicateur', 'x', 'finance', null, null, false);
        $this->settingService->setInternal('bulk_categorization_running', '1', 'finance');

        $service = $this->service();

        $this->assertTrue($service->scheduleBackgroundRun());
        $this->assertTrue($service->isRunning());

        $service->runInBackground();
        $this->assertFalse($service->isRunning());
        $this->assertNotNull($service->getLastResult());
    }

    public function testRunInBackgroundClearsTheMarkerEvenWhenNothingToDo(): void
    {
        $this->service()->markRunning();

        $this->service()->runInBackground();

        $this->assertFalse($this->service()->isRunning());
    }

    /**
     * A movement whose data breaks the AI call — a label carrying non-UTF-8
     * bytes from a Latin-1 bank export is the real-world case — must not
     * abort the batch. AiCategorizationService only catches LlmException.
     */
    public function testOneFailingMovementDoesNotAbortTheBatch(): void
    {
        $categoryId = $this->categoryRepository->create('Fournitures');
        $this->createTransaction('Premier mouvement');
        $secondId = $this->createTransaction('Deuxième mouvement');

        $calls = 0;
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturnCallback(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \TypeError('strlen(): Argument #1 ($string) must be of type string, false given');
            }
            return new LlmResponse('{}', ['category' => 'Fournitures', 'new_category_suggestion' => null], 10, 5);
        });

        $service = $this->service($llmConnector);
        $service->setAiRuleEnabled(true);

        $result = $service->runOnUncategorized();

        $this->assertSame(2, $calls, 'The batch must reach the second movement.');
        $this->assertSame(1, $result->categorizedByAi);
        $this->assertSame(1, $result->stillUncategorized);
        $this->assertSame($categoryId, $this->transactionRepository->findById($secondId)->categoryId);
    }

}
