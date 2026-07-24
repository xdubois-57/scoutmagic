<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class ExpectedReceivableServiceTest extends TestCase
{
    private \PDO $pdo;
    private ExpectedReceivableService $service;
    private TransactionRepository $transactionRepository;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->service = new ExpectedReceivableService(new ExpectedReceivableRepository($this->pdo, $encryption), $this->transactionRepository);

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    public function testCreateReceivableReturnsAnId(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', 'Camp d\'été');

        $this->assertGreaterThan(0, $id);
    }

    public function testLabelIsStoredEncryptedAtRestAndDecryptsBackOnRead(): void
    {
        $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', 'Jean Dupont — Camp d\'été');

        $raw = $this->pdo->query('SELECT label_encrypted FROM finance_expected_receivables')->fetchColumn();
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('Jean Dupont', $raw);

        $repository = new \Modules\Finance\Repository\ExpectedReceivableRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $receivable = $repository->findBySource('news', 12)[0];
        $this->assertSame('Jean Dupont — Camp d\'été', $receivable->label);
    }

    public function testGetReceivableStatusIsUnpaidWithNoMatchingTransaction(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', null);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(2500, $status['amount_due']);
        $this->assertSame(0, $status['amount_received']);
        $this->assertSame('unpaid', $status['status']);
    }

    public function testGetReceivableStatusIsPaidWhenAMatchingTransactionCoversTheFullAmount(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', null);

        $this->createTransaction('Virement de Jean Dupont +++100/0000/00034+++', 25.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(2500, $status['amount_received']);
        $this->assertSame('paid', $status['status']);
    }

    public function testGetReceivableStatusIsPartialWhenMatchedAmountIsLessThanDue(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 5000, '+++100/0000/00034+++', null);

        $this->createTransaction('Acompte +++100/0000/00034+++', 20.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(2000, $status['amount_received']);
        $this->assertSame('partial', $status['status']);
    }

    public function testGetReceivableStatusSumsMultipleMatchingTransactions(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 5000, '+++100/0000/00034+++', null);

        $this->createTransaction('Premier versement +++100/0000/00034+++', 20.00);
        $this->createTransaction('Solde +++100/0000/00034+++', 30.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(5000, $status['amount_received']);
        $this->assertSame('paid', $status['status']);
    }

    public function testGetReceivableStatusIgnoresTransactionsWithADifferentCommunication(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', null);

        $this->createTransaction('Virement sans rapport +++999/9999/99999+++', 25.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(0, $status['amount_received']);
        $this->assertSame('unpaid', $status['status']);
    }

    public function testGetReceivableStatusIgnoresDebitTransactions(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', null);

        $this->createTransaction('Paiement sortant +++100/0000/00034+++', -25.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(0, $status['amount_received']);
    }

    public function testDeleteReceivablesForSourceRemovesAllMatchingRows(): void
    {
        $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->service->createReceivable('news', 12, $this->accountId, 3000, '+++200/0000/00068+++', 'Bob');
        $this->service->createReceivable('news', 99, $this->accountId, 1000, '+++300/0000/00002+++', 'Carla');

        $this->service->deleteReceivablesForSource('news', 12);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_expected_receivables WHERE source_reference_id = 12')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_expected_receivables WHERE source_reference_id = 99')->fetchColumn());
    }

    private function createTransaction(string $label, float $amount): void
    {
        $this->transactionRepository->create(
            $this->accountId,
            $this->fiscalYearId,
            null,
            '2026-10-01',
            $label,
            $amount,
            null,
            null,
            'import',
            null
        );
    }
}
