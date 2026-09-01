<?php

declare(strict_types=1);

namespace Tests\Core\Member\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\Controller\TemporaryMemberController;
use Core\Member\Repository\MemberSearchRepository;
use Core\Member\Service\MemberSearchService;
use Core\Member\TemporaryMemberSession;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\ConfigurationMode;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TemporaryMemberControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private TemporaryMemberController $controller;
    private JournalRepository $journalRepo;
    private int $yearId;
    private int $otherYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $scoutYearService = new ScoutYearService($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'P', 'P', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'S', 'S', null, '^[0-9]+$', null, false);

        $memberYearRepo = new MemberYearRepository($this->pdo);
        $resolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);
        $searchService = new MemberSearchService(new MemberSearchRepository($connection, $this->enc), $scoutYearService);

        $this->yearId = $scoutYearService->ensureYear('2025-2026');
        $this->otherYearId = $scoutYearService->ensureYear('2024-2025');
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearId);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = $this->buildTwig($templateDir);

        $this->journalRepo = new JournalRepository($this->pdo);
        $this->controller = new TemporaryMemberController(
            $twig,
            $searchService,
            $resolver,
            new JournalService($this->journalRepo),
            $memberYearRepo
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            session_start();
        }
        $_SESSION = [];
        AuthSession::login(1, 'admin@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Enough of TwigFactory's real environment to render errors/404.html.twig,
     * which extends base.html.twig — same minimal set as
     * Tests\Core\Member\Controller\MemberSearchControllerTest.
     */
    private function buildTwig(string $templateDir): Environment
    {
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        // The shared French format filters (core/View/TwigFactory.php) used by
        // the templates under test - same rendering as the shipped ones.
        $twig->addFilter(new \Twig\TwigFilter('date_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')));
        $twig->addFilter(new \Twig\TwigFilter('datetime_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')));
        $twig->addFilter(new \Twig\TwigFilter('money', fn($a) => $a === null || $a === '' ? '' : number_format((float) $a, 2, ',', ' ') . ' €'));
        $twig->addFilter(new \Twig\TwigFilter('money_cents', fn($c) => $c === null || $c === '' ? '' : number_format(((int) $c) / 100, 2, ',', ' ') . ' €'));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.be');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('temporary_member_name', null);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 't'));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new TwigFunction('param', fn(string $k) => 'Test'));

        return $twig;
    }

    private function seedMember(?int $yearId = null, bool $isActive = true): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, email_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $yearId ?? $this->yearId,
            $this->enc->encrypt('Jean', 'member_years.first_name'),
            $this->enc->encrypt('Dupont', 'member_years.last_name'),
            $this->enc->encrypt('Mowgli', 'member_years.totem'),
            $this->enc->encrypt('anime@test.be', 'member_years.email'),
            $isActive ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A second annual row for the same person, in a later year — what a
     * prepared staff year leaves behind.
     *
     * @return int the new member_years.id
     */
    private function seedAnotherYearForSameMember(int $memberYearId, string $label): int
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);
        $memberId = (int) $stmt->fetchColumn();

        $yearId = (new ScoutYearService($this->pdo))->ensureYear($label);

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, email_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $yearId,
            $this->enc->encrypt('Jean', 'member_years.first_name'),
            $this->enc->encrypt('Dupont', 'member_years.last_name'),
            $this->enc->encrypt('Mowgli', 'member_years.totem'),
            $this->enc->encrypt('anime@test.be', 'member_years.email'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function post(string $path, bool $validCsrf = true): Request
    {
        $token = CsrfGuard::generateToken();

        return new Request(
            'POST',
            $path,
            [],
            ['_csrf_token' => $validCsrf ? $token : 'wrong'],
            [],
            ['HTTP_REFERER' => 'https://example.test/admin/members']
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function journalEntries(string $type): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_log WHERE event_type = ?');
        $stmt->execute([$type]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testAdminAddsTheMemberAndConfigurationModeTurnsOn(): void
    {
        $memberYearId = $this->seedMember();

        $response = $this->controller->add($this->post("/admin/members/{$memberYearId}/temporary-access"), ['id' => (string) $memberYearId]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/members', $response->getHeaders()['Location'] ?? null);
        $this->assertSame($memberYearId, TemporaryMemberSession::get());
        $this->assertTrue(ConfigurationMode::isActive());
    }

    public function testAddIsJournaledWithoutPersonalData(): void
    {
        $memberYearId = $this->seedMember();

        $this->controller->add($this->post("/admin/members/{$memberYearId}/temporary-access"), ['id' => (string) $memberYearId]);

        $entries = $this->journalEntries('temporary_member_added');
        $this->assertCount(1, $entries);
        $this->assertSame('security', $entries[0]['level']);
        $this->assertSame(1, (int) $entries[0]['user_account_id']);
        $this->assertStringContainsString((string) $memberYearId, (string) $entries[0]['context']);
        // Nothing identifying the member itself ever reaches the journal.
        $this->assertStringNotContainsString('Mowgli', (string) $entries[0]['context']);
        $this->assertStringNotContainsString('Dupont', (string) $entries[0]['context']);
    }

    public function testChiefIsDeniedOneLevelBelowRoleMin(): void
    {
        $memberYearId = $this->seedMember();
        AuthSession::setRole('chief');

        $response = $this->controller->add($this->post("/admin/members/{$memberYearId}/temporary-access"), ['id' => (string) $memberYearId]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull(TemporaryMemberSession::get());
        $this->assertSame([], $this->journalEntries('temporary_member_added'));
    }

    public function testAddRejectsAnInvalidCsrfToken(): void
    {
        $memberYearId = $this->seedMember();

        $response = $this->controller->add(
            $this->post("/admin/members/{$memberYearId}/temporary-access", false),
            ['id' => (string) $memberYearId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertNull(TemporaryMemberSession::get());
    }

    public function testAddRefusesAMemberWithNoRowInTheYearInEffect(): void
    {
        // Present in 2024-2025 only, while the session looks at 2025-2026.
        // The override must always name a row of the year in effect, so
        // this is refused — but with a sentence, not a bare 404: the admin
        // is looking at a real member's real sheet, and « introuvable »
        // would describe neither.
        $memberYearId = $this->seedMember($this->otherYearId);

        $response = $this->controller->add($this->post("/admin/members/{$memberYearId}/temporary-access"), ['id' => (string) $memberYearId]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString(
            'pas inscrit cette année scoute',
            (string) (\Core\Http\FlashMessage::get()['message'] ?? '')
        );
        $this->assertNull(TemporaryMemberSession::get());
    }

    /**
     * The bug this method exists for, and it only ever bit the staff.
     *
     * MemberSearchController::show() normalises onto the member's MOST
     * RECENT annual row — deliberately, so a link carrying a past year's id
     * still shows the current sheet. The button on that page therefore
     * carries NEXT year's id for anyone who already has a row there, which
     * from the moment a staff year is prepared is every animateur. The
     * handler looked that id up in the year in effect, did not find it, and
     * answered 404 — for the staff, and for nobody else.
     */
    public function testAddAcceptsTheIdOfALaterYearAndSetsTheRowForTheYearInEffect(): void
    {
        $memberYearId = $this->seedMember();
        $nextYearId = $this->seedAnotherYearForSameMember($memberYearId, '2026-2027');

        $response = $this->controller->add(
            $this->post("/admin/members/{$nextYearId}/temporary-access"),
            ['id' => (string) $nextYearId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            $memberYearId,
            TemporaryMemberSession::get(),
            'the override must name the row of the year in effect, never the one the sheet displayed'
        );
    }

    public function testTheJournalNamesTheRowActuallySetNotTheOneAskedFor(): void
    {
        $memberYearId = $this->seedMember();
        $nextYearId = $this->seedAnotherYearForSameMember($memberYearId, '2026-2027');

        $this->controller->add(
            $this->post("/admin/members/{$nextYearId}/temporary-access"),
            ['id' => (string) $nextYearId]
        );

        $stmt = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'temporary_member_added' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotFalse($stmt);

        $this->assertStringContainsString('"member_year_id":' . $memberYearId, (string) $stmt->fetchColumn());
    }

    public function testAddRejectsAnUnknownMember(): void
    {
        $response = $this->controller->add($this->post('/admin/members/999999/temporary-access'), ['id' => '999999']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull(TemporaryMemberSession::get());
    }

    public function testAddRefusesAnInactiveMember(): void
    {
        $memberYearId = $this->seedMember(null, false);

        $response = $this->controller->add($this->post("/admin/members/{$memberYearId}/temporary-access"), ['id' => (string) $memberYearId]);

        // Refused where the admin can see why, rather than accepted into a
        // session where MemberService would silently resolve it to nothing.
        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull(TemporaryMemberSession::get());
        $this->assertSame([], $this->journalEntries('temporary_member_added'));
    }

    public function testRemoveClearsTheOverrideAndJournalsIt(): void
    {
        $memberYearId = $this->seedMember();
        $this->controller->add($this->post("/admin/members/{$memberYearId}/temporary-access"), ['id' => (string) $memberYearId]);

        $response = $this->controller->remove($this->post('/admin/members/temporary-access/remove'), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull(TemporaryMemberSession::get());
        $this->assertCount(1, $this->journalEntries('temporary_member_removed'));
    }

    public function testRemoveStillWorksAfterTheSessionLostTheAdminRole(): void
    {
        $memberYearId = $this->seedMember();
        $this->controller->add($this->post("/admin/members/{$memberYearId}/temporary-access"), ['id' => (string) $memberYearId]);
        AuthSession::setRole('chief');

        $this->controller->remove($this->post('/admin/members/temporary-access/remove'), []);

        $this->assertNull(TemporaryMemberSession::get());
    }

    public function testRemoveRejectsAnInvalidCsrfToken(): void
    {
        $memberYearId = $this->seedMember();
        $this->controller->add($this->post("/admin/members/{$memberYearId}/temporary-access"), ['id' => (string) $memberYearId]);

        $response = $this->controller->remove($this->post('/admin/members/temporary-access/remove', false), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertSame($memberYearId, TemporaryMemberSession::get());
    }

    public function testDeactivatingConfigurationModeDropsTheTemporaryMember(): void
    {
        $memberYearId = $this->seedMember();
        $this->controller->add($this->post("/admin/members/{$memberYearId}/temporary-access"), ['id' => (string) $memberYearId]);

        (new \Core\Http\Controller\ConfigModeController(
            $this->buildTwig(dirname(__DIR__, 4) . '/core/View/templates')
        ))->deactivate($this->post('/config-mode/deactivate'), []);

        $this->assertFalse(ConfigurationMode::isActive());
        $this->assertNull(TemporaryMemberSession::get());
    }
}
