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
 * `ExpectedReceivableInterface::deleteReceivable()` (issue #1525), on the
 * engine whose snapshots it has to see past.
 *
 * The news module calls it inside its own edit transaction — REPEATABLE
 * READ, opened by somebody other than finance — whose snapshot dates from
 * its first read. A payment another session attaches and commits after
 * that read is invisible to plain reads, and the receivable lock does not
 * refresh the snapshot: only the locking read of the allocations
 * (`ReceivableAllocationService::holdsCommittedMoney()`) sees it. SQLite
 * has neither snapshots nor that clause, so this runs on the production
 * schema.
 */
#[Group('database')]
final class ReceivableDeletionOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private ExpectedReceivableRepository $receivables;
    private ExpectedReceivableService $api;
    private int $accountId;
    private int $fiscalYearId;
    private int $receivableId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $encryption);
        $this->api = FinanceTestHelper::receivableService($this->pdo, $encryption, $this->receivables);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Unité', 'bank', 'active')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO finance_categories (name) VALUES ('Soupers')");
        $categoryId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)"
        );
        $this->fiscalYearId = (int) $this->pdo->lastInsertId();

        $this->receivableId = $this->receivables->create(
            'news', 1, $this->accountId, 3600, StructuredCommunicationService::format('0909337554'), null, null, $categoryId
        );
    }

    /** The premise: were this SQLite, there would be no snapshot to see past. */
    public function testThisEngineTakesTheLockingBranch(): void
    {
        $this->assertNotSame('sqlite', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testAPaymentCommittedAfterTheCallersSnapshotStillRefusesTheDeletion(): void
    {
        // The caller's transaction, as the news edit opens it, and its
        // first read: from here on its plain reads see this moment.
        $this->pdo->beginTransaction();
        $this->pdo->query('SELECT COUNT(*) FROM finance_receivable_allocations')->fetchColumn();

        // Another session imports the payment and allocates it.
        $this->commitPaymentFromAnotherSession(3600);

        // The premise of the fix: the snapshot really does not see it.
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM finance_receivable_allocations')->fetchColumn(),
            'this engine shows the caller a snapshot, which is what the locking read must see past'
        );

        try {
            $this->api->deleteReceivable('news', $this->receivableId);
            $this->fail('A receivable paid by a committed payment must not be deleted.');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('déjà reçu un paiement', $e->getMessage());
        } finally {
            $this->pdo->rollBack();
        }

        $this->assertNotNull($this->receivables->findById($this->receivableId));
    }

    public function testAnUnpaidReceivableIsDeletedInsideTheCallersTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->api->deleteReceivable('news', $this->receivableId);
        $this->pdo->commit();

        $this->assertNull($this->receivables->findById($this->receivableId));
    }

    private function commitPaymentFromAnotherSession(int $cents): void
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = self::productionEngineConnection($database)->getPdo();
        $transactions = new TransactionRepository($other, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        $other->beginTransaction();
        $transactionId = $transactions->create(
            $this->accountId, $this->fiscalYearId, null, '2026-10-01',
            'Virement ' . StructuredCommunicationService::format('0909337554'), $cents / 100, null, null, 'import', null
        );
        $other->prepare(
            "INSERT INTO finance_receivable_allocations (transaction_id, receivable_id, amount_cents, source) VALUES (?, ?, ?, 'auto')"
        )->execute([$transactionId, $this->receivableId, $cents]);
        $other->commit();
    }
}
