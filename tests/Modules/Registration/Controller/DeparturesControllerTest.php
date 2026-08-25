<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\DepartureService;
use Core\Member\DepartureRepository;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Registration\Controller\DeparturesController;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * "Départs" page: section-scoped by Core\Member\
 * SectionStaffAuthorizationService (never a recomputed cloisonnement),
 * animés-only listing (never staff), auto-save with the leaving/comment
 * fields updated independently, server-side re-check on every write, and
 * no personal data ever reaching the journal.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeparturesControllerTest extends TestCase
{
    private \PDO $pdo;
    private DeparturesController $controller;
    private DepartureService $departureService;
    private EncryptionService $encryption;
    private int $scoutYearId;
    private int $sectionAId;
    private int $sectionBId;
    private int $leaMemberYearId;
    private int $noeMemberYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->scoutYearId = $scoutYearService->ensureYear('2026-2027');

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->scoutYearId);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);

        $badgeRepo = new \Core\Badge\MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, $badgeRepo);
        $sectionStaffAuth = new SectionStaffAuthorizationService($connection, $this->encryption, $sectionService);
        $departureRepository = new DepartureRepository($this->pdo, $this->encryption);
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $this->departureService = new DepartureService($departureRepository, $journalService);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->sectionAId = $this->createSection('LOU01', $branchId, 'Section A');
        $this->sectionBId = $this->createSection('LOU02', $branchId, 'Section B');

        $this->createMemberInSection($this->sectionAId, 'ChiefA', 'chief@example.com', 'chief');
        $this->createMemberInSection($this->sectionBId, 'ChiefB', 'chiefb@example.com', 'chief');
        $this->leaMemberYearId = $this->createMemberInSection($this->sectionAId, 'Léa', 'lea@example.com', 'identified');
        $this->noeMemberYearId = $this->createMemberInSection($this->sectionBId, 'Noé', 'noe@example.com', 'identified');

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/registration/views';
        $twig = TwigFactory::create($templateDir, false, ['registration' => $moduleViews]);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/departs');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new DeparturesController(
            $twig, $sectionStaffAuth, $sectionService, $this->departureService, $scoutYearResolver
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    private function createSection(string $deskCode, int $branchId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMemberInSection(int $sectionId, string $firstName, string $email, string $functionRole): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, birth_date_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'), $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'), $this->encryption->blindIndex(mb_strtolower(trim($email)), 'email'),
            $this->encryption->encrypt('2015-01-01', 'member_years.birth_date'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('{$functionRole}', 'Fn {$functionRole}', '{$functionRole}')");
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionRole]);
        $functionId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $this->branchIdOf($sectionId)]);

        return $memberYearId;
    }

    private function branchIdOf(int $sectionId): int
    {
        $stmt = $this->pdo->prepare('SELECT age_branch_id FROM sections WHERE id = ?');
        $stmt->execute([$sectionId]);
        return (int) $stmt->fetchColumn();
    }

    private function jsonRequest(string $method, string $path, array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs([$method, $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }

    /**
     * Départs anchors on the PUBLIC year, like Passage and Prévisions —
     * never on a session preview or a staff year. It used to follow
     * getEffectiveYear(), so a chief previewing next year marked departures
     * on that year's member_years rows, which PassageService (filtering
     * `leaving = 0` on the public year) would never see.
     */
    public function testPublicYearWinsOverASessionPreviewAndAStaffYear(): void
    {
        $scoutYearService = new ScoutYearService($this->pdo);
        $farYearId = $scoutYearService->ensureYear('2028-2029');

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $farYearId);
        ScoutYearSession::setPreview($farYearId);

        AuthSession::login(1, 'chief@example.com', 'chief');

        $response = $this->controller->index(new Request('GET', '/departs', [], [], [], []), []);

        $this->assertStringContainsString('2026-2027', $response->getBody());
        $this->assertStringNotContainsString('2028-2029', $response->getBody());
        // The roster still resolves against the public year, so Léa is there.
        $this->assertStringContainsString('Léa', $response->getBody());
    }

    /**
     * The scout year this page writes on is stated in the header itself,
     * not only inside the long subtitle sentence.
     */
    public function testThePageHeaderNamesTheScoutYearBeingMarked(): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');

        $body = $this->controller->index(new Request('GET', '/departs', [], [], [], []), [])->getBody();

        $this->assertMatchesRegularExpression(
            '#<h1[^>]*>Départs</h1>\s*<span class="badge text-bg-secondary">2026-2027</span>#',
            $body
        );
    }

    public function testChiefSeesOnlyOwnSection(): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');

        $response = $this->controller->index(new Request('GET', '/departs', [], [], [], []), []);

        $this->assertStringContainsString('Léa', $response->getBody());
        $this->assertStringNotContainsString('Noé', $response->getBody());
        // Only one section staffed — no section picker shown.
        $this->assertStringNotContainsString('id="section_id"', $response->getBody());
    }

    public function testAdminSeesAllSections(): void
    {
        AuthSession::login(1, 'root@example.com', 'admin');

        $response = $this->controller->index(new Request('GET', '/departs', [], [], [], []), []);

        // Admin's default view is the first section, but the picker must
        // list every section, including the one it didn't default to.
        $this->assertStringContainsString('Section A', $response->getBody());
        $this->assertStringContainsString('Section B', $response->getBody());
    }

    public function testAnimesListExcludesStaff(): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');

        $response = $this->controller->index(new Request('GET', '/departs', [], [], [], []), []);

        $this->assertStringNotContainsString('ChiefA', $response->getBody());
    }

    public function testUpdateMarksLeaving(): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');
        $token = CsrfGuard::generateToken();

        $response = $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => $token, 'leaving' => true]),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->departureService->getStatus($this->leaMemberYearId)->leaving);
    }

    public function testUpdateUnmarksLeaving(): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');
        $token = CsrfGuard::generateToken();

        $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => $token, 'leaving' => true]),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );
        $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => $token, 'leaving' => false]),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );

        $this->assertFalse($this->departureService->getStatus($this->leaMemberYearId)->leaving);
    }

    public function testCommentIsStoredEncryptedNotInClear(): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');
        $token = CsrfGuard::generateToken();

        $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => $token, 'leaving' => true]),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );
        $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => $token, 'comment' => 'Conflit avec un animateur']),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );

        $stmt = $this->pdo->prepare('SELECT leaving_comment_encrypted FROM member_years WHERE id = ?');
        $stmt->execute([$this->leaMemberYearId]);
        $raw = (string) $stmt->fetchColumn();

        $this->assertStringNotContainsString('Conflit', $raw);
        $this->assertSame('Conflit avec un animateur', $this->departureService->getStatus($this->leaMemberYearId)->comment);
    }

    public function testCommentSavedIndependentlyOfLeavingFlag(): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');
        $token = CsrfGuard::generateToken();

        $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => $token, 'leaving' => true]),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );
        $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => $token, 'comment' => 'Conflit avec un animateur']),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );

        $status = $this->departureService->getStatus($this->leaMemberYearId);
        $this->assertTrue($status->leaving);
        $this->assertSame('Conflit avec un animateur', $status->comment);

        // A later comment-only save must not touch the leaving flag —
        // the module spec's own concurrency requirement.
        $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => $token, 'comment' => 'Motif précisé']),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );
        $status = $this->departureService->getStatus($this->leaMemberYearId);
        $this->assertTrue($status->leaving);
        $this->assertSame('Motif précisé', $status->comment);
    }

    public function testUpdateOnMemberOfAnotherSectionIsRefused(): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');
        $token = CsrfGuard::generateToken();

        $response = $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->noeMemberYearId}", ['_csrf_token' => $token, 'leaving' => true]),
            ['member_year_id' => (string) $this->noeMemberYearId]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->departureService->getStatus($this->noeMemberYearId)->leaving);
    }

    public function testUpdateRejectsInvalidCsrf(): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');

        $response = $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => 'bad', 'leaving' => true]),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->departureService->getStatus($this->leaMemberYearId)->leaving);
    }

    public function testCommentNeverAppearsInJournal(): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');
        $token = CsrfGuard::generateToken();

        $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => $token, 'leaving' => true]),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );
        $this->controller->update(
            $this->jsonRequest('POST', "/departs/{$this->leaMemberYearId}", ['_csrf_token' => $token, 'comment' => 'Situation familiale difficile']),
            ['member_year_id' => (string) $this->leaMemberYearId]
        );

        $stmt = $this->pdo->query('SELECT description, context FROM event_log');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->assertStringNotContainsString('Situation familiale', (string) $row['description']);
            $this->assertStringNotContainsString('Situation familiale', (string) $row['context']);
            $this->assertStringNotContainsString('Léa', (string) $row['description']);
            $this->assertStringNotContainsString('Léa', (string) $row['context']);
        }
    }
}
