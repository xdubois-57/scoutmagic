<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Api\PaymentCandidate;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\StructuredCommunicationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\UsesProductionEngine;

/**
 * The payment a person points at (chantier #980, §2.6), on the engine that
 * takes its locks.
 *
 * `ExpectedReceivableInterface::reconcilePayment()` re-checks a candidate
 * while holding the receivable's row and then the credit's. SQLite, the
 * ordinary test engine, takes neither `FOR UPDATE` — so this class runs on
 * the production schema (`Tests\UsesProductionEngine`), lets two
 * connections race for one credit on behalf of two receivables, and
 * replays the candidate search, whose filters are a DECIMAL and a DATE
 * compared by the server.
 */
#[Group('database')]
final class ReceivablePaymentOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private ExpectedReceivableService $api;
    private int $accountId;
    private int $fiscalYearId;
    private int $categoryId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);
        $this->api = FinanceTestHelper::receivableService($this->pdo, $this->encryption, $this->receivables);

        $this->pdo->exec(
            "INSERT INTO finance_accounts (name, account_type, status) VALUES ('Locations', 'bank', 'active')"
        );
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO finance_categories (name) VALUES ('Locations')");
        $this->categoryId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) '
                . "VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)"
        );
        $this->fiscalYearId = (int) $this->pdo->lastInsertId();
    }

    /** The premise: on SQLite the locks below are not taken, and the race would prove nothing. */
    public function testThisEngineTakesTheLockingBranch(): void
    {
        $this->assertNotSame('sqlite', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    /** The search on the real engine: the exact DECIMAL amount and the DATE bound, compared by the server. */
    public function testTheServerKeepsOnlyTheCreditsOfThatExactAmountSinceTheDate(): void
    {
        $id = $this->receivable('0909337554');
        $this->movement('2027-03-13', 300.10, 'La veille', 'ETHIAS SA');
        $first = $this->movement('2027-03-14', 300.10, 'Le jour même', 'ÉTHIAS SA');
        $this->movement('2027-03-15', 300.11, 'Un centime de plus', 'ETHIAS SA');
        $this->movement('2027-03-15', 300.09, 'Un centime de moins', 'ETHIAS SA');
        $this->movement('2027-03-15', -300.10, 'Un débit', 'ETHIAS SA');
        $second = $this->movement('2027-03-16', 300.10, 'Plus tard', 'Ethias');

        $since = new \DateTimeImmutable('2027-03-14');
        $candidates = $this->api->findPaymentCandidates('rental', $id, 30010, 'éthias', $since);

        $this->assertSame(
            [$first, $second],
            array_map(static fn (PaymentCandidate $c): int => $c->transactionId, $candidates)
        );
        $this->assertSame(30010, $candidates[0]->amountCents);
    }

    /**
     * Two people, two receivables, one credit, at the same moment: the
     * first one's attachment holds the credit's row until it commits, the
     * second waits on it — here, gives up after a second — and, asked
     * again once the first has committed, is refused. One wins.
     */
    public function testTwoConnectionsClaimingTheSameCreditForTwoReceivablesCannotBothHaveIt(): void
    {
        $mine = $this->receivable('0909337554');
        $theirs = $this->receivable('1230000001');
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre 2027/118', 'ETHIAS SA');
        $since = new \DateTimeImmutable('2027-03-01');

        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = self::productionEngineConnection($database)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $otherApi = FinanceTestHelper::receivableService($other, $this->encryption);

        // The first person's attachment, still uncommitted: the lock joins
        // the transaction already open on the connection.
        $this->pdo->beginTransaction();
        $this->api->reconcilePayment('rental', $mine, $credit, 30000, $since, 7);

        try {
            $otherApi->reconcilePayment('rental', $theirs, $credit, 30000, $since, 8);
            $this->fail('the second connection attached a credit the first one holds');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('lock', strtolower($e->getMessage()));
        }
        $this->assertFalse($other->inTransaction(), 'the waiting attempt left its transaction open');

        $this->pdo->commit();

        try {
            $otherApi->reconcilePayment('rental', $theirs, $credit, 30000, $since, 8);
            $this->fail('the credit was attached to a second receivable once the first committed');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('déjà rapproché', $e->getMessage());
        }

        $rows = $this->pdo->prepare(
            'SELECT receivable_id, amount_cents, source FROM finance_receivable_allocations WHERE transaction_id = ?'
        );
        $rows->execute([$credit]);
        $this->assertSame(
            [['receivable_id' => $mine, 'amount_cents' => 30000, 'source' => 'manual']],
            array_map(
                static fn (array $row): array => [
                    'receivable_id' => (int) $row['receivable_id'],
                    'amount_cents' => (int) $row['amount_cents'],
                    'source' => (string) $row['source'],
                ],
                $rows->fetchAll(\PDO::FETCH_ASSOC)
            )
        );
    }

    /** Attach then detach on the real engine: each commits its own transaction, nothing is left open. */
    public function testAttachingAndDetachingCommitAndLeaveNothingOpen(): void
    {
        $id = $this->receivable('0909337554');
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre', 'ETHIAS SA');
        $since = new \DateTimeImmutable('2027-03-01');

        $this->api->reconcilePayment('rental', $id, $credit, 30000, $since, 7);
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(30000, $this->api->getReceivableStanding('rental', $id)?->amountReceivedCents);

        $this->api->detachPayment('rental', $id, $credit);
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->api->getReceivableStanding('rental', $id)?->amountReceivedCents);

        $this->expectException(FinanceException::class);
        $this->api->detachPayment('rental', $id, $credit);
    }

    private function receivable(string $tenDigits): int
    {
        return $this->receivables->create(
            'rental',
            1,
            $this->accountId,
            62200,
            StructuredCommunicationService::format($tenDigits),
            null,
            null,
            $this->categoryId
        );
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
