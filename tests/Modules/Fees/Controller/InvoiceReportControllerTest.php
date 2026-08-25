<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
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
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Fees\Controller\InvoiceReportController;
use Modules\Fees\Invoice\InvoiceLine;
use Modules\Fees\Invoice\InvoicePerson;
use Modules\Fees\Invoice\ParsedInvoice;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Repository\InvoiceRepository;
use Core\Import\RosterSnapshotRepository;
use Modules\Fees\Service\HouseholdTariffService;
use Modules\Fees\Service\InvoiceVerificationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;
use Twig\Environment;

/**
 * The verification report, and its RBAC boundary: allowed at `admin`,
 * refused one level below at `chief` — the page and the spreadsheet
 * export alike, because an export at a lower role is the same leak
 * through a different door.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InvoiceReportControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private InvoiceReportController $controller;
    private InvoiceRepository $invoices;
    private RosterSnapshotRepository $snapshots;
    private EncryptionService $encryption;
    private int $scoutYearId;
    private int $otherYearId;
    private int $louveteauxId;
    private int $normalFeeId;
    private int $familyFeeId;
    private int $functionId;
    /** @var array<string, int> */
    private array $matches = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->scoutYearId = $scoutYearService->ensureYear('2025-2026');
        $this->otherYearId = $scoutYearService->ensureYear('2024-2025');
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->scoutYearId);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOUV', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'SV025L1', 'Meute Akela')");
        $this->louveteauxId = (int) $this->pdo->lastInsertId();

        $feeCategories = new FeeCategoryRepository($this->pdo);
        $this->normalFeeId = $feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $this->familyFeeId = $feeCategories->create('N_F_COTISATION FAMILLE', 'Cotisation famille');

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('Anime', 'Anime', 'identified')");
        $this->functionId = (int) $this->pdo->lastInsertId();

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = TwigFactory::create($templateDir, false, ['fees' => dirname(__DIR__, 4) . '/modules/fees/views']);
        foreach ([
            'site_name' => 'Unité Test', 'is_authenticated' => true, 'current_user_role' => 'admin',
            'config_mode' => false, 'cookie_consent_given' => true, 'menus' => null,
            'current_path' => '/admin/fees/factures', 'csp_nonce' => 'test-nonce',
        ] as $key => $value) {
            $twig->addGlobal($key, $value);
        }
        $this->twig = $twig;

        $this->invoices = new InvoiceRepository($this->pdo);
        $this->snapshots = new RosterSnapshotRepository($this->pdo);
        $sections = new SectionService(Connection::withPdo($this->pdo), $this->encryption, new MemberBadgeRepository($this->pdo));

        $this->controller = new InvoiceReportController(
            $twig,
            $this->invoices,
            new InvoiceVerificationService(
                $this->invoices,
                $this->snapshots,
                new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $feeCategories),
                $sections,
                new HouseholdDetailRepository($this->pdo, $this->encryption)
            ),
            new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function member(string $firstName, string $lastName, ?int $feeCategoryId, bool $leaving = false): InvoicePerson
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       birth_date_encrypted, fee_category_id, leaving, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $this->encryption->encrypt('2012-03-15', 'member_years.birth_date'),
            $feeCategoryId, $leaving ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $this->functionId, $this->louveteauxId]);

        $person = new InvoicePerson($lastName, $firstName, '2012-03-15', null);
        $this->matches[$person->matchKey()] = $memberId;

        return $person;
    }

    /** @param InvoiceLine[] $lines */
    private function storeInvoice(array $lines, ?int $snapshotId, ?int $scoutYearId = null): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += $line->amountCents;
        }

        return $this->invoices->store(
            new ParsedInvoice('F2026/000123', '2026-01-08', $lines, $total, null, null, 'Report0024 v.01', 0),
            $scoutYearId ?? $this->scoutYearId,
            $snapshotId,
            null,
            ['SV025L1' => $this->louveteauxId],
            $this->matches
        );
    }

    /** @param InvoicePerson[] $people */
    private function feeLine(string $reference, string $descriptor, int $unitPrice, array $people): InvoiceLine
    {
        return new InvoiceLine($reference, $descriptor, 'SV025L1', $unitPrice, count($people), $unitPrice * count($people), $people);
    }

    /** A conforming invoice: two members, both billed, both on the right tariff. */
    private function conformingInvoice(): int
    {
        $one = $this->member('Basile', 'Dubois', $this->normalFeeId);
        $two = $this->member('Zoé', 'Pissoort', $this->normalFeeId);
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));

        return $this->storeInvoice([$this->feeLine('COT_NORM', 'Cotisation normale', 3900, [$one, $two])], $snapshot->id);
    }

    // --- RBAC ---------------------------------------------------------

    public function testAdminReachesTheReport(): void
    {
        $id = $this->conformingInvoice();
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch('GET', '/admin/fees/factures/{id}', 'show', ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('F2026/000123', (string) $response->getBody());
    }

    public function testChiefIsRefusedTheReport(): void
    {
        $id = $this->conformingInvoice();
        AuthSession::login(1, 'chief@test.be', 'chief');

        $this->assertSame(403, $this->dispatch('GET', '/admin/fees/factures/{id}', 'show', ['id' => (string) $id])->getStatusCode());
    }

    public function testAdminReachesTheExport(): void
    {
        $id = $this->conformingInvoice();
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch('GET', '/admin/fees/factures/{id}/export', 'export', ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('spreadsheetml', (string) ($response->getHeaders()['Content-Type'] ?? ''));
        $this->assertStringStartsWith('PK', (string) $response->getBody());
    }

    public function testChiefIsRefusedTheExport(): void
    {
        $id = $this->conformingInvoice();
        AuthSession::login(1, 'chief@test.be', 'chief');

        $this->assertSame(403, $this->dispatch('GET', '/admin/fees/factures/{id}/export', 'export', ['id' => (string) $id])->getStatusCode());
    }

    // --- what the report says -------------------------------------------

    /**
     * A report whose first screenful is forty rows of "conforme" hides the
     * two that are not — but dropping them would make the document
     * impossible to reconcile against the paper one, so they are counted
     * and collapsed.
     */
    public function testConformingLinesAreCountedAndCollapsedRatherThanShownOrDropped(): void
    {
        $id = $this->conformingInvoice();
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/factures/{id}', 'show', ['id' => (string) $id])->getBody();

        $this->assertStringContainsString('Afficher les 1 ligne(s) conforme(s)', $body);
        $this->assertStringContainsString('collapse', $body);
    }

    public function testTheNominativeTabNamesThePersonAndTheThingToDo(): void
    {
        $leaving = $this->member('Camille', 'Renard', $this->normalFeeId, leaving: true);
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));
        $id = $this->storeInvoice([$this->feeLine('COT_NORM', 'Cotisation normale', 3900, [$leaving])], $snapshot->id);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch(
            'GET', '/admin/fees/factures/{id}', 'show', ['id' => (string) $id], ['vue' => 'nominatif']
        )->getBody();

        $this->assertStringContainsString('Facturé mais parti', $body);
        $this->assertStringContainsString('Renard', $body);
        $this->assertStringContainsString('39,00 €', $body);
    }

    /** Signalled, never costed — the tariff is the same on either section. */
    public function testASectionDiscrepancyIsShownWithoutAnAmount(): void
    {
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) SELECT age_branch_id, 'SV025E1', 'Troupe Sanglier' FROM sections LIMIT 1");
        $otherSectionId = (int) $this->pdo->lastInsertId();

        $person = $this->member('Basile', 'Dubois', $this->normalFeeId);
        $this->pdo->exec("UPDATE member_functions SET section_id = {$otherSectionId}");
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));
        $id = $this->storeInvoice([$this->feeLine('COT_NORM', 'Cotisation normale', 3900, [$person])], $snapshot->id);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch(
            'GET', '/admin/fees/factures/{id}', 'show', ['id' => (string) $id], ['vue' => 'nominatif']
        )->getBody();

        $this->assertStringContainsString('Section différente', $body);
        $this->assertStringContainsString('Sans incidence', $body);
    }

    /**
     * One day of drift is enough to produce differences that are not
     * differences.
     */
    public function testTheGapBetweenTheSnapshotAndTheInvoiceIsOnTheScreen(): void
    {
        $person = $this->member('Basile', 'Dubois', $this->normalFeeId);
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));
        $id = $this->storeInvoice([$this->feeLine('COT_NORM', 'Cotisation normale', 3900, [$person])], $snapshot->id);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/factures/{id}', 'show', ['id' => (string) $id])->getBody();

        $this->assertStringContainsString('3 jour(s)', $body);
        $this->assertStringContainsString('avant', $body);
    }

    public function testAnInvoiceWithNoSnapshotSaysItWillNeverBeCheckable(): void
    {
        $person = $this->member('Basile', 'Dubois', $this->normalFeeId);
        $id = $this->storeInvoice([$this->feeLine('COT_NORM', 'Cotisation normale', 3900, [$person])], null);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/factures/{id}', 'show', ['id' => (string) $id])->getBody();

        $this->assertStringContainsString('Aucune photographie du roster', $body);
        $this->assertStringContainsString('ne le sera jamais', $body);
    }

    public function testPeopleTheSiteDidNotRecogniseAreAnnouncedOnTheReport(): void
    {
        $known = $this->member('Basile', 'Dubois', $this->normalFeeId);
        $stranger = new InvoicePerson('Inconnu', 'Personne', '2011-01-01', null);
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));
        $id = $this->storeInvoice([$this->feeLine('COT_NORM', 'Cotisation normale', 3900, [$known, $stranger])], $snapshot->id);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/factures/{id}', 'show', ['id' => (string) $id])->getBody();

        $this->assertStringContainsString("1 personne(s) facturée(s) que le site n'a pas reconnue(s)", $body);
    }

    public function testAnUnknownVueFallsBackToTheLineTabRatherThanFailing(): void
    {
        $id = $this->conformingInvoice();
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch(
            'GET', '/admin/fees/factures/{id}', 'show', ['id' => (string) $id], ['vue' => 'n\'importe quoi']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Lignes reconstituées', (string) $response->getBody());
    }

    // --- what it refuses --------------------------------------------------

    public function testAnInvoiceThatDoesNotExistIs404(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $this->assertSame(404, $this->dispatch('GET', '/admin/fees/factures/{id}', 'show', ['id' => '987654'])->getStatusCode());
    }

    /**
     * The year a session is on is the year it reads: another year's
     * invoice answers exactly like one that does not exist.
     */
    public function testAnotherScoutYearsInvoiceIs404(): void
    {
        $id = $this->storeInvoice([], null, $this->otherYearId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $this->assertSame(404, $this->dispatch('GET', '/admin/fees/factures/{id}', 'show', ['id' => (string) $id])->getStatusCode());
    }

    /**
     * `/admin/fees/factures/import` and `/admin/fees/factures/{id}` are two
     * GET routes on the same shape, and the literal one is declared first.
     * A manifest reordering that broke it would send a treasurer's import
     * screen to a 404 instead.
     */
    public function testTheImportPathIsNotSwallowedByTheIdRoute(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/fees/module.json'), true);
        $this->assertIsArray($manifest);

        $order = [];
        foreach ($manifest['routes'] as $route) {
            if ($route['method'] === 'GET' && str_starts_with((string) $route['path'], '/admin/fees/factures/')) {
                $order[] = $route['path'];
            }
        }

        $this->assertSame(
            ['/admin/fees/factures/import', '/admin/fees/factures/{id}', '/admin/fees/factures/{id}/export'],
            $order,
            'The literal /import route must stay declared before the {id} one — Router::resolve() takes the first match.'
        );
    }

    /**
     * @param array<string, string> $params
     * @param array<string, string> $query
     */
    private function dispatch(string $method, string $routePath, string $action, array $params, array $query = []): \Core\Http\Response
    {
        $path = $routePath;
        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', $value, $path);
        }

        $router = new Router();
        $router->addRoute($method, $routePath, InvoiceReportController::class, $action, 'admin');

        $configFile = sys_get_temp_dir() . '/test_fees_report_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $fc = new FrontController($router, $this->twig, new AppConfig($configFile));
        $fc->registerController(InvoiceReportController::class, $this->controller);

        return $fc->handle(new Request($method, $path, $query, [], [], []));
    }
}
