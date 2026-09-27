<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ImportController;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AiCategorySuggestionRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AiCategorizationService;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\BulkCategorizationService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ImportService;
use Modules\Finance\Service\ReceiptMatchingService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Tests\TestTwig;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ImportControllerTest extends TestCase
{
    private \PDO $pdo;
    private ImportController $controller;
    private AccountRepository $accountRepository;
    private BalanceCheckpointRepository $checkpointRepository;
    private int $accountId;
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($this->pdo))
);

        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $statementImportRepository = new StatementImportRepository($this->pdo);
        $fiscalYearRepository = new FiscalYearRepository($this->pdo, new \Core\Config\ScoutYearService($this->pdo));
        $categoryRepository = new CategoryRepository($this->pdo);
        $categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $ruleEngine = new CategoryRuleEngine($transactionRepository, $categoryRuleRepository);
        $balanceService = new BalanceService($this->checkpointRepository, $transactionRepository);
        $parserFactory = new BankStatementParserFactory();

        $settingService = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $accountTransferCategoryService = new \Modules\Finance\Service\AccountTransferCategoryService(
            $categoryRepository, $categoryRuleRepository, $transactionRepository
        );
        $financeService = new FinanceService(
            $this->accountRepository, $categoryRepository, $fiscalYearRepository, $sectionService, $transactionRepository, $balanceService,
            $settingService, $categoryRuleRepository, $accountTransferCategoryService,
            new \Modules\Finance\Service\AccountVisibility(
                // No badge assigned in these fixtures, so the treasurer
                // rule is off and the module behaves exactly as it did
                // before it existed — which is what these tests assert.
                \Modules\Finance\Service\TreasurerScope::systemCaller()
            )
        );
        $receiptMatchingService = new ReceiptMatchingService(
            new AttachmentRepository($this->pdo, $encryption), $transactionRepository, new TransactionAttachmentRepository($this->pdo),
            new JournalService(new JournalRepository($this->pdo))
        );
        $aiService = new AiCategorizationService(
            null, $categoryRepository, new AiCategorySuggestionRepository($this->pdo), new JournalService(new JournalRepository($this->pdo))
        );
        $bulkCategorizationService = new BulkCategorizationService(
            $transactionRepository, $ruleEngine, $aiService, $settingService, new SchedulerService(new SchedulerRepository($this->pdo))
        );
        $importService = new ImportService(
            $this->pdo, $encryption, $parserFactory, $this->accountRepository, $transactionRepository,
            $this->checkpointRepository, $statementImportRepository, $fiscalYearRepository, $ruleEngine, $balanceService,
            $receiptMatchingService, $bulkCategorizationService,
            FinanceTestHelper::allocationService($this->pdo, $encryption)
        );

        $moduleViews = dirname(__DIR__, 4) . '/modules/finance/views';
        $twig = TestTwig::create(['finance' => $moduleViews]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'intendant');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/finance/import');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new ImportController($twig, $financeService, $importService, $parserFactory);

        $accountId = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, 'BE00000000000001', 'Titulaire', 'intendant');
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$accountId]);
        $this->accountId = $accountId;

        FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');

        $this->fixturePath = dirname(__DIR__, 3) . '/fixtures/finance/bnp_statement_sample.csv';

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'intendant@test.be', 'intendant');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * Neither an account nor a format to choose: the file's own IBANs
     * decide where its lines go, and its structure what it is.
     */
    public function testTheFormOffersNeitherAnAccountNorAFormatToChoose(): void
    {
        $response = $this->controller->form(new Request('GET', '/finance/import', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('name="account_id"', $response->getBody());
        $this->assertStringNotContainsString('name="bank_code"', $response->getBody());
        $this->assertStringContainsString('name="statement"', $response->getBody());
    }

    /**
     * The list of formats appears after a failed detection, and only then.
     */
    public function testAFileNoFormatRecognizesBringsTheFormBackWithTheFormatList(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'finance_upload_');
        file_put_contents($path, "Date;Montant\n01/10/2026;10,00\n");

        $response = $this->controller->upload($this->uploadRequest(null, $path, bankCode: ''), []);

        $this->assertStringContainsString('Nous n&#039;avons pas reconnu ce fichier', $response->getBody());
        $this->assertStringContainsString('name="bank_code"', $response->getBody());
        $this->assertStringContainsString('CODA (toutes les banques belges)', $response->getBody());
        $this->assertSame(0, $this->countTransactions());
    }

    public function testAFileIsImportedWithoutItsFormatBeingNamed(): void
    {
        $response = $this->controller->upload($this->uploadRequest(1000.0, $this->tmpCopyOfFixture(), bankCode: ''), []);

        $this->assertStringContainsString('/finance/movements?account_id=' . $this->accountId, $response->getBody());
        $this->assertGreaterThan(0, $this->countTransactions());
    }

    private function tmpCopyOfFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'finance_upload_');
        copy($this->fixturePath, $path);
        return $path;
    }

    /**
     * A valid CSRF token in the session, matching what the form now sends
     * ({{ csrf_field() }} in @finance/import/form.html.twig).
     */
    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    private function uploadRequest(?float $balance, string $tmpFilePath, ?string $csrfToken = null, string $bankCode = 'bnp'): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/finance/import', [], [
                '_csrf_token' => $csrfToken ?? $this->csrfToken(),
                'bank_code' => $bankCode,
                'balance' => $balance !== null ? (string) $balance : '',
            ], [], []])
            ->onlyMethods(['getFile'])
            ->getMock();
        $request->method('getFile')->willReturn([
            'tmp_name' => $tmpFilePath,
            'name' => 'releve.csv',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpFilePath),
        ]);
        return $request;
    }

    public function testUploadSucceedsAndDeletesTemporaryFile(): void
    {
        $tmp = $this->tmpCopyOfFixture();

        $response = $this->controller->upload($this->uploadRequest(1000.0, $tmp), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('nouvelle', $response->getBody());
        $this->assertFileDoesNotExist($tmp);
    }

    /**
     * The file's IBAN (the fixture's BE00000000000001) belongs to no site
     * account: its lines are set aside and named, nothing is written.
     */
    public function testUploadReportsAnIbanNoAccountCarries(): void
    {
        $this->pdo->prepare('UPDATE finance_accounts SET iban_blind_index = NULL WHERE id = ?')->execute([$this->accountId]);

        $response = $this->controller->upload($this->uploadRequest(1000.0, $this->tmpCopyOfFixture()), []);

        $this->assertStringContainsString('BE00 0000 0000 0001', $response->getBody());
        $this->assertStringContainsString('aucun compte du site ne porte cet IBAN', $response->getBody());
        $this->assertStringContainsString("Aucun mouvement n'a été importé", $response->getBody());
        $this->assertSame(0, $this->countTransactions());
    }

    public function testUploadRejectsMissingBalanceOnFirstImport(): void
    {
        $tmp = $this->tmpCopyOfFixture();
        $response = $this->controller->upload($this->uploadRequest(null, $tmp), []);

        $this->assertStringContainsString('obligatoire', $response->getBody());
    }

    /**
     * Regression: POST /finance/import used to accept a request with no
     * CSRF token at all — the only mutation endpoint in the module that
     * did (AGENTS.md security checklist #7).
     */
    public function testUploadRejectsAMissingCsrfToken(): void
    {
        $tmp = $this->tmpCopyOfFixture();
        $request = $this->uploadRequest(1000.0, $tmp, '');

        $response = $this->controller->upload($request, []);

        $this->assertStringContainsString('Votre session a expiré', $response->getBody());
        $this->assertSame(0, $this->countTransactions(), 'no movement may be imported without a valid token');
    }

    public function testUploadRejectsAForgedCsrfToken(): void
    {
        $tmp = $this->tmpCopyOfFixture();
        $this->csrfToken();
        $request = $this->uploadRequest(1000.0, $tmp, str_repeat('f', 64));

        $response = $this->controller->upload($request, []);

        $this->assertStringContainsString('Votre session a expiré', $response->getBody());
        $this->assertSame(0, $this->countTransactions());
    }

    /**
     * The route's role_min is 'intendant', but each account carries its own
     * role_min_view on top of it. With no account chosen any more, the
     * boundary moves into the import itself: a file whose IBAN belongs to an
     * admin-only account imports nothing — no movement, no checkpoint — and
     * says the account is out of reach, without naming it.
     */
    public function testUploadWritesNothingIntoAnAccountAboveTheCallersRole(): void
    {
        $this->pdo->prepare("UPDATE finance_accounts SET role_min_view = 'admin' WHERE id = ?")->execute([$this->accountId]);

        $response = $this->controller->upload($this->uploadRequest(1000.0, $this->tmpCopyOfFixture()), []);

        $this->assertStringContainsString("vous n'avez pas accès au compte", $response->getBody());
        $this->assertStringNotContainsString('Voir les mouvements', $response->getBody());
        $this->assertSame(0, $this->countTransactions());
        $this->assertFalse(
            $this->checkpointRepository->hasAnyForAccount($this->accountId),
            'no balance checkpoint may be written into an account the caller cannot see'
        );
    }

    /**
     * The boundary's other side: at the account's own floor the import
     * goes through, so the guard above rejects on role, not by accident.
     */
    public function testUploadImportsIntoAnAccountAtTheCallersOwnRole(): void
    {
        $response = $this->controller->upload($this->uploadRequest(1000.0, $this->tmpCopyOfFixture()), []);

        $this->assertStringContainsString('Compte', $response->getBody());
        $this->assertStringContainsString('/finance/movements?account_id=' . $this->accountId, $response->getBody());
        $this->assertGreaterThan(0, $this->countTransactions());
    }

    private function countTransactions(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn();
    }
}
