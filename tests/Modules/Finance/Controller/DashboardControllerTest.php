<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\Request;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\DashboardController;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpoint;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ReceiptService;
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
class DashboardControllerTest extends TestCase
{
    private \PDO $pdo;
    private DashboardController $controller;
    private AccountRepository $accountRepository;
    private FiscalYearRepository $fiscalYearRepository;
    private CategoryRepository $categoryRepository;
    private TransactionRepository $transactionRepository;
    private BalanceCheckpointRepository $checkpointRepository;
    private AttachmentRepository $attachmentRepository;
    private TransactionAttachmentRepository $transactionAttachmentRepository;
    private \Modules\Finance\Repository\ExpectedReceivableRepository $receivableRepository;
    private \Modules\Finance\Service\ReceivableAllocationService $allocationService;

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
        $this->fiscalYearRepository = new FiscalYearRepository($this->pdo, new \Core\Config\ScoutYearService($this->pdo));
        $this->categoryRepository = new CategoryRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $balanceService = new BalanceService($this->checkpointRepository, $this->transactionRepository);
        $categoryRuleRepository = new \Modules\Finance\Repository\CategoryRuleRepository($this->pdo);
        $settingService = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $accountTransferCategoryService = new \Modules\Finance\Service\AccountTransferCategoryService(
            $this->categoryRepository, $categoryRuleRepository, $this->transactionRepository
        );
        $financeService = new FinanceService(
            $this->accountRepository, $this->categoryRepository, $this->fiscalYearRepository, $sectionService, $this->transactionRepository, $balanceService,
            $settingService, $categoryRuleRepository, $accountTransferCategoryService,
            new \Modules\Finance\Service\AccountVisibility(
                // No badge assigned in these fixtures, so the treasurer
                // rule is off and the module behaves exactly as it did
                // before it existed — which is what these tests assert.
                \Modules\Finance\Service\TreasurerScope::systemCaller()
            )
        );

