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

    /**
     * @param (\Closure(): float)|null $clock
     */
    private function service(?LlmConnectorInterface $llmConnector = null, ?\Closure $clock = null): BulkCategorizationService
    {
        $ruleEngine = new CategoryRuleEngine($this->transactionRepository, $this->categoryRuleRepository);
        $aiService = new AiCategorizationService(
            $llmConnector, $this->categoryRepository, new AiCategorySuggestionRepository($this->pdo),
            new JournalService(new JournalRepository($this->pdo))
        );
        return new BulkCategorizationService(
            $this->transactionRepository, $ruleEngine, $aiService, $this->settingService,
            $this->scheduler(), null, $clock
        );
    }

    private function scheduler(): SchedulerService
    {
        return new SchedulerService(new SchedulerRepository($this->pdo));
    }

    /**
     * The run's queued continuation, or null.
     *
     * @return array<string, mixed>|null
     */
    private function pendingBatch(): ?array
    {
        foreach ($this->scheduler()->findAllForTask('finance', 'run_categorization_rules') as $row) {
            if ($row['status'] === 'pending') {
                return $row;
            }
        }

        return null;
    }

    /**
     * What the scheduler would hand the handler for the queued batch.
     *
     * @return array<string, mixed>
     */
    private function pendingPayload(): array
    {
        $row = $this->pendingBatch();
        $this->assertNotNull($row, 'a run must always have its next batch queued');

        return json_decode((string) $row['payload'], true);
    }

    /**
     * What Core\Scheduler\SchedulerRunner does with the queued batch:
     * claims its row (`processing`), runs it, and marks it done. A batch
     * that throws leaves its row `processing`, as a killed process does —
     * which is exactly when only a continuation armed BEFORE the work can
     * carry the run on.
     */
    private function runQueuedBatch(BulkCategorizationService $service): void
    {
        $row = $this->pendingBatch();
        $this->assertNotNull($row, 'a run must always have its next batch queued');
        $this->pdo->prepare("UPDATE scheduled_actions SET status = 'processing' WHERE id = ?")->execute([$row['id']]);

        $service->runBatch(json_decode((string) $row['payload'], true));

        $this->pdo->prepare("UPDATE scheduled_actions SET status = 'done' WHERE id = ?")->execute([$row['id']]);
    }

    /**
     * A stub whose every answer is « Fournitures », counting its calls.
     */
    private function countingLlm(int &$calls): LlmConnectorInterface
    {
        $llmConnector = $this->createStub(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturnCallback(function () use (&$calls) {
            $calls++;
            return new LlmResponse('{}', ['category' => 'Fournitures', 'new_category_suggestion' => null], 10, 5);
        });

        return $llmConnector;
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

        $llmConnector = $this->createStub(LlmConnectorInterface::class);
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

    public function testGetLastResultNullBeforeAnyRun(): void
    {
        $this->assertNull($this->service()->getLastResult());
    }

    public function testAScheduledRunQueuesItsFirstBatchAndReadsAsRunning(): void
    {
        $this->createTransaction('Achat');

        $service = $this->service();
        $this->assertTrue($service->scheduleBackgroundRun());

        $this->assertTrue($this->service()->isRunning());
        $this->assertArrayHasKey('run_id', $this->pendingPayload());
        $this->assertSame(1, $this->service()->status()['target']);
    }

    /**
     * Two clicks a moment apart: the second finds the first one's run and
     * stands down — one run, one queued batch.
     */
    public function testASecondStartWhileARunIsUnderWayIsRefused(): void
    {
        $this->assertTrue($this->service()->scheduleBackgroundRun());
        $this->assertFalse($this->service()->scheduleBackgroundRun());

        $this->assertCount(1, $this->scheduler()->findAllForTask('finance', 'run_categorization_rules'));
    }

    /**
     * Issue #839's core: a run is walked in short batches, each a separate
     * task, and the counts add up across them.
     */
    public function testAHundredAndTwentyMovementsAreWalkedInSeveralBatchesWithCumulatedCounts(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);
        for ($i = 0; $i < 120; $i++) {
            $this->createTransaction($i % 3 === 0 ? 'VIR Delhaize ' . $i : 'Achat mystère ' . $i);
        }

        $this->assertTrue($this->service()->scheduleBackgroundRun());
        $batches = 0;
        while ($this->service()->isRunning()) {
            $this->runQueuedBatch($this->service());
            $batches++;
            $this->assertLessThan(20, $batches, 'the run must end');
        }

        $this->assertSame((int) ceil(120 / BulkCategorizationService::BATCH_SIZE), $batches);
        $this->assertSame(
            [
                'categorized_by_rules' => 40, 'categorized_by_ai' => 0, 'still_uncategorized' => 80,
                'abandoned' => false, 'processed' => 120, 'target' => 120,
            ],
            $this->service()->getLastResult()
        );
        $this->assertNull($this->pendingBatch(), 'a finished run leaves nothing queued');
    }

    /**
     * A movement neither the rules nor the AI can place stays uncategorized
     * — and must not be walked again, by this batch or the next.
     */
    public function testTheCursorNeverRevisitsAMovementItCouldNotPlace(): void
    {
        for ($i = 0; $i < BulkCategorizationService::BATCH_SIZE + 5; $i++) {
            $this->createTransaction('Achat mystère ' . $i);
        }
        $calls = 0;
        $llmConnector = $this->createStub(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturnCallback(function () use (&$calls) {
            $calls++;
            return new LlmResponse('{}', ['category' => null, 'new_category_suggestion' => null], 10, 5);
        });
        $service = $this->service($llmConnector);
        $service->setAiRuleEnabled(true);

        $result = $service->runOnUncategorized();

        $this->assertSame(BulkCategorizationService::BATCH_SIZE + 5, $calls, 'one call per movement, never two');
        $this->assertSame(BulkCategorizationService::BATCH_SIZE + 5, $result->stillUncategorized);
    }

    /**
     * The bound is frozen at the start: a statement imported mid-run does
     * not stretch the run, and is left for the next one.
     */
    public function testAMovementImportedMidRunIsLeftForTheNextRun(): void
    {
        $this->createTransaction('Avant');
        $this->assertTrue($this->service()->scheduleBackgroundRun());
        $later = $this->createTransaction('Arrivé pendant le run');

        $this->runQueuedBatch($this->service());

        $this->assertFalse($this->service()->isRunning());
        $this->assertSame(1, $this->service()->getLastResult()['still_uncategorized']);

        // The next run reaches it: both movements are still uncategorized.
        $this->assertTrue($this->service()->scheduleBackgroundRun());
        $this->assertSame(2, $this->service()->status()['target']);
        $this->assertSame($later, $this->transactionRepository->findMaxUncategorizedId());
    }

    /**
     * A batch that stops part-way — its time budget spent, or its process
     * killed after the run was written — leaves its continuation queued and
     * its cursor durable: the next batch resumes from there, and no
     * movement is asked about twice.
     */
    public function testABatchCutShortIsResumedFromTheDurableCursorByItsQueuedContinuation(): void
    {
        $this->categoryRepository->create('Fournitures');
        for ($i = 0; $i < 10; $i++) {
            $this->createTransaction('Achat ' . $i);
        }
        $calls = 0;
        $now = 1_000_000.0;
        // Every reading of the clock moves it 25 s on: the 60 s budget is
        // spent after the third movement.
        $clock = function () use (&$now): float {
            $now += 25.0;

            return $now;
        };
        $service = $this->service($this->countingLlm($calls), $clock);
        $service->setAiRuleEnabled(true);
        $this->assertTrue($service->scheduleBackgroundRun());

        $this->runQueuedBatch($service);

        $this->assertTrue($service->isRunning());
        $status = $service->status();
        $this->assertGreaterThan(0, $status['processed']);
        $this->assertLessThan(10, $status['processed']);
        $this->assertSame($status['processed'], $calls);

        while ($service->isRunning()) {
            $this->runQueuedBatch($service);
        }

        $this->assertSame(10, $calls, 'every movement asked exactly once across the batches');
        $this->assertSame(10, $service->getLastResult()['categorized_by_ai']);
    }

    /**
     * A process killed in the middle of a batch — simulated by the clock
     * blowing up while the second movement is being recorded. Everything
     * written before that point holds: the next batch, already queued,
     * resumes after the first movement, and nothing is asked about twice
     * (the movement in flight had already been categorized when the
     * process died, so it is no longer among the uncategorized).
     */
    public function testABatchKilledMidWayLosesOnlyTheMovementInFlight(): void
    {
        $this->categoryRepository->create('Fournitures');
        for ($i = 0; $i < 10; $i++) {
            $this->createTransaction('Achat ' . $i);
        }
        $calls = 0;
        $readings = 0;
        // 1: the batch's deadline; 2: the first movement recorded; 3: the
        // budget check before the second; 4: the second movement recorded.
        $dyingClock = function () use (&$readings): float {
            if (++$readings === 4) {
                throw new \RuntimeException('killed');
            }

            return microtime(true);
        };
        $llmConnector = $this->countingLlm($calls);
        $dying = $this->service($llmConnector, $dyingClock);
        $dying->setAiRuleEnabled(true);
        $this->assertTrue($this->service($llmConnector)->scheduleBackgroundRun());

        try {
            $this->runQueuedBatch($dying);
            $this->fail('the batch was meant to die');
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, $this->service()->status()['processed']);
        $service = $this->service($llmConnector);
        while ($service->isRunning()) {
            $this->runQueuedBatch($service);
        }

        $this->assertSame(10, $calls, 'ten movements, each asked once');
        $this->assertSame(0, $this->transactionRepository->findMaxUncategorizedId(), 'all ten are categorized');
        // The one in flight was categorized and then the process died
        // before counting it: the count is one short, the data is not.
        $this->assertSame(9, $service->getLastResult()['categorized_by_ai']);
    }

    /**
     * The window #839's continuation does not cover: a run said to be
     * running with nothing queued and nothing written for minutes. Asking
     * for the status — what the config page does every few seconds — puts
     * it back on its feet, without anybody clicking again.
     */
    public function testARunNothingCarriesAnyMoreIsResumedWhenItsStatusIsAsked(): void
    {
        $this->createTransaction('Achat');
        $now = 1_000_000.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $this->assertTrue($this->service(null, $clock)->scheduleBackgroundRun());
        $this->scheduler()->cancelPending('finance', 'run_categorization_rules', 'run:' . $this->pendingPayload()['run_id']);

        $this->assertFalse($this->service(null, $clock)->status()['resumed'], 'too early to call it dead');

        $now += 200;
        $status = $this->service(null, $clock)->status();

        $this->assertTrue($status['resumed']);
        $this->assertTrue($status['running']);
        $this->service(null, $clock)->runBatch($this->pendingPayload());
        $this->assertFalse($this->service(null, $clock)->isRunning());
    }

    /**
     * A run that makes no progress at all is not resumed for ever: after
     * half an hour it is given up, what it did is kept, and the button is
     * free — never the hour, let alone the scheduler's six.
     */
    public function testARunThatMakesNoProgressForHalfAnHourIsGivenUp(): void
    {
        $this->createTransaction('Achat');
        $now = 1_000_000.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $this->assertTrue($this->service(null, $clock)->scheduleBackgroundRun());

        $now += 1800;

        $this->assertFalse($this->service(null, $clock)->status()['running']);
        $this->assertNull($this->pendingBatch());
        $this->assertNotNull($this->service(null, $clock)->getLastResult());
        $this->assertTrue($this->service(null, $clock)->scheduleBackgroundRun());
    }

    /**
     * The same net on the path no human watches. A batch that fails before
     * its first movement every time — the database refusing it, say — is
     * re-armed by its own early continuation; without a count, the
     * scheduler would carry that run for ever and the button would stay
     * disabled with nobody polling the page.
     */
    public function testBatchesThatKeepFailingBeforeTheirFirstMovementEndTheRun(): void
    {
        $this->createTransaction('Achat');
        $this->assertTrue($this->service()->scheduleBackgroundRun());
        $failing = $this->service(null, static function (): float {
            throw new \RuntimeException('the database refused the batch');
        });

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $this->runQueuedBatch($failing);
                $this->fail('the batch was meant to fail');
            } catch (\RuntimeException) {
            }
            $this->assertTrue($this->service()->isRunning(), 'attempt ' . $attempt . ' still leaves room to recover');
        }

        $this->runQueuedBatch($this->service());

        $this->assertFalse($this->service()->isRunning());
        $this->assertNull($this->pendingBatch());
        $this->assertTrue($this->service()->getLastResult()['abandoned']);
        $this->assertTrue($this->service()->scheduleBackgroundRun(), 'the button is free again');
    }

    /**
     * One movement done is progress: the count starts over, so a run that
     * is slow but moving is never given up by it.
     */
    public function testABatchThatMovesResetsTheStallCount(): void
    {
        $this->categoryRepository->create('Fournitures');
        for ($i = 0; $i < BulkCategorizationService::BATCH_SIZE * 3; $i++) {
            $this->createTransaction('Achat ' . $i);
        }
        $this->assertTrue($this->service()->scheduleBackgroundRun());
        $failing = $this->service(null, static function (): float {
            throw new \RuntimeException('the database refused the batch');
        });

        for ($round = 0; $round < 2; $round++) {
            for ($attempt = 1; $attempt <= 4; $attempt++) {
                try {
                    $this->runQueuedBatch($failing);
                } catch (\RuntimeException) {
                }
            }
            $this->runQueuedBatch($this->service());
            $this->assertTrue($this->service()->isRunning(), 'round ' . $round . ' moved, so the run goes on');
        }
    }

    /**
     * Its counters cover only what it walked before giving up, so the
     * result says so — the page must not call it « terminée ».
     */
    public function testAnAbandonedRunSaysSoInItsResult(): void
    {
        $this->createTransaction('Achat');
        $now = 1_000_000.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $this->assertTrue($this->service(null, $clock)->scheduleBackgroundRun());
        $now += 1800;
        $this->service(null, $clock)->status();

        $result = $this->service(null, $clock)->getLastResult();
        $this->assertTrue($result['abandoned']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['target']);
    }

    public function testACompletedRunIsNotReportedAbandoned(): void
    {
        $this->createTransaction('Achat');

        $this->service()->runOnUncategorized();

        $result = $this->service()->getLastResult();
        $this->assertFalse($result['abandoned']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['target']);
    }

    /**
     * A leftover batch — from a run that already ended, or another run —
     * does nothing.
     */
    public function testABatchForARunThatIsNoLongerCurrentDoesNothing(): void
    {
        $id = $this->createTransaction('VIR Delhaize');
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->assertTrue($this->service()->scheduleBackgroundRun());
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);

        $this->service()->runBatch(['run_id' => 'not-this-one']);

        $this->assertNull($this->transactionRepository->findById($id)->categoryId);
        $this->assertTrue($this->service()->isRunning());
    }

    public function testAFinishedRunFreesTheButtonAtOnceEvenWithNothingToDo(): void
    {
        $this->assertTrue($this->service()->scheduleBackgroundRun());

        $this->runQueuedBatch($this->service());

        $this->assertFalse($this->service()->isRunning());
        $this->assertSame(
            [
                'categorized_by_rules' => 0, 'categorized_by_ai' => 0, 'still_uncategorized' => 0,
                'abandoned' => false, 'processed' => 0, 'target' => 0,
            ],
            $this->service()->getLastResult()
        );
    }

    /**
     * A row queued by a version from before runs existed carries no run_id;
     * it starts a run instead of being dropped.
     */
    public function testABatchWithoutARunIdStartsARun(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);
        $id = $this->createTransaction('VIR Delhaize');

        $this->service()->runBatch([]);

        $this->assertSame($categoryId, $this->transactionRepository->findById($id)->categoryId);
        $this->assertFalse($this->service()->isRunning());
    }

    /**
     * The markers a run used to be — a timestamp under one key, a boolean
     * under the other, typed so on the sites that have them — neither
     * block a run nor survive one.
     */
    public function testTheOldMarkersNeitherBlockARunNorOutliveIt(): void
    {
        $this->settingService->register('bulk_categorization_running', '0', 'boolean', 'Ancien indicateur', 'x', 'finance', null, null, false);
        $this->settingService->setInternal('bulk_categorization_running', '1', 'finance');
        $this->settingService->register('bulk_categorization_started_at', '0', 'number', 'Ancien indicateur', 'x', 'finance', null, null, false);
        $this->settingService->setInternal('bulk_categorization_started_at', (string) time(), 'finance');

        $this->assertFalse($this->service()->isRunning());
        $this->assertTrue($this->service()->scheduleBackgroundRun());

        $this->assertSame('0', $this->settingService->get('bulk_categorization_running', 'finance'));
        $this->assertSame('0', $this->settingService->get('bulk_categorization_started_at', 'finance'));
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
        $llmConnector = $this->createStub(LlmConnectorInterface::class);
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
