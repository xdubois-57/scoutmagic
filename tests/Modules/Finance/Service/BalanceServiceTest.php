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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

    /**
     * The dashboard asks three balance questions per view; the widest
     * movement read serves the narrower ones from memory. Proven by
     * deleting the rows behind the service's back: a re-read would see
     * the empty table, the memo must keep answering.
     */
    public function testTheWidestMovementReadIsReusedForNarrowerWindows(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-09-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R1', '2026-09-10', 'Achat', -100.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R2', '2026-10-10', 'Achat', -200.0, null, null, Transaction::SOURCE_MANUAL, null);

        // Wide read first (window opens at the checkpoint).
        $this->assertSame(700.0, $this->service->getBalanceAt($this->account, new \DateTimeImmutable('2026-10-31')));

        $this->pdo->exec('DELETE FROM finance_transactions');

        // Narrower window, same instance: answered from the memo.
        $this->assertSame(900.0, $this->service->getBalanceAt($this->account, new \DateTimeImmutable('2026-09-30')));
        $this->assertSame(700.0, $this->service->getLowestBalanceSince($this->account, new \DateTimeImmutable('2026-09-01')));
    }

    /**
     * The escape hatch for the rare holder that WRITES movements between
     * balance reads (Task\PurgeOldMovementsHandler): forgetAccount() must
     * drop the memo so the next read sees the database as it now is.
     */
    public function testForgetAccountMakesTheNextReadSeeFreshRows(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-09-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R1', '2026-09-10', 'Achat', -100.0, null, null, Transaction::SOURCE_MANUAL, null);

        $this->assertSame(900.0, $this->service->getBalanceAt($this->account, new \DateTimeImmutable('2026-10-31')));

        $this->pdo->exec('DELETE FROM finance_transactions');
        $this->service->forgetAccount($this->account->id);

        $this->assertSame(1000.0, $this->service->getBalanceAt($this->account, new \DateTimeImmutable('2026-10-31')));
    }

    // --- getLowestBalanceSince() ---

    public function testGetLowestBalanceSinceReturnsNullWhenNoCheckpoint(): void
    {
        $this->assertNull($this->service->getLowestBalanceSince($this->account, new \DateTimeImmutable('2026-10-01')));
    }

    public function testGetLowestBalanceSinceReturnsCheckpointBalanceWhenNoLaterTransactions(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);

        $lowest = $this->service->getLowestBalanceSince($this->account, new \DateTimeImmutable('2026-10-01'));

        $this->assertSame(1000.0, $lowest);
    }

    public function testGetLowestBalanceSinceTracksTheRunningMinimumAcrossTransactions(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R1', '2026-10-05', 'Grosse dépense', -900.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R2', '2026-10-10', 'Recette', 500.0, null, null, Transaction::SOURCE_MANUAL, null);

        $lowest = $this->service->getLowestBalanceSince($this->account, new \DateTimeImmutable('2026-10-01'));

        $this->assertSame(100.0, $lowest);
    }

    public function testGetLowestBalanceSinceIgnoresTransactionsBeforeTheWindow(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-01-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R1', '2026-02-01', 'Avant la fenêtre', -950.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R2', '2026-10-05', 'Dans la fenêtre', -20.0, null, null, Transaction::SOURCE_MANUAL, null);

        $lowest = $this->service->getLowestBalanceSince($this->account, new \DateTimeImmutable('2026-10-01'));

        $this->assertSame(30.0, $lowest);
    }

    public function testGetLowestBalanceSinceFallsBackToEarliestCheckpointWhenHistoryIsShorterThanWindow(): void
    {
        // Account only has 2 months of history — well short of the
        // 18-month window a caller might ask for — but a lowest balance
        // should still be computed from what IS on record.
        $this->checkpointRepository->create($this->account->id, '2026-08-01', 200.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R1', '2026-09-15', 'Dépense', -150.0, null, null, Transaction::SOURCE_MANUAL, null);

        $lowest = $this->service->getLowestBalanceSince($this->account, new \DateTimeImmutable('2026-01-01'));

        $this->assertSame(50.0, $lowest);
    }

    public function testGetLowestBalanceSinceStillNullWithNoCheckpointAtAll(): void
    {
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R1', '2026-10-01', 'Dépense', -20.0, null, null, Transaction::SOURCE_MANUAL, null);

        $this->assertNull($this->service->getLowestBalanceSince($this->account, new \DateTimeImmutable('2026-01-01')));
    }

    // ── getBalancesAt: the batched form must never disagree ─────────────

    /**
     * getBalancesAt() exists only to answer several dates in one pass
     * instead of one query pair each. It is worth nothing if it ever
     * answers something else, so every case below asserts it against
     * getBalanceAt() date by date rather than against hard-coded
     * figures — a future rewrite of either one then has to keep them
     * agreeing.
     *
     * @param \DateTimeImmutable[] $dates
     */
    private function assertBatchedMatchesOneByOne(array $dates): void
    {
        $batched = $this->service->getBalancesAt($this->account, $dates);

        foreach ($dates as $date) {
            $key = $date->format('Y-m-d');
            $this->assertArrayHasKey($key, $batched, "getBalancesAt() dropped {$key}");
            $this->assertSame(
                $this->service->getBalanceAt($this->account, $date),
                $batched[$key],
                "getBalancesAt() disagrees with getBalanceAt() on {$key}"
            );
        }
    }

    /**
     * @return \DateTimeImmutable[]
     */
    private function monthEndsOfTheYear(): array
    {
        $dates = [];
        foreach (range(9, 12) as $month) {
            $dates[] = new \DateTimeImmutable(sprintf('2026-%02d-01', $month));
        }
        foreach (range(1, 8) as $month) {
            $dates[] = new \DateTimeImmutable(sprintf('2027-%02d-01', $month));
        }

        return array_map(static fn(\DateTimeImmutable $d): \DateTimeImmutable => $d->modify('last day of this month'), $dates);
    }

    public function testBatchedBalancesAreAllNullWithoutAnyCheckpoint(): void
    {
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R1', '2026-10-05', 'Achat', -50.0, null, null, Transaction::SOURCE_MANUAL, null);

        $batched = $this->service->getBalancesAt($this->account, $this->monthEndsOfTheYear());

        $this->assertCount(12, $batched);
        $this->assertSame([], array_filter($batched, static fn(?float $b): bool => $b !== null));
        $this->assertBatchedMatchesOneByOne($this->monthEndsOfTheYear());
    }

    public function testBatchedBalancesMatchOneByOneAcrossAWholeYear(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-09-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $day = 0;
        foreach ([-50.0, 120.0, -30.5, 400.0, -1000.0, 75.25, -12.0, 8.0] as $index => $amount) {
            $day += 37;
            $this->transactionRepository->create(
                $this->account->id, $this->fiscalYearId, 'R' . $index,
                (new \DateTimeImmutable('2026-09-02'))->modify('+' . $day . ' days')->format('Y-m-d'),
                'Mouvement', $amount, null, null, Transaction::SOURCE_MANUAL, null
            );
        }

        $this->assertBatchedMatchesOneByOne($this->monthEndsOfTheYear());
    }

    /**
     * The case a naive "walk once from the earliest checkpoint" rewrite
     * would silently break: a checkpoint is an AUTHORITATIVE balance, so
     * a later one re-anchors the running total even when it disagrees
     * with the movements recorded since. Every month after it must be
     * seeded from IT, not from the first one.
     */
    public function testALaterCheckpointReanchorsTheMonthsAfterIt(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-09-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R1', '2026-10-05', 'Achat', -50.0, null, null, Transaction::SOURCE_MANUAL, null);
        // Deliberately inconsistent with the movement above.
        $this->checkpointRepository->create($this->account->id, '2027-01-15', 9999.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->transactionRepository->create($this->account->id, $this->fiscalYearId, 'R2', '2027-02-10', 'Achat', -99.0, null, null, Transaction::SOURCE_MANUAL, null);

        $batched = $this->service->getBalancesAt($this->account, $this->monthEndsOfTheYear());

        $this->assertSame(950.0, $batched['2026-10-31'], 'before the second checkpoint: seeded from the first');
        $this->assertSame(9999.0, $batched['2027-01-31'], 'the later checkpoint wins over the movements before it');
        $this->assertSame(9900.0, $batched['2027-02-28'], 'and the months after it start from that figure');
        $this->assertBatchedMatchesOneByOne($this->monthEndsOfTheYear());
    }

    public function testADateBeforeEveryCheckpointIsNullEvenWhenLaterOnesExist(): void
    {
        $this->checkpointRepository->create($this->account->id, '2026-12-01', 500.0, BalanceCheckpoint::SOURCE_IMPORT);

        $batched = $this->service->getBalancesAt($this->account, $this->monthEndsOfTheYear());

        $this->assertNull($batched['2026-09-30']);
        $this->assertSame(500.0, $batched['2026-12-31']);
        $this->assertBatchedMatchesOneByOne($this->monthEndsOfTheYear());
    }

    public function testAskingForNoDatesAsksTheDatabaseNothing(): void
    {
        $this->assertSame([], $this->service->getBalancesAt($this->account, []));
    }
}
