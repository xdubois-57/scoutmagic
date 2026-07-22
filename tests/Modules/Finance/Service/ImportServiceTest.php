<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Parser\BankStatementParserInterface;
use Modules\Finance\Parser\StatementLine;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\ImportService;
use Modules\Finance\Service\ReceiptMatchingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class ImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private ImportService $service;
    private AccountRepository $accountRepository;
    private TransactionRepository $transactionRepository;
    private BalanceCheckpointRepository $checkpointRepository;
    private FiscalYearRepository $fiscalYearRepository;
    private CategoryRepository $categoryRepository;
    private CategoryRuleRepository $categoryRuleRepository;
    private AttachmentRepository $attachmentRepository;
    private TransactionAttachmentRepository $transactionAttachmentRepository;
    private FakeBankStatementParserFactory $parserFactory;
    private Account $account;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $statementImportRepository = new StatementImportRepository($this->pdo);
        $this->fiscalYearRepository = new FiscalYearRepository($this->pdo, new \Core\Config\ScoutYearService($this->pdo));
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $ruleEngine = new CategoryRuleEngine($this->transactionRepository, $this->categoryRuleRepository);
        $balanceService = new BalanceService($this->checkpointRepository, $this->transactionRepository);
        $this->parserFactory = new FakeBankStatementParserFactory();

        $this->attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $receiptMatchingService = new ReceiptMatchingService(
            $this->attachmentRepository, $this->transactionRepository, $this->transactionAttachmentRepository,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->service = new ImportService(
            $this->pdo, $encryption, $this->parserFactory, $this->transactionRepository,
            $this->checkpointRepository, $statementImportRepository, $this->fiscalYearRepository, $ruleEngine, $balanceService,
            $receiptMatchingService
        );

        $accountId = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, 'BE00000000000001', 'Titulaire', 'intendant');
        $this->account = $this->accountRepository->findById($accountId);

        FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function tmpCsvFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'finance_test_');
        file_put_contents($path, 'irrelevant, the fake parser ignores this file content');
        return $path;
    }

    private function line(string $ref, string $date, float $amount, string $label): StatementLine
    {
        return new StatementLine($ref, new \DateTimeImmutable($date), $amount, $label);
    }

    private function createPendingReceipt(?float $suggestedAmount, ?string $suggestedDate, string $uploadedAt): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('a.pdf', 'a.pdf', 'application/pdf', 100)"
        );
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();

        $attachmentId = $this->attachmentRepository->create(
            $this->account->id, $fileId, 'application/pdf', 'facture.pdf', $suggestedAmount, $suggestedDate, null, 1
        );
        $this->pdo->prepare('UPDATE finance_attachments SET uploaded_at = ? WHERE id = ?')->execute([$uploadedAt, $attachmentId]);

        return $attachmentId;
    }

    public function testRejectsIbanMismatch(): void
    {
        $this->parserFactory->iban = 'BE99999999999999';
        $this->parserFactory->lines = [];

        $this->expectException(FinanceException::class);
        $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);
    }

    public function testIbanMismatchLeavesNoTransactionsInserted(): void
    {
        $this->parserFactory->iban = 'BE99999999999999';
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Test')];

        try {
            $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);
        } catch (FinanceException) {
        }

        $this->assertCount(0, $this->transactionRepository->findByAccountId($this->account->id));
    }

    public function testRequiresBalanceOnFirstImport(): void
    {
        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Test')];

        $this->expectException(FinanceException::class);
        $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', null, 1);
    }

    public function testFirstImportSucceedsWithBalanceAndCreatesCheckpoint(): void
    {
        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Achat 1'),
            $this->line('R2', '2026-10-02', -20.0, 'Achat 2'),
        ];

        $result = $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);

        $this->assertSame(2, $result->statementImport->linesTotal);
        $this->assertSame(2, $result->statementImport->linesNew);
        $this->assertSame(0, $result->statementImport->linesDuplicate);
        $this->assertTrue($this->checkpointRepository->hasAnyForAccount($this->account->id));
        $this->assertCount(2, $this->transactionRepository->findByAccountId($this->account->id));
    }

    public function testDeduplicatesOnSecondImport(): void
    {
        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];
        $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);

        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Achat 1'),
            $this->line('R2', '2026-10-05', -5.0, 'Achat 2'),
        ];
        $result = $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'b.csv', null, 1);

        $this->assertSame(2, $result->statementImport->linesTotal);
        $this->assertSame(1, $result->statementImport->linesNew);
        $this->assertSame(1, $result->statementImport->linesDuplicate);
        $this->assertCount(2, $this->transactionRepository->findByAccountId($this->account->id));
    }

    public function testBalanceOptionalOnSecondImport(): void
    {
        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];
        $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);

        $this->parserFactory->lines = [$this->line('R2', '2026-10-05', -5.0, 'Achat 2')];
        $result = $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'b.csv', null, 1);

        $this->assertSame(1, $result->statementImport->linesNew);
        $this->assertCount(1, $this->checkpointRepository->findByAccountId($this->account->id));
    }

    public function testDetectsBalanceDiscrepancyOnSecondImport(): void
    {
        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];
        // First checkpoint: 1000.0 as of 2026-10-01 — this is the bank's own
        // reported closing balance for that day, so it already reflects R1.
        $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);

        $this->parserFactory->lines = [$this->line('R2', '2026-10-05', -5.0, 'Achat 2')];
        // Calculated balance as of 2026-10-05 should be 1000.0 + (-5.0) = 995.0.
        // We report 900.0 instead — a -95.0 discrepancy (900 - 995).
        $result = $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'b.csv', 900.0, 1);

        $this->assertNotNull($result->balanceDiscrepancy);
        $this->assertEqualsWithDelta(-95.0, $result->balanceDiscrepancy, 0.01);
    }

    public function testAppliesCategoryRuleEngineDuringImport(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);

        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'VIR Delhaize')];

        $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);

        $transaction = $this->transactionRepository->findByAccountId($this->account->id)[0];
        $this->assertSame($categoryId, $transaction->categoryId);
    }

    public function testThrowsWhenNoFiscalYearCoversDateAndRollsBackWholeImport(): void
    {
        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Dans exercice'),
            $this->line('R2', '2099-01-01', -20.0, 'Hors exercice'),
        ];

        try {
            $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);
            $this->fail('Expected a FinanceException');
        } catch (FinanceException) {
        }

        // Neither line should have been persisted — the whole import is one transaction.
        $this->assertCount(0, $this->transactionRepository->findByAccountId($this->account->id));
    }

    public function testDeletesTemporaryFileAfterSuccessfulImport(): void
    {
        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat')];

        $path = $this->tmpCsvFile();
        $this->assertFileExists($path);

        $this->service->import($this->account, 'bnp', $path, 'a.csv', 1000.0, 1);

        $this->assertFileDoesNotExist($path);
    }

    public function testDeletesTemporaryFileEvenOnFailure(): void
    {
        $this->parserFactory->iban = 'BE99999999999999';
        $this->parserFactory->lines = [];

        $path = $this->tmpCsvFile();

        try {
            $this->service->import($this->account, 'bnp', $path, 'a.csv', 1000.0, 1);
        } catch (FinanceException) {
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testImportAutoMatchesAPendingReceiptWithAnExactAmount(): void
    {
        $attachmentId = $this->createPendingReceipt(10.0, '2026-10-01', '2026-10-01 12:00:00');

        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [$this->line('R1', '2026-10-02', -10.0, 'Achat')];

        $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);

        $this->assertNotSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachmentId));
    }

    public function testImportPersistsCounterpartyAndExtraDetailsFromStatementLine(): void
    {
        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [
            new StatementLine('R1', new \DateTimeImmutable('2026-10-01'), -10.0, 'Achat', 'BE00000000000009', 'Jean Dupont', 'Type : Virement en euros'),
        ];

        $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);

        $transaction = $this->transactionRepository->findByAccountId($this->account->id)[0];
        $this->assertSame('Jean Dupont', $transaction->counterpartyName);
        $this->assertSame('BE00000000000009', $transaction->counterpartyAccount);
        $this->assertSame('Type : Virement en euros', $transaction->extraDetails);
    }

    public function testImportNeverAutoMatchesAReceiptWithNoKnownAmount(): void
    {
        $attachmentId = $this->createPendingReceipt(null, null, '2026-10-01 12:00:00');

        $this->parserFactory->iban = $this->account->iban;
        $this->parserFactory->lines = [$this->line('R1', '2026-10-02', -10.0, 'Achat')];

        $this->service->import($this->account, 'bnp', $this->tmpCsvFile(), 'a.csv', 1000.0, 1);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachmentId));
    }
}

/**
 * @internal test double
 */
final class FakeStatementParser implements BankStatementParserInterface
{
    /**
     * @param StatementLine[] $lines
     */
    public function __construct(private string $iban, private array $lines)
    {
    }

    public function extractSourceIban(string $filePath): string
    {
        return $this->iban;
    }

    /**
     * @return StatementLine[]
     */
    public function parse(string $filePath): array
    {
        return $this->lines;
    }
}

/**
 * @internal test double — overrides create() so ImportServiceTest never
 * touches a real bank format, only the configured fake lines/IBAN.
 */
final class FakeBankStatementParserFactory extends BankStatementParserFactory
{
    public string $iban = '';

    /** @var StatementLine[] */
    public array $lines = [];

    public function create(string $bankCode): BankStatementParserInterface
    {
        return new FakeStatementParser($this->iban, $this->lines);
    }
}
