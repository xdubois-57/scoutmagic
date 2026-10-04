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
use Tests\RequestWithInput;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Tests\TestTwig;

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
    private \Core\View\EditableContentService $editableContent;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $this->sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $this->encryption, $memberBadgeRepository)
);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $this->memberService = new MemberService(
    $memberYearRepo,
    new MemberProfileRepository($connection, $this->encryption)
);
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
        // The CURRENT year, not a year spelled out: this fixture means
        // « the year the application is in », and saying that as a
        // literal made it expire on 1 September. See
        // DatabaseTestHelper::scoutYear().
        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        // Create Twig
        $twig = TestTwig::create([], ['param' => fn(string $k) => 'Test']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chief@test.be');
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/chefs/staffs');
        $twig->addGlobal('route_breadcrumb', ['label' => 'Staffs', 'parents' => ['Espace animateurs']]);
        // The real section_photo() reads its service and the year from the
        // globals, as public/index.php supplies them.
        $sectionPhotoService = new \Core\Photo\SectionPhotoService(new \Core\Photo\SectionPhotoRepository($this->pdo));
        $twig->addGlobal('_section_photo_service', $sectionPhotoService);
        $twig->addGlobal('effective_scout_year_id', $this->scoutYearId);

        $this->controller = new StaffsController(
            $twig,
            $this->sectionService,
            $this->memberService,
            $scoutYearResolver,
            $journalService,
            $this->badgeService,
            new UnitStaffSectionService(new \Core\Member\Repository\UnitStaffSectionRepository($this->pdo)),
            $this->sectionDocumentService,
            $settingService,
            new \Core\Member\SectionStaffAuthorizationService(
    new \Core\Member\Repository\StaffedSectionRepository($connection, $this->encryption, new \Core\Member\MemberEmailRepository($this->pdo, $this->encryption)),
    $this->sectionService
),
            $this->editableContent = new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($this->pdo)),
            new \Core\Member\Repository\MemberSectionTotemRepository($this->pdo, $this->encryption)
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

        // The real section_photo(): the medium rendition, not the original
        // (the double this test used to register pointed at /files/{id}).
        $this->assertStringContainsString('src="/files/' . $fileId . '/md"', $response->getBody());
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
        // warning must point chiefs to Correspondances Desk instead of a
        // now-removed inline edit affordance.
        $this->assertStringContainsString('Correspondances Desk', $body);
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

    public function testIndexRendersBadgesAsASelectBarPerMemberWithCorrectSelectionState(): void
    {
        // The badge picker reuses partials/select_bar.html.twig (mode
        // multi) — same component as the section picker above the staff
        // list, one instance per member so each keeps its own selection
        // independent of the others.
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

    // --- Section documents: the write affordance follows the SECTION, the
    // badge picker still follows the ROLE (IT-01) ---

    public function testTheAddDocumentButtonAppearsOnASectionTheChiefAnimates(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Section Bal');
        $this->createMemberInSection($sectionId, 'Alice', 'chief', 'chief@test.be');

        $request = new Request('GET', '/chefs/staffs', ['section' => (string) $sectionId], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringContainsString('Ajouter un document', $body);
    }

    public function testTheAddDocumentButtonIsAbsentOnASectionTheChiefDoesNotAnimate(): void
    {
        $balId = $this->createBranch('BAL', 'Baladins', 1);
        $louId = $this->createBranch('LOU', 'Louveteaux', 2);
        $own = $this->createSection('BAL01', $balId, 'Section Bal');
        $other = $this->createSection('LOU01', $louId, 'Section Lou');
        $this->createMemberInSection($own, 'Alice', 'chief', 'chief@test.be');

        $request = new Request('GET', '/chefs/staffs', ['section' => (string) $other], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringNotContainsString('Ajouter un document', $body);
    }

    public function testAnotherSectionsDocumentsRenderReadOnly(): void
    {
        $balId = $this->createBranch('BAL', 'Baladins', 1);
        $louId = $this->createBranch('LOU', 'Louveteaux', 2);
        $own = $this->createSection('BAL01', $balId, 'Section Bal');
        $other = $this->createSection('LOU01', $louId, 'Section Lou');
        $this->createMemberInSection($own, 'Alice', 'chief', 'chief@test.be');
        $this->sectionDocumentService->upload(
            $other, $this->scoutYearId, str_repeat('%PDF-1.4 x', 50), 'application/pdf',
            'camp.pdf', 'Carnet des Louveteaux', null, null
        );

        $request = new Request('GET', '/chefs/staffs', ['section' => (string) $other], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        // Still readable — narrowing the WRITE must never hide the section.
        $this->assertStringContainsString('Carnet des Louveteaux', $body);
        // But no editable control and no reorder/delete endpoint.
        $this->assertStringNotContainsString('section-document-title-input', $body);
        $this->assertStringNotContainsString('/chefs/staffs/documents/reorder', $body);
        $this->assertStringNotContainsString('/chefs/staffs/documents/delete', $body);
    }

    public function testAdminAnimatesEverySectionAndKeepsTheAddButtonEverywhere(): void
    {
        AuthSession::login(3, 'cu@test.be', 'admin');

        $louId = $this->createBranch('LOU', 'Louveteaux', 2);
        $sectionId = $this->createSection('LOU01', $louId, 'Section Lou');

        $request = new Request('GET', '/chefs/staffs', ['section' => (string) $sectionId], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringContainsString('Ajouter un document', $body);
    }

    public function testAChiefWithoutAnyStaffedSectionIsToldWhy(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Section Bal');
        // Deliberately NOT linked to chief@test.be — the Desk import case
        // where the account carries no section-bound animateur function.
        $this->createMemberInSection($sectionId, 'Alice', 'chief', 'someone.else@test.be');

        $request = new Request('GET', '/chefs/staffs', ['section' => (string) $sectionId], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringNotContainsString('Ajouter un document', $body);
        $this->assertStringContainsString('aucune fonction d\'animateur ne vous est rattachée', $body);
    }

    public function testTheExplanationIsNotShownToAChiefWhoDoesAnimateASection(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Section Bal');
        $this->createMemberInSection($sectionId, 'Alice', 'chief', 'chief@test.be');

        $request = new Request('GET', '/chefs/staffs', ['section' => (string) $sectionId], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringNotContainsString('aucune fonction d\'animateur ne vous est rattachée', $body);
    }

    public function testTheBadgePickerStillFollowsTheRoleNotTheSection(): void
    {
        // IT-01 narrows the DOCUMENTS, deliberately not the badges: a chief
        // keeps assigning badges in any section, exactly as before.
        $balId = $this->createBranch('BAL', 'Baladins', 1);
        $louId = $this->createBranch('LOU', 'Louveteaux', 2);
        $own = $this->createSection('BAL01', $balId, 'Section Bal');
        $other = $this->createSection('LOU01', $louId, 'Section Lou');
        $this->createMemberInSection($own, 'Alice', 'chief', 'chief@test.be');
        $memberYearId = $this->createMemberInSection($other, 'Bob', 'chief', 'bob@test.be');
        $this->badgeService->create('Communication');

        $request = new Request('GET', '/chefs/staffs', ['section' => (string) $other], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertStringContainsString('id="badge-picker-' . $memberYearId . '"', $body);
        $this->assertStringContainsString('Communication', $body);
    }

    // --- Section totem (issue #722) ---

    public function testSaveSectionTotemSetsChangesAndClears(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');
        $memberYearId = $this->createMemberInSection($sectionId, 'Élie', 'chief');
        $totems = new \Core\Member\Repository\MemberSectionTotemRepository($this->pdo, $this->encryption);

        foreach (['Akela', 'Baloo', ''] as $totem) {
            $response = $this->controller->saveSectionTotem($this->sectionTotemRequest($memberYearId, $sectionId, $totem), []);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue(json_decode($response->getBody(), true)['success']);
            $expected = $totem === '' ? [] : [$memberYearId => [$sectionId => $totem]];
            $this->assertSame($expected, $totems->forMemberYears([$memberYearId]));
        }

        // The journal says what changed for whom, never the totem itself.
        $journal = (string) $this->pdo->query("SELECT GROUP_CONCAT(description || ' ' || COALESCE(context, '')) FROM event_log")->fetchColumn();
        $this->assertStringContainsString('Totem de section', $journal);
        $this->assertStringNotContainsString('Akela', $journal);
    }

    public function testSaveSectionTotemRefusesASectionTheMemberIsNotIn(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $meute = $this->createSection('LOU01', $branchId, 'Meute');
        $autre = $this->createSection('LOU02', $branchId, 'Autre meute');
        $memberYearId = $this->createMemberInSection($meute, 'Élie', 'chief');

        $response = $this->controller->saveSectionTotem($this->sectionTotemRequest($memberYearId, $autre, 'Akela'), []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM member_section_totems')->fetchColumn());
    }

    public function testSaveSectionTotemRefusesAnAnimeOfTheSection(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $meute = $this->createSection('LOU01', $branchId, 'Meute');
        $anime = $this->createMemberInSection($meute, 'Lou', 'identified', 'lou@test.be');

        $response = $this->controller->saveSectionTotem($this->sectionTotemRequest($anime, $meute, 'Akela'), []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM member_section_totems')->fetchColumn());
    }

    public function testSaveSectionTotemRefusesAChiefOfAnotherScoutYear(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $meute = $this->createSection('LOU01', $branchId, 'Meute');
        $memberYearId = $this->createMemberInSection($meute, 'Élie', 'chief');
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2010-2011', '2010-09-01', '2011-08-31')");
        $pastYear = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("UPDATE member_years SET scout_year_id = {$pastYear} WHERE id = {$memberYearId}");

        $response = $this->controller->saveSectionTotem($this->sectionTotemRequest($memberYearId, $meute, 'Akela'), []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM member_section_totems')->fetchColumn());
    }

    public function testSaveSectionTotemRefusesATooLongTotemAndABadToken(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');
        $memberYearId = $this->createMemberInSection($sectionId, 'Élie', 'chief');

        $tooLong = $this->controller->saveSectionTotem($this->sectionTotemRequest($memberYearId, $sectionId, str_repeat('a', 101)), []);
        $this->assertSame(422, $tooLong->getStatusCode());

        $forged = $this->controller->saveSectionTotem($this->createJsonRequest([
            'member_year_id' => $memberYearId, 'section_id' => $sectionId, 'totem' => 'Akela', '_csrf_token' => 'invalid',
        ]), []);
        $this->assertSame(403, $forged->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM member_section_totems')->fetchColumn());
    }

    public function testTheStaffCardShowsTheSectionTotemAndTheChiefCanEditIt(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');
        $memberYearId = $this->createMemberInSection($sectionId, 'Élie', 'chief');
        (new \Core\Member\Repository\MemberSectionTotemRepository($this->pdo, $this->encryption))->set($memberYearId, $sectionId, 'Akela');

        $body = $this->controller->index(new Request('GET', '/chefs/staffs', ['section' => (string) $sectionId], [], [], []), [])->getBody();

        $this->assertStringContainsString('Élie (Akela)', $body);
        $this->assertMatchesRegularExpression('/class="[^"]*section-totem-input[^"]*"[^>]*value="Akela"/s', $body);
    }

    public function testAnIntendantSeesTheSectionTotemButCannotEditIt(): void
    {
        AuthSession::login(2, 'intendant@test.be', 'intendant');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');
        $memberYearId = $this->createMemberInSection($sectionId, 'Élie', 'chief');
        $this->createMemberInSection($sectionId, 'Intendant', 'intendant', 'intendant@test.be');
        (new \Core\Member\Repository\MemberSectionTotemRepository($this->pdo, $this->encryption))->set($memberYearId, $sectionId, 'Akela');

        $body = $this->controller->index(new Request('GET', '/chefs/staffs', ['section' => (string) $sectionId], [], [], []), [])->getBody();

        $this->assertStringContainsString('Élie (Akela)', $body);
        $this->assertStringNotContainsString('section-totem-input', $body);
    }

    private function sectionTotemRequest(int $memberYearId, int $sectionId, string $totem): Request
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $this->createJsonRequest([
            'member_year_id' => $memberYearId,
            'section_id' => $sectionId,
            'totem' => $totem,
            '_csrf_token' => $token,
        ]);
    }

    // --- The section's own text (#725) ---

    public function testTheSectionTextSitsBetweenThePhotoAndTheStaffAndIsTheSameEveryYear(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');
        $this->createMemberInSection($sectionId, 'Alice', 'chief', 'chief@test.be');
        $this->editableContent->set('staff_text_' . $sectionId, '<p>Notre staff se présente.</p>', 'rich_text', 1);
        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('core/section_photos/x.jpg', 'x.jpg', 'image/jpeg', 100)");
        $fileId = (int) $this->pdo->lastInsertId();
        (new \Core\Photo\SectionPhotoRepository($this->pdo))->upsert($sectionId, $this->scoutYearId, $fileId, null);

        $body = $this->staffsPage($sectionId);

        $text = strpos($body, 'Notre staff se présente.');
        $photo = strpos($body, 'src="/files/' . $fileId . '/md"');
        $this->assertNotFalse($text);
        $this->assertNotFalse($photo);
        $this->assertLessThan($text, $photo, 'the photo comes first');
        $this->assertLessThan(strpos($body, 'Alice'), $text, 'the text comes before the staff list');
        $this->assertStringContainsString('data-save-url="/chefs/staffs/text"', $body);
        $this->assertStringContainsString('rich-text-field.js', $body);
    }

    public function testAReaderWithoutTextSeesNoEmptyBlock(): void
    {
        AuthSession::login(2, 'intendant@test.be', 'intendant');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');
        $this->createMemberInSection($sectionId, 'Intendant', 'intendant', 'intendant@test.be');

        $body = $this->staffsPage($sectionId);

        $this->assertStringNotContainsString('section-text', $body);
        $this->assertStringNotContainsString('rich-text-field-edit-btn', $body);
    }

    public function testAReaderSeesAWrittenTextButNoEditButton(): void
    {
        AuthSession::login(2, 'intendant@test.be', 'intendant');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');
        $this->createMemberInSection($sectionId, 'Intendant', 'intendant', 'intendant@test.be');
        $this->editableContent->set('staff_text_' . $sectionId, '<p>Bienvenue.</p>', 'rich_text', 1);

        $body = $this->staffsPage($sectionId);

        $this->assertStringContainsString('Bienvenue.', $body);
        $this->assertStringNotContainsString('rich-text-field-edit-btn', $body);
    }

    public function testAnAnimateurWithoutTextIsInvitedToWrite(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');
        $this->createMemberInSection($sectionId, 'Alice', 'chief', 'chief@test.be');

        $body = $this->staffsPage($sectionId);

        $this->assertStringContainsString('section-text-empty', $body);
        $this->assertStringContainsString('rich-text-field-edit-btn', $body);
    }

    /** No edit mode is involved: the session has none, and the save works. */
    public function testAnAnimateurSavesTheTextOfTheirOwnSectionOutsideEditMode(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');
        $this->createMemberInSection($sectionId, 'Alice', 'chief', 'chief@test.be');
        unset($_SESSION['config_mode']);

        $response = $this->controller->saveSectionText($this->sectionTextRequest('staff_text_' . $sectionId, '<p>Coucou<script>x</script></p>'), []);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $stored = (string) $this->editableContent->get('staff_text_' . $sectionId);
        $this->assertStringContainsString('Coucou', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertSame($stored, json_decode($response->getBody(), true)['value']);
    }

    public function testAnAnimateurCannotWriteAnotherSectionsText(): void
    {
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $own = $this->createSection('LOU01', $branchId, 'Meute');
        $other = $this->createSection('LOU02', $branchId, 'Autre meute');
        $this->createMemberInSection($own, 'Alice', 'chief', 'chief@test.be');

        $response = $this->controller->saveSectionText($this->sectionTextRequest('staff_text_' . $other, '<p>Intrus</p>'), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->editableContent->get('staff_text_' . $other));
        $this->assertStringNotContainsString(
            'rich-text-field-edit-btn',
            $this->staffsPage($other),
            'nor is the button offered there'
        );
    }

    public function testAChefDUniteWritesEverySectionsText(): void
    {
        AuthSession::login(3, 'admin@test.be', 'admin');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');

        $response = $this->controller->saveSectionText($this->sectionTextRequest('staff_text_' . $sectionId, '<p>Du Staff d’U</p>'), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Du Staff d’U', (string) $this->editableContent->get('staff_text_' . $sectionId));
    }

    public function testTheEndpointWritesNoOtherKeyAndChecksTheToken(): void
    {
        AuthSession::login(3, 'admin@test.be', 'admin');
        $branchId = $this->createBranch('LOU', 'Louveteaux', 1);
        $sectionId = $this->createSection('LOU01', $branchId, 'Meute');

        foreach (['home_intro', 'staff_text_0', 'staff_text_' . $sectionId . '_x', 'staff_text_999'] as $key) {
            $status = $this->controller->saveSectionText($this->sectionTextRequest($key, '<p>x</p>'), [])->getStatusCode();
            $this->assertContains($status, [400, 403], $key);
            $this->assertNull($this->editableContent->get($key), $key);
        }

        $forged = $this->controller->saveSectionText($this->createJsonRequest([
            'key' => 'staff_text_' . $sectionId, 'value' => '<p>x</p>', 'type' => 'rich_text', '_csrf_token' => 'invalid',
        ]), []);
        $this->assertSame(403, $forged->getStatusCode());
        $this->assertNull($this->editableContent->get('staff_text_' . $sectionId));
    }

    private function staffsPage(int $sectionId): string
    {
        return $this->controller->index(new Request('GET', '/chefs/staffs', ['section' => (string) $sectionId], [], [], []), [])->getBody();
    }

    private function sectionTextRequest(string $key, string $value): Request
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $this->createJsonRequest(['key' => $key, 'value' => $value, 'type' => 'rich_text', '_csrf_token' => $token]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = new RequestWithInput('POST', '/chefs/staffs/update-section', [], [], [], [], json_encode($data));

        return $request;
    }
}
