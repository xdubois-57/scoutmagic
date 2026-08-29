<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\PdfTextExtractor;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\FeeCategoryRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Fees\Controller\InvoiceController;
use Modules\Fees\Invoice\InvoiceParser;
use Modules\Fees\Invoice\InvoiceReader;
use Modules\Fees\Invoice\ParsedInvoice;
use Modules\Fees\Repository\FeesImportRepository;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Repository\InvoiceMemberMatchRepository;
use Modules\Fees\Repository\InvoiceRepository;
use Core\Import\RosterSnapshotRepository;
use Modules\Fees\Service\HouseholdTariffService;
use Modules\Fees\Service\InvoiceImportService;
use Modules\Fees\Service\InvoiceSeasonService;
use Modules\Fees\Service\InvoiceVerificationService;
use Modules\Finance\Api\ExpenseReceiptInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;
use Twig\Environment;

/**
 * The two screens and their RBAC boundary: every route allowed at `admin`
 * — the espace_admin menu's own floor — and refused one level below at
 * `chief`, the POST included.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InvoiceControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private InvoiceRepository $invoices;
    private RosterSnapshotRepository $snapshots;
    private int $scoutYearId;
    private string $pdf;
    /** @var array<string, mixed> */
    private array $savedFiles = [];

    private const DOCUMENT_SECTIONS = ['SV025B1', 'SV025L1', 'STAFFDU', 'SV025E1'];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->scoutYearId = $scoutYearService->ensureYear('2025-2026');
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->scoutYearId);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOUV', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        foreach (self::DOCUMENT_SECTIONS as $code) {
            $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, '{$code}', 'Section {$code}')");
        }

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = TwigFactory::create($templateDir, false, ['fees' => dirname(__DIR__, 4) . '/modules/fees/views']);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/admin/fees/factures');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig = $twig;

        $this->invoices = new InvoiceRepository($this->pdo);
        $this->snapshots = new RosterSnapshotRepository($this->pdo);

        $content = file_get_contents(dirname(__DIR__, 4) . '/tests/fixtures/pdf/federation_invoice_sample.pdf');
        $this->assertIsString($content);
        $this->pdf = $content;

        // Another test in the suite may have unset the superglobal
        // entirely; restoring it as an array is what the CLI SAPI's own
        // starting state is.
        $this->savedFiles = $_FILES ?? [];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        $_FILES = $this->savedFiles;
        AuthSession::logout();
    }

    private function controller(?ExpenseReceiptInterface $receipts = null): InvoiceController
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearResolver = new ScoutYearResolver(
            new ScoutYearService($this->pdo),
            $settingService,
            new MemberYearRepository($this->pdo)
        );
        $sections = new SectionService(Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo));
        $journal = new JournalService(new JournalRepository($this->pdo));

        return new InvoiceController(
            $this->twig,
            new InvoiceImportService(
                new InvoiceReader(new PdfTextExtractor(), new InvoiceParser()),
                $this->invoices,
                new InvoiceMemberMatchRepository($this->pdo, $encryption),
                $this->snapshots,
                $sections,
                $journal
            ),
            new InvoiceSeasonService($this->invoices),
            new InvoiceVerificationService(
                $this->invoices,
                $this->snapshots,
                new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), new FeeCategoryRepository($this->pdo)),
                $sections,
                new HouseholdDetailRepository($this->pdo, $encryption)
            ),
            $this->invoices,
            $this->snapshots,
            new FeesImportRepository($this->pdo),
            $scoutYearResolver,
            [],
            $journal,
            $receipts
        );
    }

    // --- RBAC ---------------------------------------------------------

    public function testAdminReachesTheSeason(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch('GET', '/admin/fees/factures', 'index');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('Factures de la fédération', (string) $response->getBody());
    }

    public function testChiefIsRefusedTheSeason(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $this->assertSame(403, $this->dispatch('GET', '/admin/fees/factures', 'index')->getStatusCode());
    }

    public function testAdminReachesTheImportScreen(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch('GET', '/admin/fees/factures/import', 'form');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testChiefIsRefusedTheImportScreen(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $this->assertSame(403, $this->dispatch('GET', '/admin/fees/factures/import', 'form')->getStatusCode());
    }

    public function testChiefIsRefusedTheUploadItself(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');
        $this->givenAnUploadedFile($this->pdf);

        $response = $this->dispatch('POST', '/admin/fees/factures/import', 'upload', ['_csrf_token' => CsrfGuard::generateToken()]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    // --- Dépôt --------------------------------------------------------

    public function testTheDepositScreenRemindsTheDeskImportDateBeforeAnyFileIsChosen(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, user_account_id, line_count, member_count, new_functions_count, imported_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->scoutYearId, null, 10, 8, 0, '2026-01-05 08:29:00']);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/factures/import', 'form')->getBody();

        $this->assertStringContainsString('Dernier import Desk', $body);
        $this->assertStringContainsString('05/01/2026 à 08:29', $body);
    }

    public function testAGoodFileIsImportedAndRedirectsToTheSeason(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->givenAnUploadedFile($this->pdf);

        $response = $this->dispatch('POST', '/admin/fees/factures/import', 'upload', ['_csrf_token' => CsrfGuard::generateToken()]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/fees/factures', $response->getHeaders()['Location'] ?? null);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    public function testNoFileAtAllIsSaidPlainlyRatherThanCrashing(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $_FILES = [];

        $response = $this->dispatch('POST', '/admin/fees/factures/import', 'upload', ['_csrf_token' => CsrfGuard::generateToken()]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aucun fichier fourni', (string) $response->getBody());
    }

    // --- Total incohérent ---------------------------------------------

    public function testAFileThatDoesNotAddUpNamesTheFailingRowAndStoresNothing(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->givenAnUploadedFile(str_replace('468,00', '468,01', $this->pdf));

        $body = (string) $this->dispatch('POST', '/admin/fees/factures/import', 'upload', ['_csrf_token' => CsrfGuard::generateToken()])->getBody();

        $this->assertStringContainsString('Total incohérent', $body);
        $this->assertStringContainsString('COT_NORM', $body);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    // --- Roster périmé ------------------------------------------------

    public function testASectionTheSiteDoesNotKnowAsksForADeskImportRatherThanAMapping(): void
    {
        $this->pdo->exec("DELETE FROM sections WHERE desk_code = 'SV025E1'");
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->givenAnUploadedFile($this->pdf);

        $body = (string) $this->dispatch('POST', '/admin/fees/factures/import', 'upload', ['_csrf_token' => CsrfGuard::generateToken()])->getBody();

        $this->assertStringContainsString('Roster périmé', $body);
        $this->assertStringContainsString('SV025E1', $body);
        $this->assertStringContainsString('/admin/import', $body);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    public function testTheSameDocumentTwiceSaysSoInsteadOfDuplicatingIt(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->givenAnUploadedFile($this->pdf);
        $this->dispatch('POST', '/admin/fees/factures/import', 'upload', ['_csrf_token' => CsrfGuard::generateToken()]);

        $this->givenAnUploadedFile($this->pdf);
        $body = (string) $this->dispatch('POST', '/admin/fees/factures/import', 'upload', ['_csrf_token' => CsrfGuard::generateToken()])->getBody();

        $this->assertStringContainsString('déjà là', $body);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_invoices')->fetchColumn());
    }

    // --- The season ---------------------------------------------------

    public function testTheSeasonListsTheDocumentsInOrderWithTheirRunningTotal(): void
    {
        $this->storeInvoice('F2025/000900', '2025-11-04', 30000);
        $this->storeInvoice('F2026/000123', '2026-01-08', 106900);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/factures', 'index')->getBody();

        $this->assertStringContainsString('F2025/000900', $body);
        $this->assertStringContainsString('F2026/000123', $body);
        $this->assertStringContainsString('1 369,00 €', $body, 'The running total must net the deposit out, not double it.');
        $this->assertLessThan(
            strpos($body, 'F2026/000123'),
            (int) strpos($body, 'F2025/000900'),
            'The season is read in the order it happened.'
        );
    }

    public function testAnEmptySeasonOffersTheImportRatherThanAnEmptyTable(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/factures', 'index')->getBody();

        $this->assertStringContainsString('Aucune facture importée', $body);
        $this->assertStringContainsString('/admin/fees/factures/import', $body);
    }

    // --- The kept PDF (optional finance dependency, §7.5) --------------

    public function testWithFinanceDisabledTheKeepThePdfControlIsNotOffered(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/factures/import', 'form')->getBody();

        $this->assertStringNotContainsString('finance_account_id', $body);
    }

    public function testWithFinanceEnabledTheAccountsItAllowsAreOffered(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/factures/import', 'form', [], $this->fakeReceipts())->getBody();

        $this->assertStringContainsString('finance_account_id', $body);
        $this->assertStringContainsString('Compte courant', $body);
    }

    public function testAKeptPdfIsRememberedOnTheInvoice(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->givenAnUploadedFile($this->pdf);
        $receipts = $this->fakeReceipts();

        $this->dispatch(
            'POST',
            '/admin/fees/factures/import',
            'upload',
            ['_csrf_token' => CsrfGuard::generateToken(), 'finance_account_id' => '3'],
            $receipts
        );

        $stored = $this->invoices->findByDocumentNumber($this->scoutYearId, 'F2026/000123');
        $this->assertNotNull($stored);
        $this->assertSame(555, $stored->financeFileId);
        $this->assertSame('application/pdf', $receipts->lastMimeType, 'The type is detected, never the client-declared one.');
    }

    /**
     * The verification is what the treasurer came for; the document is
     * still in their downloads. A storage failure must not lose the
     * import.
     */
    public function testAFinanceFailureWarnsButKeepsTheImport(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->givenAnUploadedFile($this->pdf);
        $receipts = $this->fakeReceipts(true);

        $response = $this->dispatch(
            'POST',
            '/admin/fees/factures/import',
            'upload',
            ['_csrf_token' => CsrfGuard::generateToken(), 'finance_account_id' => '3'],
            $receipts
        );

        $this->assertSame(302, $response->getStatusCode());
        $stored = $this->invoices->findByDocumentNumber($this->scoutYearId, 'F2026/000123');
        $this->assertNotNull($stored);
        $this->assertNull($stored->financeFileId);
    }

    public function testNotAskingForTheAccountKeepsNoPdf(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->givenAnUploadedFile($this->pdf);
        $receipts = $this->fakeReceipts();

        $this->dispatch('POST', '/admin/fees/factures/import', 'upload', ['_csrf_token' => CsrfGuard::generateToken()], $receipts);

        $this->assertNull($this->invoices->findByDocumentNumber($this->scoutYearId, 'F2026/000123')?->financeFileId);
        $this->assertFalse($receipts->stored);
    }

    // --- helpers -------------------------------------------------------

    private function fakeReceipts(bool $fail = false): ExpenseReceiptInterface
    {
        return new class ($fail) implements ExpenseReceiptInterface {
            public bool $stored = false;
            public ?string $lastMimeType = null;

            public function __construct(private bool $fail)
            {
            }

            public function receiptAccounts(string $actorRole, array $actorLinkedMemberIds): array
            {
                return [3 => 'Compte courant'];
            }

            public function storeReceipt(
                string $content,
                string $mimeType,
                string $originalFilename,
                int $accountId,
                ?float $suggestedAmount,
                ?string $suggestedDate,
                string $actorRole,
                array $actorLinkedMemberIds,
                ?int $uploadedBy
            ): int {
                if ($this->fail) {
                    throw new \Modules\Finance\Api\FinanceException('Compte introuvable.');
                }
                $this->stored = true;
                $this->lastMimeType = $mimeType;

                return 555;
            }
        };
    }

    private function givenAnUploadedFile(string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fees_invoice_');
        $this->assertIsString($path);
        file_put_contents($path, $content);

        $_FILES = ['invoice' => [
            'name' => 'facture.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
        ]];
    }

    private function storeInvoice(string $number, string $date, int $totalCents): void
    {
        $this->invoices->store(
            new ParsedInvoice($number, $date, [], $totalCents, null, null, null, 0),
            $this->scoutYearId,
            null,
            null,
            [],
            []
        );
    }

    /** @param array<string, mixed> $body */
    private function dispatch(
        string $method,
        string $path,
        string $action,
        array $body = [],
        ?ExpenseReceiptInterface $receipts = null
    ): \Core\Http\Response {
        $controller = $this->controller($receipts);

        $router = new Router();
        $router->addRoute($method, $path, InvoiceController::class, $action, 'admin');

        $configFile = sys_get_temp_dir() . '/test_fees_invoices_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $fc = new FrontController($router, $this->twig, new AppConfig($configFile));
        $fc->registerController(InvoiceController::class, $controller);

        return $fc->handle(new Request($method, $path, [], $body, [], []));
    }
}
