<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Controller\StaffsController;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\TextNormalizerExtension;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class StaffsControllerTest extends TestCase
{
    private \PDO $pdo;
    private StaffsController $controller;
    private SectionService $sectionService;
    private MemberService $memberService;
    private BadgeService $badgeService;
    private \Core\Member\SectionDocumentService $sectionDocumentService;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $this->sectionService = new SectionService($connection, $this->encryption, $memberBadgeRepository);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $this->memberService = new MemberService($memberYearRepo, $this->encryption, $connection);
        $scoutYearService = new ScoutYearService($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);
        $journalRepo = new JournalRepository($this->pdo);
        $journalService = new JournalService($journalRepo);
        $this->badgeService = new BadgeService(new BadgeRepository($this->pdo), $memberBadgeRepository, $this->sectionService);

        $settingService->register('section_document_compression_enabled', '1', 'boolean', 'x', 'x');
        $settingService->register('section_document_compression_quality', \Core\Pdf\PdfCompressor::QUALITY_BALANCED, 'select', 'x', 'x');
        $settingService->register('section_document_compression_backend', \Core\Pdf\PdfCompressor::BACKEND_NONE, 'text', 'x', 'x', null, null, null, false);
        $storagePath = sys_get_temp_dir() . '/staffs_controller_test_' . uniqid();
        $this->sectionDocumentService = new \Core\Member\SectionDocumentService(
            new \Core\Member\SectionDocumentRepository($this->pdo),
            new \Core\Member\SectionMembershipRepository($this->pdo),
            new \Core\File\EncryptedFileStorageService(new \Core\File\FileRepository($this->pdo), $this->encryption, $storagePath),
            new \Core\File\FileRepository($this->pdo),
            $this->sectionService,
            $scoutYearService,
            $journalService,
            new \Core\Scheduler\SchedulerService(new \Core\Scheduler\SchedulerRepository($this->pdo)),
            $settingService,
            new \Core\Pdf\PdfCompressor($storagePath . '/temp')
        );

        // Create scout year
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        // Create Twig
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chief@test.be');
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/chefs/staffs');
        $twig->addGlobal('route_breadcrumb', ['label' => 'Staffs', 'parents' => ['Espace animateurs']]);
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new TwigFunction('param', fn(string $k) => 'Test'));
        // Minimal stand-in for TwigFactory::create()'s real section_photo()
        // (same pragmatic simplification as the display_name filter right
        // below) — real rendering/placeholder/overlay logic is covered in
        // full by Tests\Core\View\SectionPhotoFunctionTest; here it only
        // needs to exist and prove the template is actually wired to it.
        $sectionPhotoService = new \Core\Photo\SectionPhotoService(new \Core\Photo\SectionPhotoRepository($this->pdo));
        $twig->addGlobal('_section_photo_service', $sectionPhotoService);
        $twig->addGlobal('effective_scout_year_id', $this->scoutYearId);
        $twig->addFunction(new TwigFunction('section_photo', function (int $sectionId, string $alt = '') use ($sectionPhotoService) {
            $fileId = $sectionPhotoService->resolveFileId($sectionId, $this->scoutYearId);
            return $fileId !== null ? '<img src="/files/' . $fileId . '" alt="' . htmlspecialchars($alt) . '">' : '';
        }, ['is_safe' => ['html']]));
        $twig->addExtension(new TextNormalizerExtension());
        $twig->addFilter(new TwigFilter('display_name', function ($member) {
            if ($member instanceof \Core\Member\MemberProfile) {
                return $member->getDisplayName();
            }
            return '';
        }));

        $this->controller = new StaffsController(
            $twig,
            $this->sectionService,
            $this->memberService,
            $scoutYearResolver,
            $journalService,
            $this->badgeService,
            new UnitStaffSectionService($this->pdo),
            $this->sectionDocumentService,
            $settingService
        );

        // Set up session as chief
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.be', 'chief');
    }

    private function createBranch(string $code, string $label, int $sortOrder): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$code, $label, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode, int $branchId, ?string $name = null, ?string $email = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, email) VALUES (?, ?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name, $email]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMemberInSection(int $sectionId, string $firstName, string $functionRole = 'identified', string $email = 'member@test.be'): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, mobile_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
            $this->encryption->encrypt('0498765432', 'member_years.mobile'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('{$functionRole}', 'Animateur', '{$functionRole}')");
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionRole]);
        $functionId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT age_branch_id FROM sections WHERE id = ?');
        $stmt->execute([$sectionId]);
        $branchId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        return $memberYearId;
    }

    public function testIndexRendersWithSectionPickerAndStaffList(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Ma section');
        $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Staffs', $body);
        $this->assertStringContainsString('section-picker', $body);
        $this->assertStringContainsString('Ma section', $body);
        $this->assertStringContainsString('Alice', $body);
        // The section picker's colored dot — same single source of truth
        // (Core\Member\SectionService::colorForSection()) as every other
        // section picker/list across the site.
        $this->assertStringContainsString('background-color:', $body);
    }

    public function testIndexBreadcrumbReflectsTheSelectedSection(): void
    {
        // The section picker changes what this page shows without changing
        // its URL — the breadcrumb's own active segment must reflect the
        // currently selected section, not just the static "Staffs" label.
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Ma section');

        $request = new Request('GET', '/chefs/staffs', ['section' => (string) $sectionId], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Staffs · Ma section\s*</',
            $body
        );
    }

    public function testIndexRendersSectionDocumentsAccordionWithACompressedDocument(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Ma section');
        $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $document = $this->sectionDocumentService->upload(
            $sectionId, $this->scoutYearId, str_repeat('%PDF-1.4 x', 500), 'application/pdf',
            'camp.pdf', 'Carnet de camp', 'Description du carnet', null
        );
        $this->sectionDocumentService->refreshDetectedBackend();
        // Simulate a completed compression for the size-badge assertion below.
        $repo = new \Core\Member\SectionDocumentRepository($this->pdo);
        $repo->markCompressed($document->id, 1000);

        $request = new Request('GET', '/chefs/staffs?section=' . $sectionId, ['section' => (string) $sectionId], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Documents de section', $body);
        $this->assertStringContainsString('Carnet de camp', $body);
        $this->assertStringContainsString($this->scoutYear2025Label(), $body);
        $this->assertStringContainsString('Mo', $body);
    }

    private function scoutYear2025Label(): string
    {
        $stmt = $this->pdo->prepare('SELECT label FROM scout_years WHERE id = ?');
        $stmt->execute([$this->scoutYearId]);
        return (string) $stmt->fetchColumn();
    }

    public function testIndexRendersTheSectionStaffPhotoWhenOneExists(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Ma section');

        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('core/section_photos/x.jpg', 'x.jpg', 'image/jpeg', 100)"
        );
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        (new \Core\Photo\SectionPhotoRepository($this->pdo))->upsert($sectionId, $this->scoutYearId, $fileId, null);

        $request = new Request('GET', '/chefs/staffs?section=' . $sectionId, [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('/files/' . $fileId, $response->getBody());
    }

    public function testIndexRendersNothingForTheSectionPhotoWhenNoneExists(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $this->createSection('BAL01', $branchId, 'Ma section');

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testChiefSeesAllSections(): void
    {
        $balId = $this->createBranch('BAL', 'Baladins', 1);
        $louId = $this->createBranch('LOU', 'Louveteaux', 2);
        $this->createSection('BAL01', $balId, 'Section Bal');
        $this->createSection('LOU01', $louId, 'Section Lou');

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Section Bal', $body);
        $this->assertStringContainsString('Section Lou', $body);
    }

    public function testIntendantSeesOnlyLinkedSections(): void
    {
        // Set session as intendant
        AuthSession::login(2, 'intendant@test.be', 'intendant');

        $balId = $this->createBranch('BAL', 'Baladins', 1);
        $louId = $this->createBranch('LOU', 'Louveteaux', 2);
        $sectionA = $this->createSection('BAL01', $balId, 'Section Bal');
        $sectionB = $this->createSection('LOU01', $louId, 'Section Lou');

        // Link intendant to sectionA only
        $this->createMemberInSection($sectionA, 'Intendant', 'intendant', 'intendant@test.be');

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Section Bal', $body);
        $this->assertStringNotContainsString('Section Lou', $body);
    }

    public function testChiefSeesUnitStaffWhenNoRealSections(): void
    {
        // A chief always sees "Staff d'U", even with no imported sections —
        // StaffsController::index() ensures the section exists on every
        // load, so the empty-state message does not apply to them.
        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Staff d', $body);
        $this->assertStringNotContainsString('Aucune section disponible', $body);
    }

    public function testUnconfiguredSectionShowsWarning(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId); // No name

        // Select the unconfigured section explicitly.
        $request = new Request('GET', '/chefs/staffs', ['section' => (string) $sectionId], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        // Static template text is not HTML-escaped, so the apostrophe is literal.
        $this->assertStringContainsString("n'a pas encore de nom configuré", $body);
        // Renaming a section is no longer done from this page — the
        // warning must point chiefs to Config Desk instead of a
        // now-removed inline edit affordance.
        $this->assertStringContainsString('Config Desk', $body);
    }

    public function testIndexPassesActiveBadgesToChief(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Ma section');
        $this->createMemberInSection($sectionId, 'Alice', 'chief');
        $this->badgeService->create('Communication');

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('Communication', $response->getBody());
    }

    public function testIndexRendersBadgesAsAChipPickerPerMemberWithCorrectSelectionState(): void
    {
        // The badge picker reuses partials/chip_picker.html.twig (mode
        // multi) — same component/style as the section picker above the
        // staff list, one instance per member so each keeps its own
        // selection independent of the others.
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Ma section');
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');
        $assignedBadge = $this->badgeService->create('Communication');
        $unassignedBadge = $this->badgeService->create('Animation');
        $this->badgeService->toggleAssignment($memberYearId, $assignedBadge->id, 1);

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('id="badge-picker-' . $memberYearId . '"', $body);
        $this->assertStringContainsString('data-mode="multi"', $body);
        $this->assertStringContainsString('data-member-year-id="' . $memberYearId . '"', $body);

        // The Communication chip (assigned) must be selected, Animation
        // (not assigned) must not — order in the DOM is available_badges'
        // own order, so locate each chip by its data-id.
        $this->assertMatchesRegularExpression(
            '/data-id="' . $assignedBadge->id . '"[^>]*data-selected="true"/',
            $body
        );
        $this->assertMatchesRegularExpression(
            '/data-id="' . $unassignedBadge->id . '"[^>]*data-selected="false"/',
            $body
        );
    }

    public function testToggleBadgeAssignsThenUnassigns(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId);
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');
        $badge = $this->badgeService->create('Communication');

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest([
            'member_year_id' => $memberYearId,
            'badge_id' => $badge->id,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertTrue($decoded['assigned']);
        $this->assertCount(1, $this->badgeService->getBadgesForMemberYear($memberYearId));
    }

    public function testToggleBadgeValidatesCsrf(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId);
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');
        $badge = $this->badgeService->create('Communication');

        $request = $this->createJsonRequest([
            'member_year_id' => $memberYearId,
            'badge_id' => $badge->id,
            '_csrf_token' => 'invalid',
        ]);
        $response = $this->controller->toggleBadge($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testToggleBadgeWithInactiveBadgeReturnsError(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId);
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');
        $badge = $this->badgeService->create('Communication');
        $this->badgeService->setActive($badge->id, false);

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest([
            'member_year_id' => $memberYearId,
            'badge_id' => $badge->id,
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->toggleBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/chefs/staffs/update-section', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
