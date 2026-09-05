<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Controller\SectionRosterController;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\Export\MemberExportRowBuilder;
use Core\Member\Export\MemberExportService;
use Core\Member\Movement\MemberMovementClassifierService;
use Core\Member\Movement\MemberMovementRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionRosterRepository;
use Core\Member\Pdf\SectionRosterHtmlBuilder;
use Core\Member\SectionRosterPdfService;
use Core\Member\SectionRosterService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\TextNormalizerExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

#[Group('database')]
class SectionRosterControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SectionRosterController $controller;
    private SectionService $sectionService;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $memberBadgeRepository = new \Core\Badge\MemberBadgeRepository($this->pdo);
        $this->sectionService = new SectionService($connection, $this->encryption, $memberBadgeRepository);

        $memberEmailRepository = new MemberEmailRepository($this->pdo, $this->encryption);
        $scoutYearService = new ScoutYearService($this->pdo);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $movementClassifier = new MemberMovementClassifierService(new MemberMovementRepository($this->pdo), $scoutYearService);
        $rosterRepository = new SectionRosterRepository($this->pdo);
        $rosterService = new SectionRosterService($rosterRepository, $this->encryption, $memberEmailRepository, $movementClassifier);
        $exportRowBuilder = new MemberExportRowBuilder($rosterRepository, $this->sectionService, $scoutYearService, $this->encryption, $memberEmailRepository, $movementClassifier);
        $exportService = new MemberExportService();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $settingService->register('current_scout_year_id', (string) $this->scoutYearId, 'text', 'x', 'x');

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addExtension(new \Core\View\CompactHtmlExtension());
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chief@test.be');
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/chefs/membres');
        $twig->addGlobal('route_breadcrumb', ['label' => 'Membres par section', 'parents' => ['Espace animateurs']]);
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addExtension(new TextNormalizerExtension());

        $this->controller = new SectionRosterController(
            $twig,
            $this->sectionService,
            $rosterService,
            $exportRowBuilder,
            $exportService,
            $scoutYearResolver,
            $journalService,
            // Null cache directory: every call renders, which is what
            // Core\Member\SectionRosterPdfService documents it for.
            new SectionRosterPdfService(new SectionRosterHtmlBuilder()),
            $settingService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.be', 'chief');
        ScoutYearSession::clear();
    }

    protected function tearDown(): void
    {
        ScoutYearSession::clear();
    }

    public function testAllSectionsShownByDefault(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $this->createSection('LOU01', $branchId, 'Section Un');
        $this->createSection('LOU02', $branchId, 'Section Deux');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Section Un', $body);
        $this->assertStringContainsString('Section Deux', $body);
    }

    public function testFilteringByASpecificSectionShowsOnlyThatSection(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionA = $this->createSection('LOU01', $branchId, 'Section Un');
        $this->createSection('LOU02', $branchId, 'Section Deux');

        $request = new Request('GET', '/chefs/membres', ['section' => (string) $sectionA], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        // The picker itself still lists every section as a choice — only
        // the rendered section block below it must be limited to the
        // selection, so assert on the section HEADING, not mere presence
        // of the name anywhere on the page.
        $this->assertStringContainsString('<h2 class="h5 mb-0">Section Un</h2>', $body);
        $this->assertStringNotContainsString('<h2 class="h5 mb-0">Section Deux</h2>', $body);
    }

    public function testInvalidSectionIdFallsBackToAllSections(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $this->createSection('LOU01', $branchId, 'Section Un');
        $this->createSection('LOU02', $branchId, 'Section Deux');

        $request = new Request('GET', '/chefs/membres', ['section' => '999999'], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Section Un', $body);
        $this->assertStringContainsString('Section Deux', $body);
    }

    public function testSectionOrderMatchesBranchSortOrderThenDeskCode(): void
    {
        $branchLou = $this->createBranch('LOU', 'Louveteaux', 20);
        $branchBal = $this->createBranch('BAL', 'Baladins', 10);
        $this->createSection('LOU01', $branchLou, 'Section Louveteaux');
        $this->createSection('BAL01', $branchBal, 'Section Baladins');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $posBaladins = strpos($body, 'Section Baladins');
        $posLouveteaux = strpos($body, 'Section Louveteaux');
        $this->assertNotFalse($posBaladins);
        $this->assertNotFalse($posLouveteaux);
        $this->assertLessThan($posLouveteaux, $posBaladins);
    }

    public function testMemberNameIsALinkForAdminRole(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('href="/admin/members/' . $memberYearId . '"', $response->getBody());
    }

    public function testMemberNameIsNotALinkForChiefRole(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('href="/admin/members/' . $memberYearId . '"', $response->getBody());
    }

    public function testMemberNameIsNotALinkForIntendantRole(): void
    {
        AuthSession::login(1, 'int@test.be', 'intendant');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('href="/admin/members/' . $memberYearId . '"', $response->getBody());
    }

    public function testMemberNameIsALinkForSuperadminRole(): void
    {
        AuthSession::login(1, 'root@test.be', 'superadmin');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('href="/admin/members/' . $memberYearId . '"', $response->getBody());
    }

    /**
     * The identifier, pinned — not merely the presence of a link.
     *
     * `/admin/members/{id}` takes a **member_years.id**: Core\Member\
     * Controller\MemberSearchController::show() resolves the parameter
     * through MemberYearRepository::findById() and then normalises onto
     * the member's most recent annual row. members.id and member_years.id
     * are both integers, so the wrong one does not 404 — it silently
     * opens somebody else's sheet, which is the failure this test exists
     * to catch and which no "there is an <a href> somewhere" assertion
     * would ever see.
     *
     * The fixture pushes the two sequences apart on purpose: in a fresh
     * database they advance in lock-step, so the member created here has
     * members.id and member_years.id equal and the assertion below would
     * hold for either value. Six spare `members` rows first, and they
     * cannot be confused again.
     */
    public function testTheMemberLinkCarriesTheMemberYearIdAndNotTheMemberId(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $spare = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        for ($i = 0; $i < 6; $i++) {
            $spare->execute(['SPARE_' . uniqid()]);
        }
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');
        $lookup = $this->pdo->prepare('SELECT member_id FROM member_years WHERE id = ?');
        $lookup->execute([$memberYearId]);
        $memberId = (int) $lookup->fetchColumn();
        $this->assertNotSame($memberId, $memberYearId, 'the fixture must not let the two ids coincide');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringContainsString('href="/admin/members/' . $memberYearId . '"', $body);
        $this->assertStringNotContainsString('href="/admin/members/' . $memberId . '"', $body);
    }

    /**
     * And never the member's own page: /members/{id} is what a member
     * consults about themselves, with none of the internal notes, history
     * or module blocks the Staff d'unité opens this list to reach.
     */
    public function testTheMemberLinkIsNotTheMembersOwnPage(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);

        $this->assertStringNotContainsString(
            'href="/members/' . $memberYearId . '"',
            $this->controller->index($request, [])->getBody()
        );
    }

    public function testUsesThePreviewYearWhenSetForAnAdmin(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', '2025-08-31')");
        $previewYearId = (int) $this->pdo->lastInsertId();

        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $this->createMemberInSectionForYear($sectionId, 'Ancienne', 'chief', $previewYearId);

        ScoutYearSession::setPreview($previewYearId);

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('Ancienne', $response->getBody());
    }

    public function testExportReturnsAValidXlsxFile(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/membres/export', [], [], [], []);
        $response = $this->controller->export($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->getHeaders()['Content-Type']
        );
        $this->assertStringContainsString('attachment; filename="membres-', $response->getHeaders()['Content-Disposition']);

        $path = sys_get_temp_dir() . '/section-roster-export-test-' . uniqid() . '.xlsx';
        file_put_contents($path, $response->getBody());
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertGreaterThan(1, $sheet->getHighestRow());
        unlink($path);
    }

    public function testExportRespectsTheSectionFilter(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionA = $this->createSection('LOU01', $branchId, 'Section A');
        $sectionB = $this->createSection('LOU02', $branchId, 'Section B');
        $this->createMemberInSection($sectionA, 'Alice', 'identified');
        $this->createMemberInSection($sectionB, 'Bob', 'identified');

        $request = new Request('GET', '/chefs/membres/export', ['section' => (string) $sectionA], [], [], []);
        $response = $this->controller->export($request, []);

        $path = sys_get_temp_dir() . '/section-roster-export-test-' . uniqid() . '.xlsx';
        file_put_contents($path, $response->getBody());
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $names = [];
        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $names[] = $sheet->getCell([3, $row])->getValue(); // "Prénom" is the 3rd canonical column
        }
        $this->assertContains('Alice', $names);
        $this->assertNotContains('Bob', $names);
        unlink($path);
    }

    // --- the roll-call sheet -------------------------------------------

    public function testPdfReturnsARealPdfDocument(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/membres/pdf', [], [], [], []);
        $response = $this->controller->pdf($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->getHeaders()['Content-Type']);
        // The one header whose loss is silent: the sheet is member names in
        // the clear, and nothing else stops a shared browser or a proxy
        // keeping it after the session that was allowed to see it ends.
        $this->assertSame('private, no-store', $response->getHeaders()['Cache-Control']);
        $this->assertStringStartsWith('%PDF-', $response->getBody());
        $this->assertSame((string) strlen($response->getBody()), $response->getHeaders()['Content-Length']);
    }

    /**
     * The sheet prints what is being looked at: the button carries the
     * picker's own filter, so both must resolve the same perimeter.
     */
    public function testPdfRespectsTheSectionFilterAndNamesItInTheFile(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionA = $this->createSection('LOU01', $branchId, 'Section A');
        $sectionB = $this->createSection('LOU02', $branchId, 'Section B');
        $this->createMemberInSection($sectionA, 'Alice', 'chief');
        $this->createMemberInSection($sectionB, 'Bob', 'chief');

        $request = new Request('GET', '/chefs/membres/pdf', ['section' => (string) $sectionA], [], [], []);
        $response = $this->controller->pdf($request, []);

        $this->assertStringContainsString(
            'filename="appel-2025-2026-section-a.pdf"',
            $response->getHeaders()['Content-Disposition']
        );

        // The filename alone proves nothing: naming the file from the filter
        // while handing every section to the renderer would still pass, and
        // that is precisely the failure a roll-call sheet must not have — a
        // chief prints Section A and walks off with Section B's names too.
        // One section per sheet, so page count is the perimeter.
        $this->assertSame(1, $this->pageCountOf($response->getBody()), 'the filtered sheet must carry one section');

        $all = $this->controller->pdf(new Request('GET', '/chefs/membres/pdf', [], [], [], []), []);
        $this->assertSame(2, $this->pageCountOf($all->getBody()), 'unfiltered, both sections are printed');
    }

    /**
     * dompdf writes one `/Type /Page` object per page (and one `/Type
     * /Pages` for the tree, which the negative lookahead excludes).
     */
    private function pageCountOf(string $pdf): int
    {
        return preg_match_all('~/Type\s*/Page(?!s)~', $pdf);
    }

    public function testPdfWithoutAFilterNamesNoSection(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $this->createSection('LOU01', $branchId, 'Section A');

        $request = new Request('GET', '/chefs/membres/pdf', [], [], [], []);
        $response = $this->controller->pdf($request, []);

        $this->assertStringContainsString(
            'filename="appel-2025-2026.pdf"',
            $response->getHeaders()['Content-Disposition']
        );
    }

    /**
     * Counters, never a name — the same rule the spreadsheet export's own
     * entry already follows (AGENTS.md § Security checklist).
     */
    public function testPdfIsJournaledWithCountersAndNoPersonalData(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $this->controller->pdf(new Request('GET', '/chefs/membres/pdf', [], [], [], []), []);

        $row = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'section_roster_pdf_exported'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $context = json_decode((string) $row['context'], true);
        $this->assertSame(1, $context['section_count']);
        $this->assertSame(1, $context['member_count']);
        $this->assertStringNotContainsStringIgnoringCase('Alice', (string) $row['context']);
        $this->assertStringNotContainsStringIgnoringCase('Alice', (string) $row['description']);
    }

    public function testThePageOffersTheSheetBesideTheSpreadsheet(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $this->createSection('LOU01', $branchId, 'Ma section');

        $body = $this->controller->index(new Request('GET', '/chefs/membres', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('href="/chefs/membres/pdf"', $body);
        $this->assertStringContainsString("Feuille d'appel PDF", $body);
        $this->assertStringContainsString('href="/chefs/membres/export"', $body);
    }

    public function testTheSheetButtonCarriesTheSelectedSection(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionA = $this->createSection('LOU01', $branchId, 'Section A');
        $this->createSection('LOU02', $branchId, 'Section B');

        $request = new Request('GET', '/chefs/membres', ['section' => (string) $sectionA], [], [], []);

        $this->assertStringContainsString(
            'href="/chefs/membres/pdf?section=' . $sectionA . '"',
            $this->controller->index($request, [])->getBody()
        );
    }

    private function createBranch(string $code, string $label, int $sortOrder): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$code, $label, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode, int $branchId, ?string $name = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMemberInSection(int $sectionId, string $firstName, string $functionRole): int
    {
        return $this->createMemberInSectionForYear($sectionId, $firstName, $functionRole, $this->scoutYearId);
    }

    private function createMemberInSectionForYear(int $sectionId, string $firstName, string $functionRole, int $scoutYearId): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt('member@test.be', 'member_years.email'),
            $this->encryption->blindIndex('member@test.be', 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionRole]);
        $functionId = $stmt->fetchColumn();
        if ($functionId === false) {
            $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)')->execute([$functionRole, ucfirst($functionRole), $functionRole]);
            $functionId = (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)');
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        return $memberYearId;
    }
}
