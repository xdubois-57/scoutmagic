<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Http\Controller\BadgeConfigurationController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Tests\TestTwig;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class BadgeConfigurationControllerTest extends TestCase
{
    private BadgeConfigurationController $controller;
    private BadgeRepository $badgeRepository;
    private MemberBadgeRepository $memberBadgeRepository;
    private BadgeService $badgeService;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $journalRepo = new JournalRepository($this->pdo);
        $journalService = new JournalService($journalRepo);

        $twig = TestTwig::create([], ['param' => fn(string $k) => 'Test']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', BadgeConfigurationController::PATH);

        $this->badgeRepository = new BadgeRepository($this->pdo);
        $this->memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService(
    new SectionRepository(Connection::withPdo($this->pdo)),
    new MemberProfileRepository(Connection::withPdo($this->pdo), new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->memberBadgeRepository)
);
        $this->badgeService = new BadgeService($this->badgeRepository, $this->memberBadgeRepository, $sectionService);

        $resolver = $this->createStub(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear(1, '2026-2027', null));

        $this->controller = new BadgeConfigurationController($twig, $this->badgeService, $journalService, $resolver);
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testIndexSeedsAndRendersDefaultBadges(): void
    {
        $request = new Request('GET', BadgeConfigurationController::PATH, [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Badges', $body);
        $this->assertStringContainsString('Infirmier', $body);
        $this->assertStringContainsString('Trésorier', $body);
    }

    /**
     * Moved to the Espace chefs d'U (issue #621): the page carries the
     * shared rail of the Badges pages, with itself selected, and the title
     * of the mockup.
     */
    public function testIndexCarriesTheBadgesRailWithConfigurationSelected(): void
    {
        $body = $this->controller->index(new Request('GET', BadgeConfigurationController::PATH, [], [], [], []), [])->getBody();

        $this->assertStringContainsString('id="badges-page-picker"', $body);
        $this->assertStringContainsString('aria-label="Pages Badges"', $body);
        $this->assertMatchesRegularExpression(
            '~<a[^>]*href="/admin/badges/configuration"[^>]*aria-current="page"~',
            $body
        );
        $this->assertStringContainsString('Configuration des badges', $body);
        // The holders tab is named after its year, never « Année en cours ».
        $this->assertMatchesRegularExpression('~<a href="/admin/badges"[^>]*>\s*(?:<[^>]+>\s*)*2026-2027~', $body);
    }

    /**
     * The one layout change of the move: below md the name takes the whole
     * line and the bin is pushed to the right of the line under it; from md
     * up the switch goes back to pushing itself right, as before.
     */
    public function testIndexWrapsTheRowUnderTheNameBelowTheBreakpoint(): void
    {
        $this->badgeService->create('Communication');

        $body = $this->controller->index(new Request('GET', BadgeConfigurationController::PATH, [], [], [], []), [])->getBody();

        $this->assertMatchesRegularExpression('~class="form-control form-control-sm badge-name-input badge-config-name[ "]~', $body);
        $this->assertStringNotContainsString('max-width:220px;min-width:120px;" value=', $body, 'The inline width would pin the name on a phone.');
        $this->assertStringContainsString('class="form-check form-switch ms-md-auto text-nowrap"', $body);
        $this->assertMatchesRegularExpression('~badge-delete-btn ms-auto ms-md-0"~', $body);
    }

    public function testTheOldAddressRedirectsPermanentlyToTheNewOne(): void
    {
        $response = $this->controller->legacyRedirect(new Request('GET', '/config/badges', [], [], [], []), []);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/admin/badges/configuration', $response->getHeaders()['Location']);
    }

    public function testIndexDoesNotRenderModulesContent(): void
    {
        // The whole point of the split — no leftover module-list markup on
        // the badges page.
        $request = new Request('GET', BadgeConfigurationController::PATH, [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('module-list', $response->getBody());
        $this->assertStringNotContainsString('Mode configuration', $response->getBody());
    }

    public function testIndexGreysOutTheReadOnlyNameOfDefaultBadges(): void
    {
        $request = new Request('GET', BadgeConfigurationController::PATH, [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertMatchesRegularExpression(
            '/badge-name-input badge-config-name bg-body-secondary text-body-secondary"[^>]*value="Infirmier"[^>]*readonly/',
            $body
        );
    }

    /**
     * Module spec: a "Référent {section}" badge "can be activated and
     * deactivated" like any other badge — the switch must never be
     * rendered disabled.
     */
    public function testIndexNeverDisablesTheActiveToggleForAReferentBadge(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BR1', 'Branch', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)')
            ->execute(['LOU01', $branchId, 'Louveteaux']);

        $request = new Request('GET', BadgeConfigurationController::PATH, [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Référent Louveteaux', $body);
        $this->assertDoesNotMatchRegularExpression('/badge-active-input[^>]*disabled/', $body);
    }

    public function testIndexDisablesDeleteButtonForAssignedBadge(): void
    {
        $badge = $this->badgeService->create('Communication');
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $scoutYearId, 'enc', 'enc']);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $this->memberBadgeRepository->assign($memberYearId, $badge->id, null);

        $request = new Request('GET', BadgeConfigurationController::PATH, [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertMatchesRegularExpression(
            '/data-id="' . $badge->id . '".*?badge-delete-btn[^>]*disabled/s',
            $body
        );
    }

    public function testAddBadgeCreatesBadge(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $request = $this->createJsonRequest(['name' => 'Communication', '_csrf_token' => $token]);
        $response = $this->controller->addBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('Communication', $decoded['badge']['name']);
        $this->assertNotNull($this->badgeRepository->findByName('Communication'));
    }

    public function testAddBadgeRejectsDuplicateName(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $this->badgeService->create('Communication');

        $request = $this->createJsonRequest(['name' => 'Communication', '_csrf_token' => $token]);
        $response = $this->controller->addBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testAddBadgeWithInvalidCsrfReturns403(): void
    {
        $request = $this->createJsonRequest(['name' => 'Communication', '_csrf_token' => 'bad']);
        $response = $this->controller->addBadge($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateBadgeRenames(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $badge = $this->badgeService->create('Communication');

        $request = $this->createJsonRequest([
            'badge_id' => $badge->id, 'name' => 'Com Interne', '_csrf_token' => $token,
        ]);
        $response = $this->controller->updateBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('Com Interne', $this->badgeRepository->findById($badge->id)->name);
    }

    public function testUpdateBadgeRejectsRenamingDefaultBadge(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $this->badgeService->ensureDefaults();
        $infirmier = array_values(array_filter($this->badgeService->getAll(), fn($b) => $b->name === 'Infirmier'))[0];

        $request = $this->createJsonRequest([
            'badge_id' => $infirmier->id, 'name' => 'Nurse', '_csrf_token' => $token,
        ]);
        $response = $this->controller->updateBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame('Infirmier', $this->badgeRepository->findById($infirmier->id)->name);
    }

    public function testToggleBadgeActiveDeactivatesBadge(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $badge = $this->badgeService->create('Communication');

        $request = $this->createJsonRequest(['badge_id' => $badge->id, 'active' => false, '_csrf_token' => $token]);
        $response = $this->controller->toggleBadgeActive($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertFalse($this->badgeRepository->findById($badge->id)->isActive);
    }

    public function testDeleteBadgeRejectsDefaultBadge(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $this->badgeService->ensureDefaults();
        $infirmier = array_values(array_filter($this->badgeService->getAll(), fn($b) => $b->name === 'Infirmier'))[0];

        $request = $this->createJsonRequest(['badge_id' => $infirmier->id, '_csrf_token' => $token]);
        $response = $this->controller->deleteBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertNotNull($this->badgeRepository->findById($infirmier->id));
    }

    public function testDeleteBadgeSucceedsForUnusedCustomBadge(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, 'admin@test.com', 'admin');

        $badge = $this->badgeService->create('Communication');

        $request = $this->createJsonRequest(['badge_id' => $badge->id, '_csrf_token' => $token]);
        $response = $this->controller->deleteBadge($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNull($this->badgeRepository->findById($badge->id));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/admin/badges/add', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
