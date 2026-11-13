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

    /**
     * The transaction a lock opens reads what is committed, not the
     * snapshot its first read took (issue #1118). Under the engine's
     * default, a writer that waited on a movement's lock would resume
     * still counting the allocations as they were before it waited — the
     * very write it waited for missing — and give the movement away twice.
     */
    public function testALockedTransactionSeesWhatAnotherCommittedAfterItsFirstRead(): void
    {
        $lucie = $this->receivable(4500, '+++123/4567/89012+++');
        $antoine = $this->receivable(4500, '+++123/4567/89025+++');
        $credit = $this->credit('Virement groupé', 60.00);
        [, $otherService] = $this->secondConnection();

        $seen = $this->receivables->withReceivableLocked(
            $lucie,
            function () use ($credit, $antoine, $otherService): int {
                // The first plain read: under REPEATABLE READ, the snapshot.
                $this->assertSame([], $this->allocations->findByTransactionId($credit));

                $otherService->allocate($credit, $antoine, 4500, Role::INTENDANT, 8);

                return count($this->allocations->findByTransactionId($credit));
            }
        );

        $this->assertSame(1, $seen, "the second read did not see the other connection's committed allocation");
    }

    /**
     * A transaction somebody else opened — a campaign import, a module
     * creating its receivable in its own TransactionScope — stays at
     * REPEATABLE READ, and a lock joining it does not refresh its
     * snapshot. Its reads under the lock are then locking reads (issue
     * #1118): the allocation another connection committed after the
     * snapshot is counted, and the movement is not given away twice.
     */
    public function testAnAllocationInsideAnOuterTransactionCountsWhatWasCommittedSinceItsSnapshot(): void
    {
        $lucie = $this->receivable(4500, '+++123/4567/89012+++');
        $antoine = $this->receivable(4500, '+++123/4567/89025+++');
        $credit = $this->credit('Virement groupé', 60.00);
        [, $otherService] = $this->secondConnection();

        $this->pdo->beginTransaction();
        // The outer transaction's first plain read: its snapshot.
        $this->assertSame([], $this->allocatedOn($credit));

        $otherService->allocate($credit, $antoine, 4500, Role::INTENDANT, 8);

        try {
            $this->service->allocate($credit, $lucie, 4500, Role::INTENDANT, 7);
            $this->fail('the outer transaction gave the movement away twice');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('Il ne reste que 15,00', $e->getMessage());
        } finally {
            $this->pdo->rollBack();
        }

        $this->assertSame([$antoine => 4500], $this->allocatedOn($credit));
    }

    /**
     * The amount due is read under the lock the same way: an outer
     * transaction that saw the receivable at 45 € before somebody lowered
     * it to 30 € does not fill it to 45 € (issue #1118).
     */
    public function testAnAllocationInsideAnOuterTransactionReadsTheAmountDueAsItIsNow(): void
    {
        $lucie = $this->receivable(4500, '+++123/4567/89012+++');
        $credit = $this->credit('Virement groupé', 60.00);
        $other = $this->secondConnection()[0];

        $this->pdo->beginTransaction();
        // The outer transaction's snapshot holds the receivable at 45 €.
        $this->assertSame(4500, $this->receivables->findById($lucie)?->amountDueCents);

        (new ExpectedReceivableRepository($other, $this->encryption))->updateAmount($lucie, 3000);

        try {
            $this->service->allocate($credit, $lucie, 4500, Role::INTENDANT, 7);
            $this->fail('the allocation filled the receivable to an amount it no longer has');
        } catch (FinanceException $e) {
            $this->assertStringContainsString("n'attend plus que 30,00", $e->getMessage());
        } finally {
            $this->pdo->rollBack();
        }

        $this->assertSame([], $this->allocatedOn($credit));
    }

    /**
     * The sequence itself, in two processes: another treasurer holds the
     * movement while giving its last 15 € away; the surplus transfer
     * read 15 € on it, waits on its lock, resumes once the other has
     * committed — and finds nothing left, rather than giving the same
     * 15 € a second time.
     */
    public function testATransferThatWaitedOnAMovementResumesWithWhatTheOtherCommitted(): void
    {
        $from = $this->receivable(4500, '+++123/4567/89012+++');
        $third = $this->receivable(5000, '+++123/4567/89038+++');
        $to = $this->receivable(5000, '+++123/4567/89025+++');
        $credit = $this->credit('Virement +++123/4567/89012+++', 60.00);
        $this->service->reconcileAccount($this->accountId);
        $this->assertSame([$from => 4500], $this->allocatedOn($credit));

        $other = $this->holdingProcess($credit, $third, 1500);
        try {
            $this->service->transferOverpayment($from, $to, 1500, Role::INTENDANT, 7);
            $this->fail('the transfer gave away the 15 € the other treasurer had just taken');
        } catch (FinanceException $e) {
            $this->assertStringContainsString("n'a pas pu être imputé en entier", $e->getMessage());
        } finally {
            $this->assertSame("COMMITTED\n", stream_get_contents($other['out']));
            proc_close($other['process']);
        }

        $this->assertSame([$from => 4500, $third => 1500], $this->allocatedOn($credit));
    }

    /**
     * A second PHP process allocating $cents of $credit to $receivable in
     * a transaction it keeps open for a second and a half after taking the
     * locks — returned once it has them, so the caller's next gesture is
     * the one that waits.
     *
     * @return array{process: resource, out: resource}
     */
    private function holdingProcess(int $credit, int $receivable, int $cents): array
    {
        $credentials = self::productionEngineCredentials();
        $code = <<<'PHP'
            require 'vendor/autoload.php';
            [, $host, $port, $database, $user, $password, $credit, $receivable, $cents] = $argv;
            $pdo = (new Core\Database\Connection($host, (int) $port, $database, $user, $password))->getPdo();
            $encryption = new Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
            $service = Tests\Modules\Finance\FinanceTestHelper::allocationService($pdo, $encryption);
            $pdo->beginTransaction();
            $service->allocate((int) $credit, (int) $receivable, (int) $cents, Core\Security\Role::INTENDANT, 9);
            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);
            usleep(1500000);
            $pdo->commit();
            fwrite(STDOUT, "COMMITTED\n");
            PHP;

        $process = proc_open(
            [
                PHP_BINARY, '-r', $code, '--',
                $credentials['host'], (string) $credentials['port'],
                (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn(),
                $credentials['user'], $credentials['password'],
                (string) $credit, (string) $receivable, (string) $cents,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4)
        );
        $this->assertIsResource($process);

        $line = fgets($pipes[1]);
        if ($line !== "LOCKED\n") {
            $this->fail('the second process did not take its locks: ' . $line . stream_get_contents($pipes[2]));
        }

        return ['process' => $process, 'out' => $pipes[1]];
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
