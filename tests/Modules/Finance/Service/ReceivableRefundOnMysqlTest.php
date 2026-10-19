<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\StructuredCommunicationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\UsesProductionEngine;

/**
 * The refund a person points at (chantier #927, §2.6), on the engine that
 * takes its locks.
 *
 * `ExpectedReceivableInterface::reconcileRefund()` re-checks a candidate
 * while holding the receivable's row and then the movement's
 * (`ExpectedReceivableRepository::withReceivableLocked()`,
 * `TransactionRepository::lockForUpdate()`). SQLite, the ordinary test
 * engine, takes neither `FOR UPDATE` — so this class runs on the
 * production schema (`Tests\UsesProductionEngine`), watches a second
 * session wait on each row, and replays the candidate search, whose
 * filters are a DECIMAL and a DATE compared by the server.
 */
#[Group('database')]
final class ReceivableRefundOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private ExpectedReceivableService $api;
    private int $accountId;
    private int $fiscalYearId;
    private int $receivableId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $encryption);
        $this->transactions = new TransactionRepository($this->pdo, $encryption);
        $this->api = FinanceTestHelper::receivableService($this->pdo, $encryption, $this->receivables);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Locations', 'bank', 'active')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO finance_categories (name) VALUES ('Locations')");
        $categoryId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)"
        );
        $this->fiscalYearId = (int) $this->pdo->lastInsertId();

        $this->receivableId = $this->receivables->create(
            'rental',
            1,
            $this->accountId,
            62200,
            StructuredCommunicationService::format('0909337554'),
            null,
            null,
            $categoryId
        );
        $this->movement('2027-03-08', 818.00, 'Virement ' . StructuredCommunicationService::format('0909337554'));
    }

    /** The premise: were this SQLite, the locks below would not be taken and the tests would prove nothing. */
    public function testThisEngineTakesTheLockingBranch(): void
    {
        $this->assertNotSame('sqlite', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testASecondSessionWaitsWhileTheReceivableIsHeld(): void
    {
        $blocked = $this->receivables->withReceivableLocked(
            $this->receivableId,
            fn (): bool => $this->secondSessionIsBlockedOn('finance_expected_receivables', $this->receivableId)
        );

        $this->assertTrue($blocked, 'two refund decisions on one receivable can still each fit under its surplus');
    }

    public function testASecondSessionWaitsWhileTheMovementIsHeld(): void
    {
        $debit = $this->movement('2027-03-20', -196.00, 'Virement');

        $blocked = $this->receivables->withReceivableLocked($this->receivableId, function () use ($debit): bool {
            $this->transactions->lockForUpdate($debit);

            return $this->secondSessionIsBlockedOn('finance_transactions', $debit);
        });

        $this->assertTrue($blocked, 'one debit can still become the refund of two receivables at once');
    }

    public function testWhatTheWorkWroteIsRolledBackWhenItThrows(): void
    {
        try {
            $this->receivables->withReceivableLocked($this->receivableId, function (): void {
                $this->receivables->updateAmount($this->receivableId, 1);
                throw new \RuntimeException('stop');
            });
        } catch (\RuntimeException) {
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(62200, $this->receivables->findById($this->receivableId)?->amountDueCents);
    }

    /** The whole path on the real engine: candidates filtered by the server, then one reconciled and gone. */
    public function testACandidateIsFoundAndReconciledOnce(): void
    {
        $this->movement('2027-03-17', -10.00, 'Avant');
        $this->movement('2027-03-21', -196.01, 'Trop grand');
        $debit = $this->movement('2027-03-20', -196.00, 'Virement', 'J. LAMBERT');
        $since = new \DateTimeImmutable('2027-03-18');

        $candidates = $this->api->findRefundCandidates('rental', $this->receivableId, $since);
        $this->assertSame([$debit], array_map(static fn ($c): int => $c->transactionId, $candidates));
        $this->assertSame(19600, $candidates[0]->amountCents);

        $this->api->reconcileRefund('rental', $this->receivableId, $debit, $since, null);

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(19600, $this->api->getReceivableStanding('rental', $this->receivableId)?->amountRefundedCents);
        $this->expectException(FinanceException::class);
        $this->api->reconcileRefund('rental', $this->receivableId, $debit, $since, null);
    }

    private function secondSessionIsBlockedOn(string $table, int $id): bool
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = self::productionEngineConnection($database)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $other->beginTransaction();
            $probe = $other->prepare("SELECT id FROM {$table} WHERE id = ? FOR UPDATE");
            $probe->execute([$id]);
            $probe->fetchAll();

            return false;
        } catch (\PDOException $e) {
            return str_contains(strtolower($e->getMessage()), 'lock');
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
    }

    private function movement(string $date, float $amount, string $label, ?string $counterpartyName = null): int
    {
        return $this->transactions->create(
            $this->accountId,
            $this->fiscalYearId,
            null,
            $date,
            $label,
            $amount,
            null,
            null,
            'import',
            null,
            $counterpartyName
        );
    }
}
