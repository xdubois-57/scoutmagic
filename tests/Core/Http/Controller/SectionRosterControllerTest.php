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
            $journalService
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

        $this->assertStringContainsString('href="/members/' . $memberYearId . '"', $response->getBody());
    }

    public function testMemberNameIsNotALinkForChiefRole(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('href="/members/' . $memberYearId . '"', $response->getBody());
    }

    public function testMemberNameIsNotALinkForIntendantRole(): void
    {
        AuthSession::login(1, 'int@test.be', 'intendant');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('href="/members/' . $memberYearId . '"', $response->getBody());
    }

    public function testMemberNameIsALinkForSuperadminRole(): void
    {
        AuthSession::login(1, 'root@test.be', 'superadmin');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 20);
        $sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/membres', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('href="/members/' . $memberYearId . '"', $response->getBody());
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
