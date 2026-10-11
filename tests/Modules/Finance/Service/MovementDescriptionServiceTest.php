<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\MovementDescriptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * A page of movements described in one pass (issue #838): what each pays,
 * the counterparty its IBAN's history names, nothing stored.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MovementDescriptionServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private TransactionRepository $transactions;
    private int $accountId;
    private int $fiscalYearId;
    private int $categoryId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);
        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Compte Unité', 'bank', 'active')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');
        $this->categoryId = FinanceTestHelper::createCategory($this->pdo, 'Camps');
    }

    // ── what the movement pays ──────────────────────────────────────────

    public function testAMovementIsDescribedByTheReceivableTakingItsLargestShare(): void
    {
        $receivables = FinanceTestHelper::receivableService($this->pdo, $this->encryption);
        $fee = $receivables->createReceivable('news', 1, $this->accountId, 3000, '+++123/4567/89002+++', 'Cotisation Jean', $this->categoryId);
        $camp = $receivables->createReceivable('news', 2, $this->accountId, 9000, '+++123/4567/89103+++', 'Camp Jean', $this->categoryId);
        $movement = $this->movement('+++123/4567/89002+++', 120.00);
        $this->allocate($movement, $fee, 3000);
        $this->allocate($movement, $camp, 9000);

        $description = $this->present([$movement])[$movement]['description'];

        $this->assertStringStartsWith('News #2 — Camp Jean (+ 1 autre créance)', $description);
        $this->assertStringNotContainsString('+++', $description);
    }

    /**
     * A tie never flips between two runs: the lowest receivable id names it.
     */
    public function testATieIsNamedByTheLowestReceivable(): void
    {
        $receivables = FinanceTestHelper::receivableService($this->pdo, $this->encryption);
        $first = $receivables->createReceivable('news', 1, $this->accountId, 5000, '+++123/4567/89002+++', 'Premier', $this->categoryId);
        $second = $receivables->createReceivable('news', 2, $this->accountId, 5000, '+++123/4567/89103+++', 'Second', $this->categoryId);
        $movement = $this->movement('Virement', 100.00);
        $this->allocate($movement, $second, 5000);
        $this->allocate($movement, $first, 5000);

        $this->assertStringStartsWith('News #1 — Premier', $this->present([$movement])[$movement]['description']);
    }

    public function testACreditCarryingAnUnknownCommunicationIsUnimputed(): void
    {
        $movement = $this->movement('+++123/4567/89002+++', 45.00);

        $this->assertSame('Paiement non imputé', $this->present([$movement])[$movement]['description']);
    }

    // ── the counterparty an IBAN's history names ────────────────────────

    public function testAnIbanWithoutANameTakesTheNameItsHistoryMostOftenCarries(): void
    {
        $this->movement('Payconiq', -3.50, '2026-01-10', 'Boulangerie Martin', 'BE12 3456 7890 1234');
        $this->movement('Payconiq', -4.20, '2026-02-10', 'BOULANGERIE  MARTIN', 'BE12345678901234');
        $this->movement('Payconiq', -2.00, '2026-02-12', 'Martin SPRL', 'BE12345678901234');
        $nameless = $this->movement('Payconiq', -5.00, '2026-03-01', null, 'be12 3456 7890 1234');

        $presented = $this->present([$nameless])[$nameless];

        $this->assertSame('Boulangerie Martin', $presented['counterparty']);
        $this->assertStringContainsString('Boulangerie Martin', $presented['description']);
    }

    /**
     * Two names as frequent as each other: a guess between two people is
     * worse than no name.
     */
    public function testATieBetweenTwoNamesInfersNothing(): void
    {
        $this->movement('Payconiq', -3.50, '2026-01-10', 'Jean Dupont', 'BE12345678901234');
        $this->movement('Payconiq', -4.20, '2026-02-10', 'Marie Dupont', 'BE12345678901234');
        $nameless = $this->movement('Payconiq', -5.00, '2026-03-01', null, 'BE12345678901234');

        $this->assertSame('Compte Unité', $this->present([$nameless])[$nameless]['counterparty']);
    }

    public function testHistoryOlderThanEighteenMonthsIsNotAsked(): void
    {
        $oldYear = FinanceTestHelper::createScoutYear($this->pdo, '2023-2024', '2023-09-01', '2024-08-31');
        $this->transactions->create($this->accountId, $oldYear, 'old', '2024-06-01', 'Payconiq', -3.0, null, null, Transaction::SOURCE_IMPORT, null, 'Ancien Nom', 'BE12345678901234');
        $nameless = $this->movement('Payconiq', -5.00, '2026-03-01', null, 'BE12345678901234');

        $this->assertSame('Compte Unité', $this->present([$nameless])[$nameless]['counterparty']);
    }

    /**
     * The counterparty columns are encrypted, so the history is read per
     * account — once for a whole page, however many movements need it.
     */
    public function testTheHistoryIsReadOncePerAccountForAWholePage(): void
    {
        $this->movement('Payconiq', -3.50, '2026-01-10', 'Boulangerie Martin', 'BE12345678901234');
        $page = [];
        for ($i = 0; $i < 5; $i++) {
            $page[] = $this->movement('Payconiq', -1.0 - $i, '2026-03-0' . ($i + 1), null, 'BE12345678901234');
        }
        $spy = new class ($this->pdo, $this->encryption) extends TransactionRepository {
            public int $historyReads = 0;

            public function findByAccountAfterDate(int $accountId, string $afterDate): array
            {
                $this->historyReads++;

                return parent::findByAccountAfterDate($accountId, $afterDate);
            }
        };

        $presented = $this->service($spy)->present($this->transactions->findByIds($page), [$this->accountId => 'Compte Unité']);

        $this->assertSame(1, $spy->historyReads);
        $this->assertSame(['Boulangerie Martin'], array_values(array_unique(array_column($presented, 'counterparty'))));
    }

    /**
     * A projection, never a column: presenting changes nothing stored.
     */
    public function testNothingIsStored(): void
    {
        $this->movement('Payconiq', -3.50, '2026-01-10', 'Boulangerie Martin', 'BE12345678901234');
        $nameless = $this->movement('Payconiq', -5.00, '2026-03-01', null, 'BE12345678901234');
        $before = $this->transactions->findById($nameless);

        $this->present([$nameless]);

        $this->assertEquals($before, $this->transactions->findById($nameless));
        // Portable across SQLite and MariaDB: the stored row's own columns.
        $row = $this->pdo->query('SELECT * FROM finance_transactions WHERE id = ' . $nameless)?->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('human_description', $row);
    }

    /**
     * @param int[] $ids
     * @return array<int, array{counterparty: string, description: string}>
     */
    private function present(array $ids): array
    {
        return $this->service($this->transactions)->present($this->transactions->findByIds($ids), [$this->accountId => 'Compte Unité']);
    }

    private function service(TransactionRepository $transactions): MovementDescriptionService
    {
        $base = FinanceTestHelper::movementDescriptions($this->pdo, $this->encryption, [], static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-03-15'));
        $reflection = new \ReflectionProperty(MovementDescriptionService::class, 'transactionRepository');
        $reflection->setValue($base, $transactions);

        return $base;
    }

    private function movement(
        string $label,
        float $amount,
        string $date = '2026-02-18',
        ?string $counterpartyName = null,
        ?string $counterpartyAccount = null
    ): int {
        return $this->transactions->create(
            $this->accountId, $this->fiscalYearId, 'ref-' . uniqid('', true), $date, $label, $amount,
            null, null, Transaction::SOURCE_IMPORT, null, $counterpartyName, $counterpartyAccount
        );
    }

    private function allocate(int $movement, int $receivable, int $cents): void
    {
        (new ReceivableAllocationRepository($this->pdo))->create($movement, $receivable, $cents, 'manual', 7);
    }
}
