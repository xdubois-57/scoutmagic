<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Parser\BankStatementParserInterface;
use Modules\Finance\Parser\ClosingBalance;
use Modules\Finance\Parser\StatementLine;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AiCategorySuggestionRepository;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AiCategorizationService;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\BulkCategorizationService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Service\ImportResult;
use Modules\Finance\Service\ImportService;
use Modules\Finance\Service\SkippedAccount;
use Modules\Finance\Service\StatementFormatNotRecognized;
use Modules\Finance\Service\ReceiptMatchingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
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
    private BulkCategorizationService $bulkCategorizationService;
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

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $aiService = new AiCategorizationService(
            null, $this->categoryRepository, new AiCategorySuggestionRepository($this->pdo), new JournalService(new JournalRepository($this->pdo))
        );
        $this->bulkCategorizationService = new BulkCategorizationService(
            $this->transactionRepository, $ruleEngine, $aiService, $settingService, new SchedulerService(new SchedulerRepository($this->pdo))
        );

        $this->service = new ImportService(
            $this->pdo, $encryption, $this->parserFactory, $this->accountRepository, $this->transactionRepository,
            $this->checkpointRepository, $statementImportRepository, $this->fiscalYearRepository, $ruleEngine, $balanceService,
            $receiptMatchingService, $this->bulkCategorizationService,
            FinanceTestHelper::allocationService($this->pdo, $encryption)
        );

        $this->account = $this->activeAccount('Compte', 'BE00000000000001');

        FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function tmpCsvFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'finance_test_');
        file_put_contents($path, 'irrelevant, the fake parser ignores this file content');
        return $path;
    }

    private function line(string $ref, string $date, float $amount, string $label, string $iban = 'BE00000000000001'): StatementLine
    {
        return new StatementLine($iban, $ref, new \DateTimeImmutable($date), $amount, $label);
    }

    private function activeAccount(string $name, string $iban, string $roleMinView = 'intendant'): Account
    {
        $id = $this->accountRepository->create($name, Account::TYPE_BANK, null, $iban, 'Titulaire', $roleMinView);
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$id]);
        $account = $this->accountRepository->findById($id);
        \assert($account !== null);

        return $account;
    }

    /**
     * @param \Closure(Account): bool|null $mayImportInto
     */
    private function import(?float $balance, string $file = 'a.csv', ?\Closure $mayImportInto = null, ?string $path = null): ImportResult
    {
        return $this->service->import(
            'bnp',
            $path ?? $this->tmpCsvFile(),
            $file,
            $balance,
            1,
            $mayImportInto ?? static fn (): bool => true
        );
    }

    private function countStatementImports(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM finance_statement_imports')->fetchColumn();
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

    // --- The file's IBANs decide (issue #511, IT-01) --------------------

    public function testEachLineJoinsTheAccountCarryingItsIban(): void
    {
        $second = $this->activeAccount('Deuxième', 'BE00000000000002');
        $this->parserFactory->ibans = ['BE00000000000001', 'BE00000000000002'];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Premier'),
            $this->line('R2', '2026-10-02', -20.0, 'Deuxième', 'BE00000000000002'),
            $this->line('R3', '2026-10-03', -30.0, 'Deuxième encore', 'BE00000000000002'),
        ];
        $this->checkpointRepository->create($this->account->id, '2026-09-01', 0.0, 'manual');
        $this->checkpointRepository->create($second->id, '2026-09-01', 0.0, 'manual');

        $result = $this->import(null);

        $this->assertSame([$this->account->id, $second->id], array_map(static fn ($o) => $o->account->id, $result->accounts));
        $this->assertSame(['Premier'], array_map(static fn ($t) => $t->label, $this->transactionRepository->findByAccountId($this->account->id)));
        $this->assertCount(2, $this->transactionRepository->findByAccountId($second->id));
        $this->assertSame(3, $result->linesNew());
        $this->assertSame([], $result->skipped);
    }

    /**
     * One file, one upload id — shared by the bookkeeping row of every
     * account it fed, and different from the next file's.
     */
    public function testEveryAccountGetsItsOwnBookkeepingRowTiedToTheSameUpload(): void
    {
        $second = $this->activeAccount('Deuxième', 'BE00000000000002');
        $this->parserFactory->ibans = ['BE00000000000001', 'BE00000000000002'];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Premier'),
            $this->line('R2', '2026-10-02', -20.0, 'Deuxième', 'BE00000000000002'),
        ];
        $this->checkpointRepository->create($this->account->id, '2026-09-01', 0.0, 'manual');
        $this->checkpointRepository->create($second->id, '2026-09-01', 0.0, 'manual');

        $first = $this->import(null);
        $again = $this->import(null, 'b.csv');

        [$a, $b] = [$first->accounts[0]->statementImport, $first->accounts[1]->statementImport];
        $this->assertSame([$this->account->id, $second->id], [$a->accountId, $b->accountId]);
        $this->assertNotNull($a->uploadId);
        $this->assertSame($a->uploadId, $b->uploadId);
        $this->assertNotSame($a->uploadId, $again->accounts[0]->statementImport->uploadId);
        $this->assertSame(4, $this->countStatementImports());
    }

    /**
     * An IBAN no site account carries is set aside and named — never
     * written, and never the occasion to create an account.
     */
    public function testAnUnknownIbanIsSkippedAndReportedWithoutCreatingAnything(): void
    {
        $this->parserFactory->ibans = ['BE00000000000001', 'BE99999999999999'];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Connu'),
            $this->line('R2', '2026-10-02', -20.0, 'Inconnu', 'BE99999999999999'),
            $this->line('R3', '2026-10-03', -30.0, 'Inconnu', 'BE99999999999999'),
        ];
        $accountsBefore = count($this->accountRepository->findAllOrdered());

        $result = $this->import(1000.0);

        $this->assertCount(1, $result->accounts);
        $this->assertCount(1, $this->transactionRepository->findByAccountId($this->account->id));
        $this->assertEquals([new SkippedAccount('BE99999999999999', SkippedAccount::REASON_UNKNOWN, 2)], $result->skipped);
        $this->assertSame($accountsBefore, count($this->accountRepository->findAllOrdered()));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn());
    }

    public function testAFileWhoseOnlyIbanIsUnknownWritesNothingAtAll(): void
    {
        $this->parserFactory->ibans = ['BE99999999999999'];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Inconnu', 'BE99999999999999')];

        $result = $this->import(1000.0);

        $this->assertSame([], $result->accounts);
        $this->assertSame(SkippedAccount::REASON_UNKNOWN, $result->skipped[0]->reason);
        $this->assertSame(0, $this->countStatementImports());
        $this->assertFalse($this->checkpointRepository->hasAnyForAccount($this->account->id));
    }

    /**
     * The RBAC boundary, now that no account is chosen: an account the
     * caller's rule refuses is skipped — no line, no checkpoint, no
     * bookkeeping row — and its neighbour in the same file still imports.
     */
    public function testAnAccountTheCallerMayNotUseIsSkippedAndNothingIsWrittenIntoIt(): void
    {
        $restricted = $this->activeAccount('Réservé', 'BE00000000000002', 'admin');
        $this->parserFactory->ibans = ['BE00000000000001', 'BE00000000000002'];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Permis'),
            $this->line('R2', '2026-10-02', -20.0, 'Refusé', 'BE00000000000002'),
        ];
        $this->checkpointRepository->create($this->account->id, '2026-09-01', 0.0, 'manual');

        $result = $this->import(null, 'a.csv', fn (Account $account): bool => $account->id !== $restricted->id);

        $this->assertSame([$this->account->id], array_map(static fn ($o) => $o->account->id, $result->accounts));
        $this->assertSame(SkippedAccount::REASON_FORBIDDEN, $result->skipped[0]->reason);
        $this->assertCount(0, $this->transactionRepository->findByAccountId($restricted->id));
        $this->assertFalse($this->checkpointRepository->hasAnyForAccount($restricted->id));
    }

    /**
     * Visibility is judged before status: an archived account the caller
     * may not use reads as forbidden, not as archived.
     */
    public function testAnInactiveAccountIsSkippedAndSaysSoOnlyToWhoeverMayUseIt(): void
    {
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'archived' WHERE id = ?")->execute([$this->account->id]);
        $this->parserFactory->ibans = ['BE00000000000001'];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Archivé')];

        $visible = $this->import(1000.0);
        $hidden = $this->import(1000.0, 'b.csv', static fn (): bool => false);

        $this->assertSame(SkippedAccount::REASON_INACTIVE, $visible->skipped[0]->reason);
        $this->assertSame(SkippedAccount::REASON_FORBIDDEN, $hidden->skipped[0]->reason);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn());
    }

    /**
     * Nothing makes an IBAN unique across accounts: the account that
     * replaced an archived one inherits its statements…
     */
    public function testTheActiveAccountWinsOverAnArchivedOneSharingItsIban(): void
    {
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'archived' WHERE id = ?")->execute([$this->account->id]);
        $replacement = $this->activeAccount('Remplaçant', 'BE00000000000001');
        $this->parserFactory->ibans = ['BE00000000000001'];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat')];

        $result = $this->import(1000.0);

        $this->assertSame($replacement->id, $result->accounts[0]->account->id);
        $this->assertCount(0, $this->transactionRepository->findByAccountId($this->account->id));
    }

    /**
     * …and two ACTIVE accounts sharing one are not settled by chance.
     */
    public function testTwoActiveAccountsSharingAnIbanAreAmbiguousAndGetNothing(): void
    {
        $twin = $this->activeAccount('Jumeau', 'BE00000000000001');
        $this->parserFactory->ibans = ['BE00000000000001'];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat')];

        $result = $this->import(1000.0);

        $this->assertSame([], $result->accounts);
        $this->assertSame(SkippedAccount::REASON_AMBIGUOUS, $result->skipped[0]->reason);
        $this->assertCount(0, $this->transactionRepository->findByAccountId($twin->id));
    }

    public function testATypedBalanceIsRefusedForAFileCoveringSeveralAccounts(): void
    {
        $second = $this->activeAccount('Deuxième', 'BE00000000000002');
        $this->parserFactory->ibans = ['BE00000000000001', 'BE00000000000002'];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Premier'),
            $this->line('R2', '2026-10-02', -20.0, 'Deuxième', 'BE00000000000002'),
        ];

        try {
            $this->import(1000.0);
            $this->fail('A typed balance cannot be attributed to one of several accounts.');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('plusieurs comptes', $e->getMessage());
        }

        $this->assertSame(0, $this->countStatementImports());
        $this->assertFalse($this->checkpointRepository->hasAnyForAccount($second->id));
    }

    public function testAFirstImportOfAnyAccountInTheFileStillNeedsItsBalance(): void
    {
        $second = $this->activeAccount('Deuxième', 'BE00000000000002');
        $this->checkpointRepository->create($this->account->id, '2026-09-01', 0.0, 'manual');
        $this->parserFactory->ibans = ['BE00000000000001', 'BE00000000000002'];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Premier'),
            $this->line('R2', '2026-10-02', -20.0, 'Deuxième', 'BE00000000000002'),
        ];

        try {
            $this->import(null);
            $this->fail('The second account has never been imported: its opening balance is missing.');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('« Deuxième »', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn());
        $this->assertFalse($this->checkpointRepository->hasAnyForAccount($second->id));
    }

    /**
     * All or nothing across accounts: the second account's bad date takes
     * the first account's perfectly good lines down with it.
     */
    public function testADateNoScoutYearCoversRefusesTheWholeFileAcrossAccounts(): void
    {
        $second = $this->activeAccount('Deuxième', 'BE00000000000002');
        $this->checkpointRepository->create($this->account->id, '2026-09-01', 0.0, 'manual');
        $this->checkpointRepository->create($second->id, '2026-09-01', 0.0, 'manual');
        $this->parserFactory->ibans = ['BE00000000000001', 'BE00000000000002'];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Bon'),
            $this->line('R2', '2099-01-05', -20.0, 'Hors année', 'BE00000000000002'),
            $this->line('R3', '2099-01-02', -30.0, 'Hors année', 'BE00000000000002'),
        ];

        try {
            $this->import(null);
            $this->fail('A date outside every scout year refuses the file.');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('2 dates, du 02/01/2099 au 05/01/2099', $e->getMessage());
            $this->assertStringContainsString('année scoute 2098-2099', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn());
        $this->assertSame(0, $this->countStatementImports());
    }

    /**
     * The year is never created from a statement date: a one-byte shift in
     * a file makes plausible, wrong dates.
     */
    public function testAMissingScoutYearIsNeverCreated(): void
    {
        $this->parserFactory->ibans = ['BE00000000000001'];
        $this->parserFactory->lines = [$this->line('R1', '2099-01-05', -10.0, 'Hors année')];
        $yearsBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM scout_years')->fetchColumn();

        try {
            $this->import(1000.0);
            $this->fail('A date outside every scout year refuses the file.');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('la date du 05/01/2099', $e->getMessage());
        }

        $this->assertSame($yearsBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM scout_years')->fetchColumn());
    }

    /**
     * A date on a line of a SKIPPED account decides nothing: those lines
     * are never written, so they cannot refuse the file.
     */
    public function testADateOnASkippedAccountsLineDoesNotRefuseTheFile(): void
    {
        $this->parserFactory->ibans = ['BE00000000000001', 'BE99999999999999'];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Bon'),
            $this->line('R2', '2099-01-05', -20.0, 'Inconnu', 'BE99999999999999'),
        ];

        $result = $this->import(1000.0);

        $this->assertSame(1, $result->linesNew());
    }

    public function testALineClaimingAnAccountTheFileNeverAnnouncedIsRefused(): void
    {
        $this->parserFactory->ibans = ['BE00000000000001'];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Égarée', 'BE00000000000002')];

        $this->expectException(FinanceException::class);
        $this->import(1000.0);
    }

    public function testRequiresBalanceOnFirstImport(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Test')];

        $this->expectException(FinanceException::class);
        $this->import(null, 'a.csv');
    }

    public function testFirstImportSucceedsWithBalanceAndCreatesCheckpoint(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Achat 1'),
            $this->line('R2', '2026-10-02', -20.0, 'Achat 2'),
        ];

        $result = $this->import(1000.0, 'a.csv');

        $this->assertSame(2, $result->accounts[0]->statementImport->linesTotal);
        $this->assertSame(2, $result->accounts[0]->statementImport->linesNew);
        $this->assertSame(0, $result->accounts[0]->statementImport->linesDuplicate);
        $this->assertTrue($this->checkpointRepository->hasAnyForAccount($this->account->id));
        $this->assertCount(2, $this->transactionRepository->findByAccountId($this->account->id));
    }

    public function testDeduplicatesOnSecondImport(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];
        $this->import(1000.0, 'a.csv');

        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Achat 1'),
            $this->line('R2', '2026-10-05', -5.0, 'Achat 2'),
        ];
        $result = $this->import(null, 'b.csv');

        $this->assertSame(2, $result->accounts[0]->statementImport->linesTotal);
        $this->assertSame(1, $result->accounts[0]->statementImport->linesNew);
        $this->assertSame(1, $result->accounts[0]->statementImport->linesDuplicate);
        $this->assertCount(2, $this->transactionRepository->findByAccountId($this->account->id));
    }

    public function testNoBalanceIsNeededAfterTheFirstImport(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];
        $this->import(1000.0, 'a.csv');

        $this->parserFactory->lines = [$this->line('R2', '2026-10-05', -5.0, 'Achat 2')];
        $result = $this->import(null, 'b.csv');

        $this->assertSame(1, $result->accounts[0]->statementImport->linesNew);
        $this->assertCount(1, $this->checkpointRepository->findByAccountId($this->account->id));
    }

    /**
     * A format that states no balance takes a typed one on the account's
     * first import only: afterwards the balance is recomputed from the
     * movements, and a typed one is refused rather than recorded.
     */
    public function testATypedBalanceIsRefusedOnceTheAccountHasOne(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];
        $this->import(1000.0, 'a.csv');

        $this->parserFactory->lines = [$this->line('R2', '2026-10-05', -5.0, 'Achat 2')];

        try {
            $this->import(900.0, 'b.csv');
            $this->fail('A second typed balance must be refused.');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('déjà un solde de référence', $e->getMessage());
        }

        $this->assertCount(1, $this->checkpointRepository->findByAccountId($this->account->id));
        $this->assertCount(1, $this->transactionRepository->findByAccountId($this->account->id));
    }

    // --- Balances stated by the file (issue #511, IT-02) -----------------

    /**
     * A file that states its balances needs none typed — not even on a
     * first import, which is the case a multi-account file could never
     * have met with one form field.
     */
    public function testAFileStatingItsBalancesRecordsEachAccountsOwnOnItsOwnDate(): void
    {
        $second = $this->activeAccount('Deuxième', 'BE00000000000002');
        $this->parserFactory->ibans = ['BE00000000000001', 'BE00000000000002'];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Premier'),
            $this->line('R2', '2026-10-02', -20.0, 'Deuxième', 'BE00000000000002'),
        ];
        $this->parserFactory->balances = [
            'BE00000000000001' => new ClosingBalance(new \DateTimeImmutable('2026-10-03'), 990.0),
            'BE00000000000002' => new ClosingBalance(new \DateTimeImmutable('2026-10-04'), 480.0),
        ];

        $this->import(null);

        $first = $this->checkpointRepository->findByAccountId($this->account->id);
        $other = $this->checkpointRepository->findByAccountId($second->id);
        $this->assertCount(1, $first);
        $this->assertEqualsWithDelta(990.0, $first[0]->balance, 0.001);
        $this->assertSame('2026-10-03', $first[0]->checkpointDate);
        $this->assertEqualsWithDelta(480.0, $other[0]->balance, 0.001);
        $this->assertSame('2026-10-04', $other[0]->checkpointDate);
    }

    /**
     * The existing check does not change in nature: it compares the
     * ledger to a balance that now comes from the file.
     */
    public function testTheDiscrepancyCheckComparesTheLedgerToTheFilesBalance(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];
        $this->parserFactory->balances = [$this->account->iban => new ClosingBalance(new \DateTimeImmutable('2026-10-01'), 1000.0)];
        $this->import(null, 'a.csv');

        // The ledger says 1000 − 5 = 995 on the 5th; the file says 900.
        $this->parserFactory->lines = [$this->line('R2', '2026-10-05', -5.0, 'Achat 2')];
        $this->parserFactory->balances = [$this->account->iban => new ClosingBalance(new \DateTimeImmutable('2026-10-05'), 900.0)];
        $result = $this->import(null, 'b.csv');

        $this->assertNotNull($result->accounts[0]->balanceDiscrepancy);
        $this->assertEqualsWithDelta(-95.0, $result->accounts[0]->balanceDiscrepancy, 0.01);
    }

    public function testTheSameFileImportedTwiceWritesItsBalanceOnce(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];
        $this->parserFactory->balances = [$this->account->iban => new ClosingBalance(new \DateTimeImmutable('2026-10-01'), 1000.0)];

        $this->import(null, 'a.csv');
        $again = $this->import(null, 'a.csv');

        $this->assertCount(1, $this->checkpointRepository->findByAccountId($this->account->id));
        $this->assertNull($again->accounts[0]->balanceDiscrepancy);
        $this->assertSame(1, $again->linesDuplicate());
    }

    /**
     * A typed balance next to one the file states would be one of them
     * silently ignored: refused instead, before anything is written.
     */
    public function testATypedBalanceIsRefusedWhenTheFileStatesItsOwn(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];
        $this->parserFactory->balances = [$this->account->iban => new ClosingBalance(new \DateTimeImmutable('2026-10-01'), 1000.0)];

        try {
            $this->import(1000.0);
            $this->fail('The file states its own balance.');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('indique lui-même le solde', $e->getMessage());
        }

        $this->assertSame(0, $this->countStatementImports());
    }

    // --- Format detection -------------------------------------------------

    public function testTheFormatIsDetectedWhenNoneIsGiven(): void
    {
        $this->parserFactory->detected = 'bnp';
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];

        $result = $this->service->import(null, $this->tmpCsvFile(), 'a.csv', 1000.0, 1, static fn (): bool => true);

        $this->assertSame('bnp', $result->accounts[0]->statementImport->bankCode);
    }

    public function testAFileNoFormatRecognizesAsksForTheFormatAndWritesNothing(): void
    {
        $this->parserFactory->detected = null;
        $path = $this->tmpCsvFile();

        try {
            $this->service->import(null, $path, 'a.csv', null, 1, static fn (): bool => true);
            $this->fail('An unrecognized file cannot be imported.');
        } catch (StatementFormatNotRecognized $e) {
            $this->assertStringContainsString('Choisissez le format manuellement', $e->getMessage());
        }

        $this->assertSame(0, $this->countStatementImports());
        $this->assertFileDoesNotExist($path);
    }

    // --- Structured communication ----------------------------------------

    /**
     * The structured communication a format carries apart is stored apart,
     * encrypted like the label, and read back whole.
     */
    public function testAStructuredCommunicationIsStoredInItsOwnEncryptedField(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [
            new StatementLine('BE00000000000001', 'R1', new \DateTimeImmutable('2026-10-01'), 45.0, '+++126/0010/00146+++', structuredCommunication: '126001000146'),
        ];

        $this->import(1000.0);

        $transaction = $this->transactionRepository->findByAccountId($this->account->id)[0];
        $this->assertSame('126001000146', $transaction->structuredCommunication);
        $raw = (string) $this->pdo->query('SELECT structured_communication FROM finance_transactions')->fetchColumn();
        $this->assertStringNotContainsString('126001000146', $raw);
    }

    public function testAppliesCategoryRuleEngineDuringImport(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);

        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'VIR Delhaize')];

        $this->import(1000.0, 'a.csv');

        $transaction = $this->transactionRepository->findByAccountId($this->account->id)[0];
        $this->assertSame($categoryId, $transaction->categoryId);
    }

    public function testImportSchedulesBackgroundCategorizationRunWhenNewLinesAreInserted(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];

        $this->import(1000.0, 'a.csv');

        $this->assertTrue($this->bulkCategorizationService->isRunning());
    }

    public function testImportDoesNotScheduleBackgroundRunWhenEverythingWasADuplicate(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat 1')];
        $this->import(1000.0, 'a.csv');
        // Finish the run the first (real) import started, so the second
        // (all-duplicate) import's own behavior can be observed cleanly.
        $this->bulkCategorizationService->runOnUncategorized();

        $this->import(null, 'b.csv');

        $this->assertFalse($this->bulkCategorizationService->isRunning());
    }

    public function testThrowsWhenNoFiscalYearCoversDateAndRollsBackWholeImport(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [
            $this->line('R1', '2026-10-01', -10.0, 'Dans exercice'),
            $this->line('R2', '2099-01-01', -20.0, 'Hors exercice'),
        ];

        try {
            $this->import(1000.0, 'a.csv');
            $this->fail('Expected a FinanceException');
        } catch (FinanceException) {
        }

        // Neither line should have been persisted — the whole import is one transaction.
        $this->assertCount(0, $this->transactionRepository->findByAccountId($this->account->id));
    }

    public function testDeletesTemporaryFileAfterSuccessfulImport(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-01', -10.0, 'Achat')];

        $path = $this->tmpCsvFile();
        $this->assertFileExists($path);

        $this->import(1000.0, 'a.csv', null, $path);

        $this->assertFileDoesNotExist($path);
    }

    public function testDeletesTemporaryFileEvenOnFailure(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2099-01-01', -10.0, 'Hors année')];

        $path = $this->tmpCsvFile();

        try {
            $this->import(1000.0, 'a.csv', null, $path);
            $this->fail('The import was expected to fail on the missing scout year.');
        } catch (FinanceException) {
            // The failure is this test's premise: without it, the deletion
            // below is only the success path's, which its neighbour above
            // already covers.
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testImportAutoMatchesAPendingReceiptWithAnExactAmount(): void
    {
        $attachmentId = $this->createPendingReceipt(10.0, '2026-10-01', '2026-10-01 12:00:00');

        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-02', -10.0, 'Achat')];

        $this->import(1000.0, 'a.csv');

        $this->assertNotSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachmentId));
    }

    public function testImportPersistsCounterpartyAndExtraDetailsFromStatementLine(): void
    {
        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [
            new StatementLine('BE00000000000001', 'R1', new \DateTimeImmutable('2026-10-01'), -10.0, 'Achat', 'BE00000000000009', 'Jean Dupont', 'Type : Virement en euros'),
        ];

        $this->import(1000.0, 'a.csv');

        $transaction = $this->transactionRepository->findByAccountId($this->account->id)[0];
        $this->assertSame('Jean Dupont', $transaction->counterpartyName);
        $this->assertSame('BE00000000000009', $transaction->counterpartyAccount);
        $this->assertSame('Type : Virement en euros', $transaction->extraDetails);
    }

    public function testImportNeverAutoMatchesAReceiptWithNoKnownAmount(): void
    {
        $attachmentId = $this->createPendingReceipt(null, null, '2026-10-01 12:00:00');

        $this->parserFactory->ibans = [$this->account->iban];
        $this->parserFactory->lines = [$this->line('R1', '2026-10-02', -10.0, 'Achat')];

        $this->import(1000.0, 'a.csv');

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachmentId));
    }
}

/**
 * @internal test double
 */
final class FakeStatementParser implements BankStatementParserInterface
{
    /**
     * @param list<string> $ibans
     * @param StatementLine[] $lines
     * @param array<string, ClosingBalance> $balances
     */
    public function __construct(private array $ibans, private array $lines, private array $balances)
    {
    }

    public function recognizes(string $filePath): bool
    {
        return true;
    }

    public function closingBalances(string $filePath): array
    {
        return $this->balances;
    }

    public function extractAccountIbans(string $filePath): array
    {
        return $this->ibans;
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
 * touches a real bank format, only the configured fake lines/IBANs.
 */
final class FakeBankStatementParserFactory extends BankStatementParserFactory
{
    /** @var list<string> */
    public array $ibans = [];

    /** @var StatementLine[] */
    public array $lines = [];

    /** @var array<string, ClosingBalance> */
    public array $balances = [];

    /** What detect() answers — the format name, or null for "not recognized". */
    public ?string $detected = 'bnp';

    public function create(string $bankCode): BankStatementParserInterface
    {
        return new FakeStatementParser($this->ibans, $this->lines, $this->balances);
    }

    public function detect(string $filePath): ?string
    {
        return $this->detected;
    }
}
