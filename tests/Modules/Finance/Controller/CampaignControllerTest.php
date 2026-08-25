<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Badge\MemberBadgeRepository;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Controller\CampaignController;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\MemberLookupRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountTransferCategoryService;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\CampaignExportService;
use Modules\Finance\Service\CampaignImportService;
use Modules\Finance\Service\CampaignOverviewService;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Finance\Service\TreasurerScope;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampaignControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampaignController $controller;
    private CampaignService $campaignService;
    private CampaignRepository $campaigns;
    private CampaignRowRepository $rows;
    private ExpectedReceivableRepository $receivables;
    private int $accountId;
    private int $scoutYearId;
    /** @var array<string, int> */
    private array $memberIds = [];
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $transactionRepository = new TransactionRepository($this->pdo, $this->encryption);
        $categoryRepository = new CategoryRepository($this->pdo);
        $categoryRuleRepository = new \Modules\Finance\Repository\CategoryRuleRepository($this->pdo);
        $checkpointRepository = new \Modules\Finance\Repository\BalanceCheckpointRepository($this->pdo);
        $scoutYearService = new ScoutYearService($this->pdo);
        $accountVisibility = new AccountVisibility(TreasurerScope::systemCaller());

        $financeService = new FinanceService(
            $accountRepository,
            $categoryRepository,
            new FiscalYearRepository($this->pdo, $scoutYearService),
            new SectionService(Connection::withPdo($this->pdo), $this->encryption, new MemberBadgeRepository($this->pdo)),
            $transactionRepository,
            new BalanceService($checkpointRepository, $transactionRepository),
            new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
            $categoryRuleRepository,
            new AccountTransferCategoryService($categoryRepository, $categoryRuleRepository, $transactionRepository),
            $accountVisibility
        );

        $this->campaigns = new CampaignRepository($this->pdo);
        $this->rows = new CampaignRowRepository($this->pdo, $this->encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $allocations = FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables);

        $this->campaignService = new CampaignService(
            $this->pdo,
            $this->campaigns,
            $this->rows,
            new CampaignImportService(new MemberLookupRepository($this->pdo)),
            FinanceTestHelper::receivableService($this->pdo, $this->encryption, $this->receivables),
            new StructuredCommunicationService($this->receivables),
            $accountRepository,
            $accountVisibility,
            new EncryptedFileStorageService(new FileRepository($this->pdo), $this->encryption, sys_get_temp_dir()),
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->controller = new CampaignController(
            $this->twig(),
            $this->campaignService,
            new CampaignOverviewService(
                $this->campaigns,
                $this->rows,
                $this->receivables,
                $allocations,
                $accountRepository,
                $accountVisibility,
                new MemberService(new MemberYearRepository($this->pdo), $this->encryption, Connection::withPdo($this->pdo)),
                new UserAccountRepository($this->pdo, $this->encryption)
            ),
            new CampaignExportService(),
            $financeService,
            $allocations,
            $scoutYearService
        );

        $this->accountId = $accountRepository->create('Compte Unité', Account::TYPE_BANK, null, 'BE00000000000001', 'Titulaire', 'intendant');
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$this->accountId]);

        $this->scoutYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);

        foreach (['D-100', 'D-200'] as $deskId) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute([$deskId]);
            $this->memberIds[$deskId] = (int) $this->pdo->lastInsertId();
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'intendant@test.be', 'intendant');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    // ── the screens ─────────────────────────────────────────────────────

    public function testTheListRendersEvenWithNoCampaign(): void
    {
        $response = $this->controller->index(new Request('GET', '/finance/campaigns', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aucune campagne', $response->getBody());
    }

    public function testTheFormExplainsWhereTheFileHasToComeFrom(): void
    {
        $response = $this->controller->form(new Request('GET', '/finance/campaigns/new', [], [], [], []), []);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('ID interne', $body);
        $this->assertStringContainsString('Ne reconstruisez pas la liste à la main', $body);
    }

    public function testUploadingAValidFileCreatesTheCampaignAndGoesToIt(): void
    {
        $response = $this->upload('Cotisations 2025-2026', [
            [$this->memberIds['D-100'], '45,00'],
            [$this->memberIds['D-200'], '38,25'],
        ]);

        $this->assertSame(302, $response->getStatusCode(), $response->getBody());
        $this->assertSame('/finance/campaigns/1', $response->getHeaders()['Location'] ?? null);
        $this->assertSame(2, $this->rows->countByCampaignId(1));
    }

    /**
     * A refusal that sends somebody to a help page without explaining
     * anything on the spot is a refusal done badly: the offending lines
     * are named on the page, with what the file says on them.
     */
    public function testARefusedFileNamesEveryOffendingLineOnThePage(): void
    {
        $response = $this->upload('Cotisations', [
            [$this->memberIds['D-100'], '45,00'],
            ['4821', '45,00'],
        ]);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('refusé', $body);
        $this->assertStringContainsString('4821', $body);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_campaigns')->fetchColumn());
    }

    public function testTheDetailPageSaysHowManyLinesTheExportWillTake(): void
    {
        $campaignId = $this->createCampaign();

        $response = $this->controller->show(
            new Request('GET', '/finance/campaigns/' . $campaignId, [], [], [], []),
            ['id' => (string) $campaignId]
        );

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString("L'export reprend les 2 créances affichées", $body);
        $this->assertStringContainsString('Exporter (2)', $body);
    }

    public function testAnUnknownCampaignIsANotFoundRatherThanAnError(): void
    {
        $response = $this->controller->show(new Request('GET', '/finance/campaigns/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ── the export follows the filter ───────────────────────────────────

    /**
     * Exporting 262 lines while looking at the 41 unpaid ones is a
     * surprise; exporting 41 while believing you have 262 is worse.
     */
    public function testTheExportCarriesExactlyTheLinesTheFilterShows(): void
    {
        $campaignId = $this->createCampaign();
        $rows = $this->rows->findByCampaignId($campaignId);
        $paid = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $rows[0]->id)[0];

        (new TransactionRepository($this->pdo, $this->encryption))->create(
            $this->accountId, $this->scoutYearId, 'REF-1', '2026-02-18',
            'Virement ' . $paid->communication, 45.00, null, null, 'import', null
        );

        $all = $this->exportRowCount($campaignId, 'all');
        $paidOnly = $this->exportRowCount($campaignId, 'paid');
        $todo = $this->exportRowCount($campaignId, 'todo');

        $this->assertSame(2, $all);
        $this->assertSame(1, $paidOnly);
        $this->assertSame(1, $todo);
    }

    // ── the gestures ────────────────────────────────────────────────────

    public function testSavingANoteWritesItAndComesBackToTheSameFilter(): void
    {
        $campaignId = $this->createCampaign();
        $rowId = $this->rows->findByCampaignId($campaignId)[0]->id;

        $response = $this->controller->saveNote(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'note' => 'Rappel fait', 'filter' => 'all'], [], []),
            ['id' => (string) $campaignId, 'rowId' => (string) $rowId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/finance/campaigns/' . $campaignId . '?filter=all', $response->getHeaders()['Location'] ?? null);
        $this->assertSame('Rappel fait', $this->rows->findById($rowId)?->note);
    }

    public function testANoteIsNotWrittenWithoutAValidCsrfToken(): void
    {
        $campaignId = $this->createCampaign();
        $rowId = $this->rows->findByCampaignId($campaignId)[0]->id;

        $this->controller->saveNote(
            new Request('POST', '/x', [], ['_csrf_token' => 'wrong', 'note' => 'Rappel fait'], [], []),
            ['id' => (string) $campaignId, 'rowId' => (string) $rowId]
        );

        $this->assertNull($this->rows->findById($rowId)?->note);
    }

    /**
     * Abandoning settles the receivable and nothing enters the account —
     * which is exactly why it is not recorded as a payment.
     */
    public function testWaivingSettlesTheReceivableWithoutAnyMoneyComingIn(): void
    {
        $campaignId = $this->createCampaign();
        $rowId = $this->rows->findByCampaignId($campaignId)[0]->id;
        $receivableId = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $rowId)[0]->id;

        $response = $this->controller->waive(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'waived' => '1', 'filter' => 'all'], [], []),
            ['id' => (string) $campaignId, 'receivableId' => (string) $receivableId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $receivable = $this->receivables->findById($receivableId);
        $this->assertNotNull($receivable);
        $this->assertTrue($receivable->isWaived());

        $status = FinanceTestHelper::receivableService($this->pdo, $this->encryption, $this->receivables)
            ->getReceivableStatus($receivableId);
        $this->assertSame('waived', $status['status']);
        $this->assertSame(0, $status['amount_received']);
    }

    public function testClosingACampaignKeepsItReadable(): void
    {
        $campaignId = $this->createCampaign();

        $this->controller->updateStatus(
            new Request('POST', '/x', [], ['_csrf_token' => $this->csrfToken(), 'status' => 'closed'], [], []),
            ['id' => (string) $campaignId]
        );

        $this->assertFalse($this->campaigns->findById($campaignId)?->isOpen());
        $response = $this->controller->show(
            new Request('GET', '/finance/campaigns/' . $campaignId, [], [], [], []),
            ['id' => (string) $campaignId]
        );
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Clôturée', $response->getBody());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function createCampaign(): int
    {
        $response = $this->upload('Cotisations 2025-2026', [
            [$this->memberIds['D-100'], '45,00'],
            [$this->memberIds['D-200'], '38,25'],
        ]);
        self::assertSame(302, $response->getStatusCode(), $response->getBody());

        return 1;
    }

    /**
     * @param array<int, array<int, string|int>> $lines
     */
    private function upload(string $label, array $lines): \Core\Http\Response
    {
        $path = $this->spreadsheet($lines);

        // Core\Http\Request::getFile() reads the superglobal, exactly as
        // PHP fills it — so the test has to as well.
        $_FILES['spreadsheet'] = [
            'name' => 'cotisations.xlsx',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path) ?: 0,
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        try {
            return $this->controller->create(
                new Request(
                    'POST',
                    '/finance/campaigns',
                    [],
                    [
                        '_csrf_token' => $this->csrfToken(),
                        'label' => $label,
                        'scout_year_id' => (string) $this->scoutYearId,
                        'account_id' => (string) $this->accountId,
                    ],
                    [],
                    []
                ),
                []
            );
        } finally {
            unset($_FILES['spreadsheet']);
        }
    }

    private function exportRowCount(int $campaignId, string $filter): int
    {
        $response = $this->controller->export(
            new Request('GET', '/finance/campaigns/' . $campaignId . '/export', ['filter' => $filter], [], [], []),
            ['id' => (string) $campaignId]
        );
        self::assertSame(200, $response->getStatusCode());

        $path = (tempnam(sys_get_temp_dir(), 'campaign_export_') ?: '') . '.xlsx';
        $this->tempFiles[] = $path;
        file_put_contents($path, $response->getBody());

        $spreadsheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
        $highestRow = $spreadsheet->getSheet(0)->getHighestDataRow();
        $spreadsheet->disconnectWorksheets();

        return $highestRow - 1; // minus the header row
    }

    /**
     * @param array<int, array<int, string|int>> $lines
     */
    private function spreadsheet(array $lines): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue([1, 1], 'ID interne');
        $sheet->setCellValue([2, 1], 'Montant');
        foreach ($lines as $rowIndex => $cells) {
            foreach ($cells as $cellIndex => $value) {
                $sheet->setCellValue([$cellIndex + 1, $rowIndex + 2], $value);
            }
        }

        $path = (tempnam(sys_get_temp_dir(), 'campaign_') ?: '') . '.xlsx';
        $this->tempFiles[] = $path;
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    private function twig(): Environment
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath(dirname(__DIR__, 4) . '/modules/finance/views', 'finance');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);

        $twig->addFilter(new \Twig\TwigFilter('date_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')));
        $twig->addFilter(new \Twig\TwigFilter('datetime_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')));
        $twig->addFilter(new \Twig\TwigFilter('money', fn($a) => $a === null || $a === '' ? '' : number_format((float) $a, 2, ',', ' ') . ' €'));
        $twig->addFilter(new \Twig\TwigFilter('money_cents', fn($c) => $c === null || $c === '' ? '' : number_format(((int) $c) / 100, 2, ',', ' ') . ' €'));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'intendant');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/finance/campaigns');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        return $twig;
    }
}
