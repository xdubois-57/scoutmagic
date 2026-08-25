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
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Task\ReconcileReceivablesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReconcileReceivablesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $this->accountId = $accountRepository->create('Compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');
    }

    /**
     * The case the task exists for: movements and receivables that both
     * predate the allocation model, with nothing written down between
     * them. One nightly run matches the whole history.
     */
    public function testItMatchesAHistoryThatPredatesTheAllocationModel(): void
    {
        $receivableId = (new ExpectedReceivableRepository($this->pdo, $this->encryption))
            ->create('finance', 1, $this->accountId, 4500, '+++123/4567/89012+++', null);
        (new TransactionRepository($this->pdo, $this->encryption))->create(
            $this->accountId, $this->fiscalYearId, 'REF-1', '2026-02-18',
            'Virement +++123/4567/89012+++', 45.00, null, null, Transaction::SOURCE_IMPORT, null
        );

        (new ReconcileReceivablesHandler())->handle([], $this->taskContext());

        $allocations = (new ReceivableAllocationRepository($this->pdo))->findByReceivableId($receivableId);
        $this->assertCount(1, $allocations);
        $this->assertSame(4500, $allocations[0]->amountCents);
    }

    public function testASecondRunWritesNothingMore(): void
    {
        (new ExpectedReceivableRepository($this->pdo, $this->encryption))
            ->create('finance', 1, $this->accountId, 4500, '+++123/4567/89012+++', null);
        (new TransactionRepository($this->pdo, $this->encryption))->create(
            $this->accountId, $this->fiscalYearId, 'REF-1', '2026-02-18',
            'Virement +++123/4567/89012+++', 45.00, null, null, Transaction::SOURCE_IMPORT, null
        );

        (new ReconcileReceivablesHandler())->handle([], $this->taskContext());
        (new ReconcileReceivablesHandler())->handle([], $this->taskContext());

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_receivable_allocations')->fetchColumn());
    }

    public function testItSchedulesItsNextRun(): void
    {
        (new ReconcileReceivablesHandler())->handle([], $this->taskContext());

        $scheduled = (new SchedulerService(new SchedulerRepository($this->pdo)))
            ->find('finance', 'reconcile_receivables', 'nightly');

        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    public function testItDoesNotPileUpASecondPendingRun(): void
    {
        (new ReconcileReceivablesHandler())->handle([], $this->taskContext());
        (new ReconcileReceivablesHandler())->handle([], $this->taskContext());

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'reconcile_receivables' AND status = 'pending'"
        )->fetchColumn();

        $this->assertSame(1, $count);
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createMock(UserAccountRepository::class),
            sys_get_temp_dir()
        );
    }
}
