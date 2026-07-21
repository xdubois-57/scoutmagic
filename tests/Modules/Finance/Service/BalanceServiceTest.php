<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\BalanceCheckpoint;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class BalanceServiceTest extends TestCase
{
    private \PDO $pdo;
    private BalanceService $service;
    private BalanceCheckpointRepository $checkpointRepository;
    private TransactionRepository $transactionRepository;
    private Account $account;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->service = new BalanceService($this->checkpointRepository, $this->transactionRepository);

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $accountId = (int) $this->pdo->lastInsertId();
        $this->account = new Account($accountId, 'Compte', Account::TYPE_BANK, null, null, null, 'intendant', Account::STATUS_ACTIVE);

        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    public function testReturnsNullWhenNoCheckpointExists(): void
    {
        $this->assertNull($this->service->getBalanceAt($this->account, new \DateTimeImmutable('2026-10-15')));
    }

    public function testReturnsCheckpointBalanceWhenNoLaterTransactions(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);

        $balance = $this->service->getBalanceAt($this->account, new \DateTimeImmutable('2026-10-01'));
        $this->assertSame(1000.0, $balance);
    }

    public function testAddsTransactionsAfterCheckpointUpToRequestedDate(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R1', '2026-10-05', 'Achat', -50.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R2', '2026-10-20', 'Après la date demandée', -999.0, null, null, Transaction::SOURCE_MANUAL, null);

        $balance = $this->service->getBalanceAt($this->account, new \DateTimeImmutable('2026-10-10'));
        $this->assertSame(950.0, $balance);
    }

    public function testUsesMostRecentCheckpointWhenMultipleExist(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-09-01', 500.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->checkpointRepository->create($this->account->id, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R1', '2026-10-15', 'Achat', -100.0, null, null, Transaction::SOURCE_MANUAL, null);

        $balance = $this->service->getBalanceAt($this->account, new \DateTimeImmutable('2026-10-31'));
        $this->assertSame(900.0, $balance);
    }
}
