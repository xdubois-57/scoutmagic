<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ReceivableAllocationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\UsesProductionEngine;

/**
 * The allocations a treasurer writes and the ones the automatic pass
 * writes, on the engine that takes their locks (issue #1118).
 *
 * Every writer holds the receivable's row, then the movement's
 * (`SELECT … FOR UPDATE`), and reads what is left on either side again
 * under them. SQLite, the ordinary test engine, takes no `FOR UPDATE`, so
 * this class runs on the production schema (`Tests\UsesProductionEngine`)
 * and lets two connections race for one movement: the second waits on the
 * first — here, gives up after a second — and, asked again once the first
 * has committed, sees what it wrote.
 */
#[Group('database')]
final class ReceivableAllocationOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private ReceivableAllocationRepository $allocations;
    private ReceivableAllocationService $service;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);
        $this->allocations = new ReceivableAllocationRepository($this->pdo);
        $this->service = FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables);

        $this->pdo->exec(
            "INSERT INTO finance_accounts (name, account_type, status) VALUES ('Compte Unité', 'bank', 'active')"
        );
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) '
                . "VALUES ('2025-2026', '2025-09-01', '2026-08-31', 0)"
        );
        $this->fiscalYearId = (int) $this->pdo->lastInsertId();
    }

    /** The premise: on SQLite the locks below are not taken, and the race would prove nothing. */
    public function testThisEngineTakesTheLockingBranch(): void
    {
        $this->assertNotSame('sqlite', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Two treasurers give the same 60 € transfer to two receivables of
     * 45 € at the same moment. The second waits on the movement the first
     * holds; asked again once the first has committed, it is told what is
     * left. The transfer never gives away more than it carries.
     */
    public function testTwoAllocationsOfOneMovementNeverGiveAwayMoreThanItCarries(): void
    {
        $lucie = $this->receivable(4500, '+++123/4567/89012+++');
        $antoine = $this->receivable(4500, '+++123/4567/89025+++');
        $credit = $this->credit('Virement groupé', 60.00);
        [$other, $otherService] = $this->secondConnection();

        $this->pdo->beginTransaction();
        $this->service->allocate($credit, $lucie, 4500, Role::INTENDANT, 7);

        try {
            $otherService->allocate($credit, $antoine, 4500, Role::INTENDANT, 8);
            $this->fail('the second connection allocated a movement the first one holds');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('lock', strtolower($e->getMessage()));
        }
        $this->assertFalse($other->inTransaction(), 'the waiting attempt left its transaction open');

        $this->pdo->commit();

        try {
            $otherService->allocate($credit, $antoine, 4500, Role::INTENDANT, 8);
            $this->fail('the movement gave away more than it carries');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('Il ne reste que 15,00', $e->getMessage());
        }

        $this->assertSame([$lucie => 4500], $this->allocatedOn($credit));
    }

    /**
     * The automatic pass — which also runs on an anonymous page read, the
     * payment QR — and a person's gesture on the same movement: the pass
     * re-checks its write under the same locks, so it waits for the
     * gesture, then takes only what is left.
     */
    public function testTheAutomaticPassWaitsForAPersonsAllocationAndTakesOnlyWhatIsLeft(): void
    {
        $named = $this->receivable(4500, '+++123/4567/89012+++');
        $sibling = $this->receivable(4500, '+++123/4567/89025+++');
        $credit = $this->credit('Virement +++123/4567/89012+++', 60.00);
        [, $otherService] = $this->secondConnection();

        $this->pdo->beginTransaction();
        $this->service->allocate($credit, $sibling, 4500, Role::INTENDANT, 7);

        try {
            $otherService->reconcileAccount($this->accountId);
            $this->fail('the automatic pass wrote past a person holding the movement');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('lock', strtolower($e->getMessage()));
        }

        $this->pdo->commit();
        $otherService->reconcileAccount($this->accountId);

        $this->assertSame([$named => 1500, $sibling => 4500], $this->allocatedOn($credit));
    }

    /** « Répartir » on the real engine: a refused line rolls back the lines InnoDB had already written. */
    public function testARefusedSplitLineRollsBackTheOthersOnTheRealEngine(): void
    {
        $lucie = $this->receivable(4500, '+++123/4567/89012+++');
        $antoine = $this->receivable(3000, '+++123/4567/89025+++');
        $credit = $this->credit('Virement groupé', 90.00);

        try {
            $this->service->split($credit, [$lucie => 4500, $antoine => 4500], Role::INTENDANT, 7);
            $this->fail('a split with a refused line was accepted');
        } catch (FinanceException $e) {
            $this->assertStringStartsWith("Rien n'a été réparti.", $e->getMessage());
        }

        $this->assertSame([], $this->allocatedOn($credit));
        $this->assertFalse($this->pdo->inTransaction());

        $this->service->split($credit, [$lucie => 4500, $antoine => 3000], Role::INTENDANT, 7);
        $this->assertSame([$lucie => 4500, $antoine => 3000], $this->allocatedOn($credit));
        $this->assertFalse($this->pdo->inTransaction());
    }

    /**
     * A second connection on the same database, waiting at most a second
     * for a lock, with its own allocation service.
     *
     * @return array{\PDO, ReceivableAllocationService}
     */
    private function secondConnection(): array
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = self::productionEngineConnection($database)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        return [$other, FinanceTestHelper::allocationService($other, $this->encryption)];
    }

    /** @return array<int, int> receivable id => cents, the positive allocations of one movement */
    private function allocatedOn(int $transactionId): array
    {
        $allocated = [];
        foreach ($this->allocations->findByTransactionId($transactionId) as $allocation) {
            if ($allocation->amountCents > 0) {
                $allocated[$allocation->receivableId] = $allocation->amountCents;
            }
        }
        ksort($allocated);

        return $allocated;
    }

    private function receivable(int $amountCents, string $communication): int
    {
        return $this->receivables->create('finance', 1, $this->accountId, $amountCents, $communication, null);
    }

    private function credit(string $label, float $amount): int
    {
        return $this->transactions->create(
            $this->accountId,
            $this->fiscalYearId,
            null,
            '2026-02-18',
            $label,
            $amount,
            null,
            null,
            'import',
            null
        );
    }
}
