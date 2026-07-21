<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Http\Request;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\MovementController;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ReceiptService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class MovementControllerTest extends TestCase
{
    private \PDO $pdo;
    private MovementController $controller;
    private TransactionRepository $transactionRepository;
    private AccountRepository $accountRepository;
    private FiscalYearRepository $fiscalYearRepository;
    private CategoryRepository $categoryRepository;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->fiscalYearRepository = new FiscalYearRepository($this->pdo, new \Core\Config\ScoutYearService($this->pdo));
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
        $transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $checkpointRepository = new \Modules\Finance\Repository\BalanceCheckpointRepository($this->pdo);
        $balanceService = new \Modules\Finance\Service\BalanceService($checkpointRepository, $this->transactionRepository);
        $financeService = new FinanceService(
            $this->accountRepository, $this->categoryRepository, $this->fiscalYearRepository, $sectionService, $this->transactionRepository, $balanceService
        );
        $fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, sys_get_temp_dir() . '/finance_movement_test_' . uniqid());
        $receiptService = new ReceiptService($attachmentRepository, $this->accountRepository, $transactionAttachmentRepository, $fileStorage);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/finance/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'finance');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'intendant');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/finance/movements');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $this->controller = new MovementController(
            $twig, $financeService, $this->transactionRepository, $this->categoryRepository, $this->fiscalYearRepository,
            $attachmentRepository, $transactionAttachmentRepository, $receiptService, $journalService
        );

        $accountId = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, 'BE00000000000001', 'Titulaire', 'intendant');
        $this->accountRepository->update($accountId, 'Compte', Account::TYPE_BANK, null, 'BE00000000000001', 'Titulaire', 'intendant');
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$accountId]);
        $this->accountId = $accountId;

        $this->fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'intendant@test.be', 'intendant');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function createTransaction(string $date, float $amount, string $label, ?int $categoryId = null): int
    {
        return $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, 'ref-' . uniqid(), $date, $label, $amount, $categoryId, null, Transaction::SOURCE_MANUAL, null
        );
    }

    public function testListDefaultsToCurrentFiscalYear(): void
    {
        $this->createTransaction('2026-10-01', -20.0, 'Achat A');
        $otherYearId = FinanceTestHelper::createScoutYear($this->pdo, '2020-2021', '2020-09-01', '2021-08-31');
        $this->transactionRepository->create($this->accountId, $otherYearId, 'ref-old', '2025-10-01', 'Achat ancien exercice', -5.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->list(new Request('GET', '/finance/movements', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Achat A', $response->getBody());
        $this->assertStringNotContainsString('Achat ancien exercice', $response->getBody());
    }

    public function testListAllFiscalYearsWhenRequested(): void
    {
        $this->createTransaction('2026-10-01', -20.0, 'Achat A');
        $otherYearId = FinanceTestHelper::createScoutYear($this->pdo, '2020-2021', '2020-09-01', '2021-08-31');
        $this->transactionRepository->create($this->accountId, $otherYearId, 'ref-old', '2025-10-01', 'Achat ancien exercice', -5.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->list(new Request('GET', '/finance/movements', ['fiscal_year_id' => 'all'], [], [], []), []);

        $this->assertStringContainsString('Achat A', $response->getBody());
        $this->assertStringContainsString('Achat ancien exercice', $response->getBody());
    }

    public function testListFiltersByCategory(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->createTransaction('2026-10-01', -20.0, 'Delhaize', $categoryId);
        $this->createTransaction('2026-10-02', -10.0, 'Colruyt', null);

        $response = $this->controller->list(new Request('GET', '/finance/movements', ['category_id' => (string) $categoryId], [], [], []), []);

        $this->assertStringContainsString('Delhaize', $response->getBody());
        $this->assertStringNotContainsString('Colruyt', $response->getBody());
    }

    public function testListFiltersBySearchText(): void
    {
        $this->createTransaction('2026-10-01', -20.0, 'Delhaize Bruxelles');
        $this->createTransaction('2026-10-02', -10.0, 'Colruyt Ottignies');

        $response = $this->controller->list(new Request('GET', '/finance/movements', ['q' => 'delhaize'], [], [], []), []);

        $this->assertStringContainsString('Delhaize Bruxelles', $response->getBody());
        $this->assertStringNotContainsString('Colruyt Ottignies', $response->getBody());
    }

    public function testListMarksUncategorizedMovements(): void
    {
        $this->createTransaction('2026-10-01', -20.0, 'Achat sans catégorie', null);

        $response = $this->controller->list(new Request('GET', '/finance/movements', [], [], [], []), []);

        $this->assertStringContainsString('table-warning', $response->getBody());
    }

    public function testListCategoryFilterNoneShowsOnlyUncategorizedMovements(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->createTransaction('2026-10-01', -20.0, 'Delhaize', $categoryId);
        $this->createTransaction('2026-10-02', -10.0, 'Colruyt', null);

        $response = $this->controller->list(new Request('GET', '/finance/movements', ['category_id' => 'none'], [], [], []), []);

        $this->assertStringContainsString('Colruyt', $response->getBody());
        $this->assertStringNotContainsString('Delhaize', $response->getBody());
    }

    public function testListDoesNotRenderExerciceColumn(): void
    {
        $this->createTransaction('2026-10-01', -20.0, 'Achat A');

        $response = $this->controller->list(new Request('GET', '/finance/movements', [], [], [], []), []);

        $this->assertStringNotContainsString('<th>Exercice</th>', $response->getBody());
    }

    public function testListRowsCarryMovementDetailsAsDataAttributes(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $id = $this->createTransaction('2026-10-01', -20.0, 'Achat détaillé', $categoryId);
        $this->transactionRepository->updateEditableFields($id, $categoryId, 'Un commentaire', $this->fiscalYearId);

        $response = $this->controller->list(new Request('GET', '/finance/movements', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('data-id="' . $id . '"', $body);
        $this->assertStringContainsString('data-category-id="' . $categoryId . '"', $body);
        $this->assertStringContainsString('data-comment="Un commentaire"', $body);
        $this->assertStringContainsString('data-fiscal-year-id="' . $this->fiscalYearId . '"', $body);
    }

    public function testListRendersAccountFilterDropdown(): void
    {
        $response = $this->controller->list(new Request('GET', '/finance/movements', [], [], [], []), []);

        $this->assertStringContainsString('id="filter-account"', $response->getBody());
        $this->assertStringContainsString('name="account_id"', $response->getBody());
    }

    public function testListRowsCarryCounterpartyAndExtraDetailsAsDataAttributes(): void
    {
        $id = $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, 'R1', '2026-10-01', 'Achat', -20.0, null, null,
            Transaction::SOURCE_IMPORT, null, 'Jean Dupont', 'BE00000000000009', 'Type : Virement en euros'
        );

        $response = $this->controller->list(new Request('GET', '/finance/movements', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('data-counterparty-name="Jean Dupont"', $body);
        $this->assertStringContainsString('data-counterparty-account="BE00000000000009"', $body);
        $this->assertStringContainsString('data-extra-details="Type : Virement en euros"', $body);
    }

    public function testSearchOrdersByMostRecentWhenQueryGiven(): void
    {
        $this->createTransaction('2026-10-01', -20.0, 'Delhaize Bruxelles');
        $this->createTransaction('2026-10-10', -5.0, 'Delhaize Ottignies');

        $response = $this->controller->search(new Request('GET', '/finance/movements/search', ['q' => 'Delhaize'], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['movements']);
        $this->assertSame('Delhaize Ottignies', $data['movements'][0]['label']);
        $this->assertSame('Delhaize Bruxelles', $data['movements'][1]['label']);
    }

    public function testSearchWithoutQueryButNearDateReturnsClosestMovementsFirst(): void
    {
        $this->createTransaction('2026-10-01', -20.0, 'Loin');
        $this->createTransaction('2026-10-14', -5.0, 'Proche');
        $this->createTransaction('2026-10-31', -5.0, 'Très loin');

        $response = $this->controller->search(new Request('GET', '/finance/movements/search', ['near_date' => '2026-10-15'], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertSame('Proche', $data['movements'][0]['label']);
    }

    public function testSearchWithMalformedNearDateFallsBackGracefully(): void
    {
        $this->createTransaction('2026-10-01', -20.0, 'Achat');

        $response = $this->controller->search(new Request('GET', '/finance/movements/search', ['near_date' => 'not-a-date'], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['movements']);
    }

    public function testSearchWithoutQueryOrNearDateReturnsUpToTwenty(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->createTransaction('2026-10-' . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT), -1.0, "Achat {$i}");
        }

        $response = $this->controller->search(new Request('GET', '/finance/movements/search', [], [], [], []), []);
        $data = json_decode($response->getBody(), true);

        $this->assertCount(20, $data['movements']);
    }

    private function jsonRequest(string $method, array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs([$method, '/finance/movements/1', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    public function testUpdateChangesCategoryCommentAndFiscalYear(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $newFiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $id = $this->createTransaction('2026-10-01', -20.0, 'Achat', null);

        $token = $this->csrfToken();
        $response = $this->controller->update(
            $this->jsonRequest('PATCH', ['category_id' => $categoryId, 'comment' => 'Remboursé', 'fiscal_year_id' => $newFiscalYearId, '_csrf_token' => $token]),
            ['id' => (string) $id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $transaction = $this->transactionRepository->findById($id);
        $this->assertSame($categoryId, $transaction->categoryId);
        $this->assertSame('Remboursé', $transaction->comment);
        $this->assertSame($newFiscalYearId, $transaction->fiscalYearId);
    }

    public function testUpdateNeverTouchesAmountDateLabelOrBankReference(): void
    {
        $id = $this->createTransaction('2026-10-01', -20.0, 'Libellé original');
        $before = $this->transactionRepository->findById($id);

        $token = $this->csrfToken();
        $this->controller->update(
            $this->jsonRequest('PATCH', ['amount' => -999.0, 'transaction_date' => '2020-01-01', 'label' => 'Hacked', 'bank_reference' => 'HACKED', '_csrf_token' => $token]),
            ['id' => (string) $id]
        );

        $after = $this->transactionRepository->findById($id);
        $this->assertSame($before->amount, $after->amount);
        $this->assertSame($before->transactionDate, $after->transactionDate);
        $this->assertSame($before->label, $after->label);
        $this->assertSame($before->bankReference, $after->bankReference);
    }

    public function testUpdateRejectsInvalidCategory(): void
    {
        $id = $this->createTransaction('2026-10-01', -20.0, 'Achat');

        $token = $this->csrfToken();
        $response = $this->controller->update(
            $this->jsonRequest('PATCH', ['category_id' => 9999, '_csrf_token' => $token]),
            ['id' => (string) $id]
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdateRejectsInvalidFiscalYear(): void
    {
        $id = $this->createTransaction('2026-10-01', -20.0, 'Achat');

        $token = $this->csrfToken();
        $response = $this->controller->update(
            $this->jsonRequest('PATCH', ['fiscal_year_id' => 9999, '_csrf_token' => $token]),
            ['id' => (string) $id]
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdateRejectsInvalidCsrfToken(): void
    {
        $this->csrfToken();
        $id = $this->createTransaction('2026-10-01', -20.0, 'Achat');

        $response = $this->controller->update(
            $this->jsonRequest('PATCH', ['comment' => 'x', '_csrf_token' => 'bad-token']),
            ['id' => (string) $id]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateReturns404ForUnknownMovement(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->update(
            $this->jsonRequest('PATCH', ['comment' => 'x', '_csrf_token' => $token]),
            ['id' => '9999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateRejectsAccessBelowAccountRoleFloor(): void
    {
        $restrictedAccountId = $this->accountRepository->create('Compte restreint', Account::TYPE_BANK, null, 'BE00000000000002', 'Titulaire', 'admin');
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$restrictedAccountId]);
        $id = $this->transactionRepository->create($restrictedAccountId, $this->fiscalYearId, 'ref-x', '2026-10-01', 'Achat', -20.0, null, null, Transaction::SOURCE_MANUAL, null);

        $token = $this->csrfToken();
        $response = $this->controller->update(
            $this->jsonRequest('PATCH', ['comment' => 'x', '_csrf_token' => $token]),
            ['id' => (string) $id]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAttachmentsIncludesReceiptSuggestedAmountLabelAndDescription(): void
    {
        $transactionId = $this->createTransaction('2026-10-01', -12.5, 'Achat');

        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('a.pdf', 'a.pdf', 'application/pdf', 100)"
        );
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();

        $attachmentRepository = new AttachmentRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $attachmentId = $attachmentRepository->create($this->accountId, $fileId, 'application/pdf', 'facture.pdf', 12.5, '2026-10-01', null, 1);
        $attachmentRepository->updateSuggestedLabel($attachmentId, 'Delhaize');
        $attachmentRepository->updateSuggestedDescription($attachmentId, 'Achat de fournitures de bureau');
        (new TransactionAttachmentRepository($this->pdo))->associate($transactionId, $attachmentId);

        $response = $this->controller->attachments(new Request('GET', '/finance/movements/' . $transactionId . '/attachments', [], [], [], []), ['id' => (string) $transactionId]);
        $data = json_decode($response->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['attachments']);
        $this->assertSame(12.5, $data['attachments'][0]['suggested_amount']);
        $this->assertSame('Delhaize', $data['attachments'][0]['suggested_label']);
        $this->assertSame('Achat de fournitures de bureau', $data['attachments'][0]['suggested_description']);
    }
}
