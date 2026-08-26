<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\Controller\UploadController;
use Core\Http\Request;
use Core\Import\AgeBranchRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Photo\AccountPhotoRepository;
use Core\Photo\AccountPhotoService;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Photo\LandscapeImageProcessor;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Photo\SectionPhotoProcessor;
use Core\Photo\SectionPhotoRepository;
use Core\Photo\SectionPhotoService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\ConfigurationMode;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class UploadControllerTest extends TestCase
{
    private \PDO $pdo;
    private string $tmpDir;
    private \Core\Photo\PhotoIngestionService $photoIngestionService;
    private UploadController $controller;
    private MemberPhotoService $memberPhotoService;
    private AccountPhotoService $accountPhotoService;
    private SectionPhotoService $sectionPhotoService;
    private \Core\Photo\UnitLogoService $unitLogoService;
    private JournalRepository $journalRepo;
    private EncryptionService $encryption;
    private int $memberId;
    private int $sectionId;
    private int $scoutYearId;
    private int $branchId;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->tmpDir = sys_get_temp_dir() . '/scoutmagic_upload_ctrl_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $fileRepo = new FileRepository($this->pdo);
        $uploadHandler = new class($fileRepo, $this->tmpDir) extends UploadHandler {
            protected function moveFile(string $from, string $to): bool
            {
                return copy($from, $to);
            }
        };

        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->memberPhotoService = new MemberPhotoService(new MemberPhotoRepository($this->pdo));
        $this->accountPhotoService = new AccountPhotoService(
            new AccountPhotoRepository($this->pdo),
            $fileRepo,
            $this->tmpDir
        );
        $this->sectionPhotoService = new SectionPhotoService(new SectionPhotoRepository($this->pdo));
        $this->journalRepo = new JournalRepository($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $memberService = new MemberService(
            new MemberYearRepository($this->pdo),
            $this->encryption,
            Connection::withPdo($this->pdo)
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'n');

        $settingService = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $settingService->register('pwa_background_color', '#ffffff', 'color', 'x', 'x');
        $settingService->register('pwa_icon_version', '1', 'number', 'x', 'x', null, null, null, false);
        $this->unitLogoService = new \Core\Photo\UnitLogoService(
            new \Core\Photo\UnitLogoProcessor(),
            $settingService,
            $this->tmpDir . '/logo',
            $this->tmpDir . '/logo_defaults'
        );

        $imageVariantService = new ImageVariantService($fileRepo, new ImageVariantProcessor(), $this->tmpDir);

        // The controller keeps two collaborators; everything that happens to
        // the bytes moved into Core\Photo\PhotoIngestionService. These tests
        // still drive the controller (they are about the web boundary: the
        // CSRF token, the authorization, the flash and the redirect), so they
        // build the real service rather than a double — the pipeline they
        // assert on is the production one.
        $this->photoIngestionService = new \Core\Photo\PhotoIngestionService(
            $uploadHandler,
            $editableContentService,
            $this->memberPhotoService,
            $this->sectionPhotoService,
            new SectionPhotoProcessor(),
            new LandscapeImageProcessor(),
            new AgeBranchRepository($this->pdo),
            $this->unitLogoService,
            $imageVariantService,
            $this->accountPhotoService
        );
        $this->controller = new UploadController($twig, $this->photoIngestionService, $memberService);
        $this->controller->setJournalService(new JournalService($this->journalRepo));

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BR1', 'Branch', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->branchId = $branchId;
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Louveteaux')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['user'] = ['user_account_id' => 1, 'email' => 'admin@test.com', 'role' => 'superadmin'];
        AuthSession::login(1, 'admin@test.com', 'superadmin');
        ConfigurationMode::activate('superadmin');
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
        ConfigurationMode::deactivate();
        $_SESSION = [];
    }

    /**
     * Inserts a member_years row for $this->memberId whose email blind
     * index matches $email, so MemberService::isLinkedToMember() resolves
     * true for it — used by the outside-config-mode member_photo tests.
     */
    private function linkMemberToEmail(string $email): void
    {
        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)), 'email');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('Test', 'member_years.first_name'),
            $this->encryption->encrypt('Member', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $blindIndex,
        ]);
    }

    // --- account_photo: your own face, and nobody else's ----------------

    public function testAccountPhotoContextSetsThePhotoOfTheCallersOwnAccount(): void
    {
        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'me.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $response = $this->controller->store($this->accountPhotoRequest('1'), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($this->accountPhotoService->resolveFileId(1));

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'account_photo_updated'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        unset($_FILES['file']);
    }

    /**
     * The whole boundary of this context: an account id in a request is
     * not an authorisation to set that account's face. Configuration
     * mode does not widen it either — which is why this test runs with it
     * active (setUp() activates it).
     */
    public function testAccountPhotoContextRefusesAnotherAccountsIdEvenInConfigurationMode(): void
    {
        // Two rows, so the second one is certainly not the id the session
        // carries (1) whatever this table's auto-increment starts at.
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc-me', 'idx-me']);
        $stmt->execute(['enc-other', 'idx-other']);
        $otherAccountId = (int) $this->pdo->lastInsertId();
        $this->assertNotSame(1, $otherAccountId);

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'me.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $response = $this->controller->store($this->accountPhotoRequest((string) $otherAccountId), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->accountPhotoService->resolveFileId($otherAccountId));
        // Nothing was stored at all — the refusal happens before the file
        // is ever handled.
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());

        unset($_FILES['file']);
    }

    public function testAccountPhotoContextRefusesASessionWithNoAccountAtAll(): void
    {
        AuthSession::logout();

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'me.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $response = $this->controller->store($this->accountPhotoRequest('1'), []);

        $this->assertSame(403, $response->getStatusCode());

        unset($_FILES['file']);
        AuthSession::login(1, 'admin@test.com', 'superadmin');
    }

    private function accountPhotoRequest(string $key): Request
    {
        return new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'account_photo',
            'key' => $key,
            'return_url' => '/account',
        ], [], []);
    }

    public function testMemberPhotoContextSetsPhotoForMemberAndYear(): void
    {
        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'photo.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'member_photo',
            'key' => $this->memberId . ':' . $this->scoutYearId,
            'return_url' => '/trombinoscope',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $fileId = $this->memberPhotoService->resolveFileId($this->memberId, $this->scoutYearId);
        $this->assertNotNull($fileId);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'member_photo_updated'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        unset($_FILES['file']);
    }

    public function testMemberPhotoContextReplacesExistingPhoto(): void
    {
        $tmpFile1 = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile1, 'name' => 'first.jpg', 'size' => filesize($tmpFile1), 'error' => UPLOAD_ERR_OK];
        $request1 = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'member_photo',
            'key' => $this->memberId . ':' . $this->scoutYearId,
            'return_url' => '/trombinoscope',
        ], [], []);
        $this->controller->store($request1, []);
        $firstFileId = $this->memberPhotoService->resolveFileId($this->memberId, $this->scoutYearId);

        $tmpFile2 = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile2, 'name' => 'second.jpg', 'size' => filesize($tmpFile2), 'error' => UPLOAD_ERR_OK];
        $request2 = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'member_photo',
            'key' => $this->memberId . ':' . $this->scoutYearId,
            'return_url' => '/trombinoscope',
        ], [], []);
        $this->controller->store($request2, []);
        $secondFileId = $this->memberPhotoService->resolveFileId($this->memberId, $this->scoutYearId);

        $this->assertNotSame($firstFileId, $secondFileId);

        unset($_FILES['file']);
    }

    /**
     * The member page's photo-replace overlay works outside configuration
     * mode — the logged-in account must be linked to the member the upload
     * key names. Covers the DoD's "allowed for linked account outside
     * config mode" requirement.
     */
    public function testMemberPhotoContextAllowedOutsideConfigModeForLinkedAccount(): void
    {
        ConfigurationMode::deactivate();
        AuthSession::login(1, 'parent@test.com', 'identified');
        $this->linkMemberToEmail('parent@test.com');

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'photo.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'member_photo',
            'key' => $this->memberId . ':' . $this->scoutYearId,
            'return_url' => '/members/1',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($this->memberPhotoService->resolveFileId($this->memberId, $this->scoutYearId));

        unset($_FILES['file']);
    }

    /**
     * Covers the DoD's "denied for non-linked logged-in account" — a
     * logged-in identified user with no member_years row matching this
     * member/year must be rejected, even with no chief/admin bypass
     * (member_photo has none outside configuration mode).
     */
    public function testMemberPhotoContextDeniedOutsideConfigModeForNonLinkedAccount(): void
    {
        ConfigurationMode::deactivate();
        AuthSession::login(1, 'stranger@test.com', 'identified');

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'photo.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'member_photo',
            'key' => $this->memberId . ':' . $this->scoutYearId,
            'return_url' => '/members/1',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->memberPhotoService->resolveFileId($this->memberId, $this->scoutYearId));

        unset($_FILES['file']);
    }

    /**
     * Non-member_photo contexts stay configuration-mode-only even now that
     * the /upload route's role_min is loosened to `identified` — a
     * superadmin who hasn't activated configuration mode must still be
     * rejected.
     */
    public function testSectionPhotoContextDeniedWithoutConfigModeEvenForSuperadmin(): void
    {
        ConfigurationMode::deactivate();

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'staff.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'section_photo',
            'key' => $this->sectionId . ':' . $this->scoutYearId,
            'return_url' => '/chefs/staffs',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(403, $response->getStatusCode());

        unset($_FILES['file']);
    }

    /**
     * The other half of the same widening covered above for editable_image
     * — section_photo is gated purely on ConfigurationMode::isActive() too,
     * so an admin-level (not just superadmin) config-mode session must now
     * be able to use it.
     */
    public function testSectionPhotoContextAllowedForAdminLevelConfigMode(): void
    {
        ConfigurationMode::deactivate();
        AuthSession::login(1, 'chief-unite@test.com', 'admin');
        ConfigurationMode::activate('admin');

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'staff.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'section_photo',
            'key' => $this->sectionId . ':' . $this->scoutYearId,
            'return_url' => '/chefs/staffs',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($this->sectionPhotoService->resolveFileId($this->sectionId, $this->scoutYearId));

        unset($_FILES['file']);
    }

    /**
     * age_branch_logo is a direct role check, not configuration-mode-only
     * — Config Desk is its own superadmin-only admin area with no session
     * flag to toggle, unlike editable_image/section_photo.
     */
    public function testAgeBranchLogoContextAllowedForSuperadminWithoutConfigMode(): void
    {
        ConfigurationMode::deactivate();

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'logo.png', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'age_branch_logo',
            'key' => (string) $this->branchId,
            'return_url' => '/config/functions',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $stmt = $this->pdo->prepare('SELECT logo_file_id FROM age_branches WHERE id = ?');
        $stmt->execute([$this->branchId]);
        $this->assertNotNull($stmt->fetchColumn());

        unset($_FILES['file']);
    }

    public function testAgeBranchLogoContextDeniedForNonSuperadminRole(): void
    {
        ConfigurationMode::deactivate();
        AuthSession::login(1, 'admin@test.com', 'admin');

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'logo.png', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'age_branch_logo',
            'key' => (string) $this->branchId,
            'return_url' => '/config/functions',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(403, $response->getStatusCode());

        unset($_FILES['file']);
    }

    /**
     * unit_logo (Installation & serveur's logo upload) is a direct role
     * check like age_branch_logo — its own admin page is already
     * superadmin-gated, no configuration-mode flag involved.
     */
    public function testUnitLogoContextAllowedForSuperadminWithoutConfigMode(): void
    {
        ConfigurationMode::deactivate();

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'logo.png', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'unit_logo',
            'key' => '',
            'return_url' => '/setup',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/setup', $response->getHeaders()['Location']);
        $this->assertTrue($this->unitLogoService->hasCustomLogo());
        $this->assertNotNull($this->unitLogoService->resolveIconContent('192'));

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'unit_logo_updated'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        unset($_FILES['file']);
    }

    /**
     * The iOS caveat rides the success flash itself (see ARCHITECTURE
     * §8.23 — iOS never re-reads the manifest/apple-touch-icon for an
     * already-installed app, no web API can refresh that icon), shown
     * once right after a successful upload rather than as a permanent
     * on-page fixture.
     */
    public function testUnitLogoContextSuccessFlashIncludesTheIosReinstallCaveat(): void
    {
        ConfigurationMode::deactivate();

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'logo.png', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'unit_logo',
            'key' => '',
            'return_url' => '/setup',
        ], [], []);

        $this->controller->store($request, []);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertNotNull($flash);
        $this->assertSame('success', $flash['type']);
        $this->assertStringContainsString('iOS', $flash['message']);
        $this->assertStringContainsString('Android', $flash['message']);

        unset($_FILES['file']);
    }

    public function testUnitLogoContextDeniedForNonSuperadminRole(): void
    {
        ConfigurationMode::deactivate();
        AuthSession::login(1, 'admin@test.com', 'admin');

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'logo.png', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'unit_logo',
            'key' => '',
            'return_url' => '/setup',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->unitLogoService->hasCustomLogo());

        unset($_FILES['file']);
    }

    /**
     * unit_logo never becomes a `files` table row — see
     * Core\Photo\UnitLogoService's own docblock (a favicon/manifest icon
     * is fetched with no session, same reasoning as the original PWA-only
     * version of this context).
     */
    public function testUnitLogoContextNeverCreatesAFilesTableRow(): void
    {
        $countBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'logo.png', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];
        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'unit_logo',
            'key' => '',
            'return_url' => '/setup',
        ], [], []);
        $this->controller->store($request, []);

        $countAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $this->assertSame($countBefore, $countAfter);

        unset($_FILES['file']);
    }

    public function testEditableImageContextSetsTheEditableContentRecord(): void
    {
        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'hero.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'editable_image',
            'key' => 'home.hero',
            'return_url' => '/',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM editable_contents WHERE content_key = 'home.hero'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        unset($_FILES['file']);
    }

    /**
     * Configuration mode widened from superadmin-only to admin (Chef
     * d'Unité) — analyzed and confirmed as a deliberate consequence for
     * every context gated purely on ConfigurationMode::isActive()
     * (Core\Http\Controller\UploadController::isUploadAuthorized()'s own
     * docblock). editable_image is exactly that kind of context: an admin
     * session (not superadmin) activating configuration mode must now be
     * able to use it, where before this lot it could not.
     */
    public function testEditableImageContextAllowedForAdminLevelConfigMode(): void
    {
        ConfigurationMode::deactivate();
        AuthSession::login(1, 'chief-unite@test.com', 'admin');
        ConfigurationMode::activate('admin');

        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'hero.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'editable_image',
            'key' => 'home.hero',
            'return_url' => '/',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM editable_contents WHERE content_key = 'home.hero'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        unset($_FILES['file']);
    }

    /**
     * The generic editable_image() mechanism must produce a landscape
     * image regardless of what was uploaded (matching the public news
     * article page's featured-image treatment — Core\Photo\
     * LandscapeImageProcessor) — a portrait source proves it actually ran
     * server-side rather than the raw upload being stored as-is.
     */
    public function testEditableImageContextCropsToTheLandscapeRatio(): void
    {
        $tmpFile = $this->createTempImageWithSize(400, 1000);
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'portrait.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'editable_image',
            'key' => 'home.hero',
            'return_url' => '/',
        ], [], []);
        $this->controller->store($request, []);

        $stmt = $this->pdo->query("SELECT content_value FROM editable_contents WHERE content_key = 'home.hero'");
        $fileId = (int) $stmt->fetchColumn();
        $this->assertGreaterThan(0, $fileId);

        $pathStmt = $this->pdo->prepare('SELECT relative_path FROM files WHERE id = ?');
        $pathStmt->execute([$fileId]);
        $relativePath = (string) $pathStmt->fetchColumn();
        $size = getimagesize($this->tmpDir . '/' . $relativePath);

        $this->assertNotFalse($size);
        $this->assertGreaterThan($size[1], $size[0], 'output must be landscape (wider than tall)');
        $this->assertEqualsWithDelta(1200 / 400, $size[0] / $size[1], 0.01);

        unset($_FILES['file']);
    }

    public function testSectionPhotoContextSetsPhotoForSectionAndYear(): void
    {
        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'staff.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'section_photo',
            'key' => $this->sectionId . ':' . $this->scoutYearId,
            'return_url' => '/chefs/staffs',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $fileId = $this->sectionPhotoService->resolveFileId($this->sectionId, $this->scoutYearId);
        $this->assertNotNull($fileId);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'section_photo_updated'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        unset($_FILES['file']);
    }

    /**
     * The real bug this prompt fixes (ARCHITECTURE §8.21): section_photo
     * is rendered on the public Contact and Sections pages, so its
     * underlying file's role_min must be 'public', not 'intendant' —
     * otherwise FileAccessGuard denies it to any logged-out visitor and
     * those pages show nothing at all. Covers both ends: the stored
     * role_min itself, and that a Role::PUBLIC FileAccessGuard actually
     * allows reading it back.
     */
    public function testSectionPhotoContextStoresRoleMinPublicAndIsReadableByAPublicRoleGuard(): void
    {
        $tmpFile = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'staff.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'section_photo',
            'key' => $this->sectionId . ':' . $this->scoutYearId,
            'return_url' => '/chefs/staffs',
        ], [], []);
        $this->controller->store($request, []);

        $fileId = $this->sectionPhotoService->resolveFileId($this->sectionId, $this->scoutYearId);
        $this->assertNotNull($fileId);

        $stmt = $this->pdo->prepare('SELECT role_min FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $this->assertSame('public', $stmt->fetchColumn());

        $guard = new \Core\File\FileAccessGuard(new \Core\File\FileRepository($this->pdo), \Core\Security\Role::PUBLIC);
        $this->assertNotNull($guard->check($fileId));

        unset($_FILES['file']);
    }

    public function testSectionPhotoContextReplacesExistingPhoto(): void
    {
        $tmpFile1 = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile1, 'name' => 'first.jpg', 'size' => filesize($tmpFile1), 'error' => UPLOAD_ERR_OK];
        $request1 = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'section_photo',
            'key' => $this->sectionId . ':' . $this->scoutYearId,
            'return_url' => '/chefs/staffs',
        ], [], []);
        $this->controller->store($request1, []);
        $firstFileId = $this->sectionPhotoService->resolveFileId($this->sectionId, $this->scoutYearId);

        $tmpFile2 = $this->createTempImage();
        $_FILES['file'] = ['tmp_name' => $tmpFile2, 'name' => 'second.jpg', 'size' => filesize($tmpFile2), 'error' => UPLOAD_ERR_OK];
        $request2 = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'section_photo',
            'key' => $this->sectionId . ':' . $this->scoutYearId,
            'return_url' => '/chefs/staffs',
        ], [], []);
        $this->controller->store($request2, []);
        $secondFileId = $this->sectionPhotoService->resolveFileId($this->sectionId, $this->scoutYearId);

        $this->assertNotSame($firstFileId, $secondFileId);

        unset($_FILES['file']);
    }

    /**
     * Module spec: "a traditional landscape ratio and the width being the
     * max container width" — a source image with a very different ratio
     * (here, a tall portrait) must end up stored as a 4:3 landscape image,
     * proving SectionPhotoProcessor actually ran server-side rather than
     * the raw upload being stored as-is.
     */
    public function testSectionPhotoContextCropsToA4By3LandscapeRatio(): void
    {
        $tmpFile = $this->createTempImageWithSize(400, 1000);
        $_FILES['file'] = ['tmp_name' => $tmpFile, 'name' => 'portrait.jpg', 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK];

        $request = new Request('POST', '/upload', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'context' => 'section_photo',
            'key' => $this->sectionId . ':' . $this->scoutYearId,
            'return_url' => '/chefs/staffs',
        ], [], []);
        $this->controller->store($request, []);

        $fileId = $this->sectionPhotoService->resolveFileId($this->sectionId, $this->scoutYearId);
        $this->assertNotNull($fileId);

        $stmt = $this->pdo->prepare('SELECT relative_path FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $relativePath = (string) $stmt->fetchColumn();
        $size = getimagesize($this->tmpDir . '/' . $relativePath);

        $this->assertNotFalse($size);
        $this->assertEqualsWithDelta(4 / 3, $size[0] / $size[1], 0.01);

        unset($_FILES['file']);
    }

    private function createTempImage(): string
    {
        return $this->createTempImageWithSize(10, 10);
    }

    private function createTempImageWithSize(int $width, int $height): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'img');
        $img = imagecreatetruecolor($width, $height);
        imagejpeg($img, $tmpFile);
        imagedestroy($img);
        return $tmpFile;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