        $this->attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, sys_get_temp_dir() . '/finance_dashboard_test_' . uniqid());
        $receiptService = new ReceiptService($this->attachmentRepository, $this->accountRepository, $this->transactionAttachmentRepository, $fileStorage, $this->transactionRepository);

        $moduleViews = dirname(__DIR__, 4) . '/modules/finance/views';
        $twig = TestTwig::create(['finance' => $moduleViews]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'intendant');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/finance');
        $twig->addGlobal('route_breadcrumb', ['label' => 'Finances', 'parents' => ['Espace animateurs']]);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $expectedReceivableRepository = new \Modules\Finance\Repository\ExpectedReceivableRepository($this->pdo, $encryption);
        $this->receivableRepository = $expectedReceivableRepository;
        $allocationService = \Tests\Modules\Finance\FinanceTestHelper::allocationService($this->pdo, $encryption, $expectedReceivableRepository);
        $this->allocationService = $allocationService;

        $this->controller = new DashboardController(
            $twig,
            $financeService,
            $balanceService,
            $this->transactionRepository,
            $receiptService,
            new StatementImportRepository($this->pdo),
            new \Modules\Finance\Service\ReconciliationService(
                $expectedReceivableRepository,
                new \Modules\Finance\Repository\ReceivableAllocationRepository($this->pdo),
                $this->transactionRepository,
                $this->accountRepository,
                new \Modules\Finance\Service\AccountVisibility(\Modules\Finance\Service\TreasurerScope::systemCaller()),
                $allocationService,
                new \Core\Member\MemberService(
    new \Core\Import\MemberYearRepository($this->pdo),
    new \Core\Member\Repository\MemberProfileRepository($connection, $encryption)
),
                new \Core\Member\Household\HouseholdService(
                    new \Core\Member\Household\HouseholdRepository($this->pdo, $encryption)
                )
            ),
            $allocationService,
            new \Modules\Finance\Repository\CampaignRepository($this->pdo),
            new \Core\ScoutYear\ScoutYearResolver(
                new \Core\Config\ScoutYearService($this->pdo),
                new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
                new \Core\Import\MemberYearRepository($this->pdo)
            )
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'intendant@test.be', 'intendant');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testShowsEmptyStateWhenNoAccountsVisible(): void
    {
        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aucun compte visible', $response->getBody());
    }

    private function createAccount(string $roleMinView = 'intendant'): int
    {
        $id = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, 'BE00000000000001', 'Titulaire', $roleMinView);
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$id]);
        return $id;
    }

    public function testRendersAccountAndFiscalYearPickers(): void
    {
        $this->createAccount();
        $currentLabel = \Core\Config\ScoutYearService::labelForDate(new \DateTimeImmutable('today'));

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertStringContainsString('Compte', $response->getBody());
        $this->assertStringContainsString($currentLabel, $response->getBody());
    }

    public function testBreadcrumbReflectsTheSelectedAccount(): void
    {
        // The account picker (_nav.html.twig) changes what this page shows
        // without changing its URL — the breadcrumb's own active segment
        // must reflect the currently selected account, not just the
        // static "Finances" label.
        $this->createAccount();

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Finances · Compte\s*</',
            $response->getBody()
        );
    }

    public function testDefaultsToCurrentFiscalYear(): void
    {
        $this->createAccount();

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertStringContainsString('btn-primary', $response->getBody());
    }

    public function testPagePickerUsesTheNavRailNotTheOldHorizontalScrollRow(): void
    {
        // modules/finance/views/_nav.html.twig used to be a raw
        // overflow-x-auto button row with no scroll affordance on mobile.
        // The finance pages are a fixed set declared in code with short
        // labels, so they are a nav rail (through
        // partials/page_picker.html.twig) — see docs/module-development.md.
        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $body = $response->getBody();
        $this->assertStringContainsString('id="finance-page-picker"', $body);
        $this->assertStringContainsString('nav nav-underline', $body);
        $this->assertStringNotContainsString('overflow-x-auto', $body);
        $this->assertMatchesRegularExpression(
            '/href="\/finance"[^>]*aria-current="page"/s',
            $body
        );
    }

    public function testShowsCategorySummaryAndBilan(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $category = $this->categoryRepository->create('Alimentation');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, $category, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r2', '2026-10-02', 'x', 100.0, $category, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Alimentation', $body);
        $this->assertStringContainsString('Bilan', $body);
        // Charts are only rendered when there is category data.
        $this->assertStringContainsString('chart-net-category-pie', $body);
        $this->assertStringContainsString('chart-income-expense-bar', $body);
        $this->assertStringContainsString('chart-balance-line', $body);
    }

    public function testCategorySummaryLinksUncategorizedRowToFilteredMovementsPage(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString(
            '/finance/movements?account_id=' . $accountId . '&fiscal_year_id=' . $fiscalYearId . '&category_id=none',
            $body
        );
    }

    public function testCategorySummaryLinksCategorizedRowToItsOwnCategoryId(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, $categoryId, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString(
            '/finance/movements?account_id=' . $accountId . '&fiscal_year_id=' . $fiscalYearId . '&category_id=' . $categoryId,
            $body
        );
    }

    public function testFiltersRespectAccountRoleFloor(): void
    {
        // Only role_min_view=admin accounts exist — an intendant should
        // see the empty state, not this account.
        $this->createAccount('admin');

        $response = $this->controller->index(new Request('GET', '/finance', [], [], [], []), []);

        $this->assertStringContainsString('Aucun compte visible', $response->getBody());
    }

    public function testInvalidAccountIdFallsBackToFirstVisibleAccount(): void
    {
        $accountId = $this->createAccount();

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => '9999'], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShowsLowestBalance18MonthsMetric(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $today = new \DateTimeImmutable('today');
        $this->checkpointRepository->create($accountId, $today->format('Y-m-d'), 500.0, BalanceCheckpoint::SOURCE_MANUAL);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', $today->format('Y-m-d'), 'Grosse dépense', -450.0, null, null, Transaction::SOURCE_MANUAL, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId, 'fiscal_year_id' => (string) $fiscalYearId], [], [], []), []);

        $this->assertStringContainsString('Solde le plus bas', $response->getBody());
        $this->assertStringContainsString('50,00', $response->getBody());
    }

    public function testShowsLastImportDateAndImportLink(): void
    {
        $accountId = $this->createAccount();
        (new \Modules\Finance\Repository\StatementImportRepository($this->pdo))->create($accountId, 'bnp', 'releve.csv', 10, 10, 0, null);

        $response = $this->controller->index(new Request('GET', '/finance', ['account_id' => (string) $accountId], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringNotContainsString('Aucun import', $body);
        $this->assertStringContainsString('/finance/import?account_id=' . $accountId, $body);
    }

    /**
     * The value a tile shows, by its label (partials/stat_tiles).
     */
    private function tileValue(string $body, string $label): ?string
    {
        $pattern = '#' . preg_quote($label, '#') . '\s*</div>\s*<div class="fs-3 fw-semibold lh-1">(.*?)</div>#s';

        return preg_match($pattern, $body, $match) === 1 ? trim(html_entity_decode($match[1])) : null;
    }

    private function dashboard(int $accountId, ?int $fiscalYearId = null): string
    {
        $query = ['account_id' => (string) $accountId];
        if ($fiscalYearId !== null) {
            $query['fiscal_year_id'] = (string) $fiscalYearId;
        }

        return $this->controller->index(new Request('GET', '/finance', $query, [], [], []), [])->getBody();
    }

    /**
     * Two rows of four (issue #838): what the account looks like, then
     * what is waiting for the treasurer.
     */
    public function testTheDashboardIsTwoRowsOfFourTiles(): void
    {
        $body = $this->dashboard($this->createAccount());

        $this->assertSame(2, substr_count($body, 'row-cols-md-4 g-3 mb-4 stat-tiles'));
        $labels = [
            'Solde actuel', 'Solde le plus bas', 'Dernier extrait importé', 'Paiements encore attendus',
            'Mouvements non catégorisés', 'Reçus à associer', 'Paiements à traiter', 'Campagnes ouvertes',
        ];
        $tiles = substr($body, (int) strpos($body, 'stat-tiles'));
        $positions = [];
        foreach ($labels as $label) {
            $this->assertNotNull($this->tileValue($body, $label), $label . ' is missing');
            $positions[] = strpos($tiles, $label);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'the tiles come in the order the issue lists them');
        $this->assertSame(8, substr_count($body, 'class="fs-3 fw-semibold lh-1"'));
    }

    /**
     * The lists the action tiles replace are gone: no banner, no
     * « Reçus en attente », no « Derniers mouvements en attente d'action ».
     */
    public function testTheDashboardNoLongerRepeatsTheActionLists(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'Achat spécifique', -20.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->attachmentRepository->create($accountId, $this->createFile(), 'application/pdf', 'recu.pdf', null, null, null, null);

        $body = $this->dashboard($accountId, $fiscalYearId);

        $this->assertStringNotContainsString('mouvement(s) non catégorisé(s)', $body);
        $this->assertStringNotContainsString("reçu(s) en attente d'association", $body);
        $this->assertStringNotContainsString('Reçus en attente', $body);
        $this->assertStringNotContainsString("Derniers mouvements en attente d'action", $body);
        $this->assertStringNotContainsString('Achat spécifique', $body);
    }

    public function testMoneyStillExpectedIsTheRemainderOfUnpaidAndPartialReceivables(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $this->receivableRepository->create('finance', 1, $accountId, 4500, '+++123/4567/89002+++', null, null);
        $this->receivableRepository->create('finance', 2, $accountId, 3000, '+++123/4567/89103+++', null, null);
        $paid = $this->receivableRepository->create('finance', 3, $accountId, 2000, '+++123/4567/89204+++', null, null);
        $waived = $this->receivableRepository->create('finance', 4, $accountId, 9900, '+++123/4567/89305+++', null, null);
        $this->pdo->exec("UPDATE finance_expected_receivables SET waived_at = '2026-10-01 10:00:00' WHERE id = " . $waived);
        // 10 € on the 30 € one, all of the 20 € one.
        $this->transactionRepository->create($accountId, $fiscalYearId, 'p1', '2026-10-02', 'Acompte +++123/4567/89103+++', 10.0, null, null, Transaction::SOURCE_IMPORT, null);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'p2', '2026-10-02', 'Solde +++123/4567/89204+++', 20.0, null, null, Transaction::SOURCE_IMPORT, null);

        $body = $this->dashboard($accountId, $fiscalYearId);

        // 45 € untouched + 20 € left of 30 € — neither the paid 20 € nor
        // the waived 99 €, and never the 75 € first asked.
        $this->assertSame('65,00 €', $this->tileValue($body, 'Paiements encore attendus'));
        // The list it opens is narrowed to the same account.
        $this->assertStringContainsString('href="/finance/receivables?account_id=' . $accountId . '"', $body);
        $this->assertNotNull($paid);
    }

    public function testTheMovementsTileCountsOnlyUncategorizedMovementsOfTheSelectedYear(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $categoryId = $this->categoryRepository->create('Fournitures');
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r2', '2026-10-02', 'y', -5.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionRepository->create($accountId, $fiscalYearId, 'r3', '2026-10-03', 'z', -5.0, $categoryId, null, Transaction::SOURCE_MANUAL, null);

        $body = $this->dashboard($accountId, $fiscalYearId);

        $this->assertSame('2', $this->tileValue($body, 'Mouvements non catégorisés'));
        $this->assertStringContainsString(
            '/finance/movements?account_id=' . $accountId . '&amp;fiscal_year_id=' . $fiscalYearId . '&amp;category_id=none',
            $body
        );
    }

    public function testTheReceiptsTileCountsActiveReceiptsWithNoMovement(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        $fileId = $this->createFile();
        $this->attachmentRepository->create($accountId, $fileId, 'application/pdf', 'a.pdf', null, null, null, null);
        $this->attachmentRepository->create($accountId, $fileId, 'application/pdf', 'b.pdf', null, null, null, null);
        $linked = $this->attachmentRepository->create($accountId, $fileId, 'application/pdf', 'c.pdf', null, null, null, null);
        $movementId = $this->transactionRepository->create($accountId, $fiscalYearId, 'r1', '2026-10-01', 'x', -20.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->transactionAttachmentRepository->associate($movementId, $linked);

        $body = $this->dashboard($accountId, $fiscalYearId);

        $this->assertSame('2', $this->tileValue($body, 'Reçus à associer'));
        $this->assertStringContainsString('/finance/receipts?account_id=' . $accountId . '&amp;pending=1', $body);
    }

    /**
     * A campaign belongs to a scout year, not a fiscal year: the count is
     * the account's open campaigns of the scout year being looked at, and
     * the tile opens the campaigns list on that same year and account, so
     * the two agree.
     */
    public function testTheCampaignsTileCountsOpenCampaignsOfTheSelectedAccountAndScoutYear(): void
    {
        $accountId = $this->createAccount();
        $otherAccountId = $this->createAccount();
        $currentYearId = FinanceTestHelper::createScoutYear($this->pdo, \Core\Config\ScoutYearService::labelForDate(new \DateTimeImmutable('today')), '2025-09-01', '2026-08-31', true);
        $pastYearId = FinanceTestHelper::createScoutYear($this->pdo, '2019-2020', '2019-09-01', '2020-08-31', false);
        foreach ([
            [$accountId, $currentYearId, 'open'],
            [$accountId, $currentYearId, 'open'],
            [$accountId, $currentYearId, 'closed'],
            [$accountId, $pastYearId, 'open'],
            [$otherAccountId, $currentYearId, 'open'],
        ] as [$account, $yearId, $status]) {
            $this->pdo->prepare('INSERT INTO finance_campaigns (label, scout_year_id, account_id, status) VALUES (?, ?, ?, ?)')
                ->execute(['Cotisations', $yearId, $account, $status]);
        }

        $body = $this->dashboard($accountId);

        $this->assertSame('2', $this->tileValue($body, 'Campagnes ouvertes'));
        $this->assertStringContainsString(
            'href="/finance/campaigns?scout_year_id=' . $currentYearId . '&amp;account_id=' . $accountId . '"',
            $body
        );
    }

    /**
     * The reconciliation tile is the only figure on this page that is a
     * thing to DO. It has to be zero when there is nothing to do, or it
     * becomes a nag the treasurer learns to ignore.
     */
    public function testTheReconciliationTileIsZeroWhenThereIsNothingToReconcile(): void
    {
        $accountId = $this->createAccount();

        $body = $this->controller->index(
            new Request('GET', '/finance', ['account_id' => (string) $accountId], [], [], []),
            []
        )->getBody();

        $this->assertStringContainsString('Paiements à traiter', $body);
        $this->assertStringContainsString('Aucun paiement n’attend de décision', $body);
        $this->assertStringContainsString('/finance/reconciliation?account_id=' . $accountId, $body);
    }

    /**
     * A credit whose communication names nothing the site knows is the
     * "non imputés" situation — one of the four the reconciliation screen
     * exists for, and the tile must say so rather than just show a
     * number.
     */
    public function testACreditNamingNoReceivableShowsUpOnTheTile(): void
    {
        $accountId = $this->createAccount();
        $fiscalYearId = $this->fiscalYearRepository->findCurrent()->id;
        FinanceTestHelper::createScoutYear($this->pdo, \Core\Config\ScoutYearService::labelForDate(new \DateTimeImmutable('today')), '2025-09-01', '2026-08-31', true);

        // A receivable so the account has something to reconcile against,
        // and a credit whose valid structured communication names nothing
        // — the orphan case (issue #837).
        $receivables = new \Modules\Finance\Repository\ExpectedReceivableRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $receivables->create('finance', 1, $accountId, 4500, '+++123/4567/89012+++', null, null);
        $this->transactionRepository->create(
            $accountId, $fiscalYearId, 'orphan-1', '2026-02-18', 'Virement +++999/8888/77758+++',
            30.0, null, null, Transaction::SOURCE_IMPORT, null
        );

        $body = $this->controller->index(
            new Request('GET', '/finance', ['account_id' => (string) $accountId], [], [], []),
            []
        )->getBody();

        $this->assertStringContainsString('1 non imputé', $body);
        $this->assertStringNotContainsString('Aucun paiement n’attend de décision', $body);
    }

    private function createFile(): int
    {
        $this->pdo->exec(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('a.pdf', 'a.pdf', 'application/pdf', 100)"
        );
        return (int) $this->pdo->lastInsertId();
    }
}
