<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\SosStaff\Controller\SosAdminController;
use Modules\SosStaff\Repository\ExcludedSectionRepository;
use Modules\SosStaff\Repository\OnCallAssignment;
use Modules\SosStaff\Repository\OnCallRepository;
use Modules\SosStaff\Repository\ProviderCredentialRepository;
use Modules\SosStaff\Repository\SosSettingsRepository;
use Modules\SosStaff\Service\CalendarSyncService;
use Modules\SosStaff\Service\OnCallService;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosSettingsService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class SosAdminControllerTest extends TestCase
{
    private \PDO $pdo;
    private SosAdminController $controller;
    private Environment $twig;
    private OnCallRepository $onCallRepository;
    private SchedulerRepository $schedulerRepository;
    private SosSettingsService $settingsService;
    private \Modules\SosStaff\Service\RedirectService $redirectService;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $encryption, $memberBadgeRepository);
        $memberYearRepository = new MemberYearRepository($this->pdo);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('transition_hour', '10:00', 'text', 'Heure', 'desc', 'sos_staff');
        $settingService->register('email_notifications_enabled', '1', 'boolean', 'Emails', 'desc', 'sos_staff');

        $this->settingsService = new SosSettingsService(
            new ExcludedSectionRepository($this->pdo),
            new SosSettingsRepository($this->pdo),
            $sectionService,
            $memberYearRepository,
            new UnitStaffSectionService($this->pdo),
            $settingService
        );

        $this->onCallRepository = new OnCallRepository($this->pdo);
        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $schedulerService = new SchedulerService($this->schedulerRepository);
        $onCallService = new OnCallService($this->onCallRepository, $schedulerService, $this->settingsService);

        $providerConfigService = new ProviderConfigService(new ProviderCredentialRepository($this->pdo, $encryption));
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->redirectService = $this->createMock(RedirectService::class);
        $calendarSyncService = $this->createMock(CalendarSyncService::class);

        $scoutYearService = new ScoutYearService($this->pdo);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepository);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/sos_staff/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'sos_staff');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_email', 'admin@test.be');
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $this->controller = new SosAdminController(
            $this->twig,
            $providerConfigService,
            $this->settingsService,
            $onCallService,
            $this->redirectService,
            $calendarSyncService,
            $sectionService,
            $schedulerService,
            $scoutYearResolver,
            $journalService,
            null
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'admin@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data, string $path = '/admin/sos/x'): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    /**
     * @return int member_id (persistent identity)
     */
    private function createStaffduMember(string $totem, string $mobile): int
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $staffduId = (new UnitStaffSectionService($this->pdo))->ensureSection();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, mobile_encrypted)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $encryption->encrypt('Jean'), $encryption->encrypt('Dupont'),
            $encryption->encrypt($totem), $encryption->encrypt($mobile),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) VALUES ('CU', 'Chef Unité', 'admin', 1)");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'CU'")->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $staffduId]);

        return $memberId;
    }

    public function testIndexRendersPage(): void
    {
        $response = $this->controller->index(new Request('GET', '/admin/sos', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('SOS Staff', $response->getBody());
    }

    public function testIndexShowsProviderNotConfiguredWarning(): void
    {
        $response = $this->controller->index(new Request('GET', '/admin/sos', [], [], [], []), []);

        $this->assertStringContainsString('Aucun fournisseur', $response->getBody());
    }

    public function testUpdateDefaultNumberValidatesCsrf(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470123456');
        $response = $this->controller->updateDefaultNumber(
            $this->jsonRequest(['member_id' => $memberId, '_csrf_token' => 'bad']),
            []
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateDefaultNumberPersistsMemberChoice(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470123456');
        $token = $this->csrfToken();
        $response = $this->controller->updateDefaultNumber(
            $this->jsonRequest(['member_id' => $memberId, '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('+32470123456', $this->settingsService->getDefaultNumber($this->scoutYearId));
    }

    public function testUpdateDefaultNumberRejectsMissingMember(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->updateDefaultNumber(
            $this->jsonRequest(['_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testUpdateSettingsPersistsTransitionHour(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->updateSettings(
            $this->jsonRequest(['transition_hour' => '08:30', '_csrf_token' => $token]),
            []
        );

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame('08:30', $this->settingsService->getTransitionHour());
    }

    public function testUpdateSettingsPersistsEmailToggle(): void
    {
        $token = $this->csrfToken();
        $this->controller->updateSettings(
            $this->jsonRequest(['email_notifications_enabled' => false, '_csrf_token' => $token]),
            []
        );

        $this->assertFalse($this->settingsService->isEmailNotificationsEnabled());
    }

    public function testSaveOnCallPersistsAssignmentsAndSchedulesTransitions(): void
    {
        // resolveTarget() only "sees" a member marked oncall if they're
        // part of the roster order (getStaffOptions(), i.e. a real Staff
        // d'U member with a known mobile) — an arbitrary member_id with no
        // such row is silently ignored for transition purposes.
        $memberId = $this->createStaffduMember('Akela', '+32470000001');

        $token = $this->csrfToken();
        $response = $this->controller->saveOnCall(
            $this->jsonRequest([
                'year' => 2026,
                'month' => 7,
                'cells' => [
                    ['member_id' => $memberId, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
                ],
                '_csrf_token' => $token,
            ]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $found = $this->onCallRepository->findForDate('2026-07-05');
        $this->assertCount(1, $found);
        $this->assertSame($memberId, $found[0]->memberId);

        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey(OnCallService::MODULE_ID, OnCallService::TASK_KEY);
        $this->assertNotEmpty($scheduled);
    }

    public function testResavingTheGridDoesNotAccumulateStaleEntriesInThePlannedList(): void
    {
        // Re-saving cancels the previous month's pending transitions
        // (Service\OnCallService::cancelPendingTransitionsInRange()) but
        // that's a soft cancel (status='canceled', row kept for audit) —
        // the planned-transitions list must filter those out, or every
        // re-save appears to leave duplicate/stale rows behind.
        $memberId = $this->createStaffduMember('Akela', '+32470000001');
        $token = $this->csrfToken();

        for ($i = 0; $i < 3; $i++) {
            $this->controller->saveOnCall(
                $this->jsonRequest([
                    'year' => 2026,
                    'month' => 7,
                    'cells' => [
                        ['member_id' => $memberId, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
                    ],
                    '_csrf_token' => $token,
                ]),
                []
            );
        }

        // Use the transitions-only fragment, not index()'s full page — the
        // default-number dropdown also renders "Akela" once, which would
        // otherwise pollute a whole-page substring count.
        $response = $this->controller->plannedTransitions(new Request('GET', '/admin/sos/transitions', ['month' => '2026-07'], [], [], []), []);
        $body = $response->getBody();

        // Only one active pair of transitions (into Akela on the 5th, back
        // to default on the 6th) should be visible, not one pair per save.
        $this->assertSame(1, substr_count($body, 'Akela'));
    }

    public function testSaveOnCallRejectsInvalidMonth(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->saveOnCall(
            $this->jsonRequest(['year' => 2026, 'month' => 13, 'cells' => [], '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testSaveOnCallIgnoresCellsWithInvalidState(): void
    {
        $token = $this->csrfToken();
        $this->controller->saveOnCall(
            $this->jsonRequest([
                'year' => 2026,
                'month' => 7,
                'cells' => [
                    ['member_id' => 1, 'date' => '2026-07-05', 'state' => 'bogus'],
                ],
                '_csrf_token' => $token,
            ]),
            []
        );

        $this->assertSame([], $this->onCallRepository->findForDate('2026-07-05'));
    }

    public function testPlannedTransitionsSortedFutureFirstWithNextHighlighted(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');
        $schedulerService = new SchedulerService($this->schedulerRepository);
        $now = new \DateTimeImmutable();
        $today = $now->format('Y-m-d');

        $past = $now->modify('-2 hours');
        $near = $now->modify('+2 hours');
        $far = $now->modify('+4 hours');
        $schedulerService->schedule(OnCallService::MODULE_ID, OnCallService::TASK_KEY, $past, ['member_id' => $memberId], $today);
        $schedulerService->schedule(OnCallService::MODULE_ID, OnCallService::TASK_KEY, $near, ['member_id' => $memberId], $today);
        $schedulerService->schedule(OnCallService::MODULE_ID, OnCallService::TASK_KEY, $far, ['member_id' => $memberId], $today);

        $response = $this->controller->index(new Request('GET', '/admin/sos', ['month' => $now->format('Y-m')], [], [], []), []);
        $body = $response->getBody();

        $posFar = strpos($body, $far->format('d/m/Y H:i'));
        $posNear = strpos($body, $near->format('d/m/Y H:i'));
        $posPast = strpos($body, $past->format('d/m/Y H:i'));
        $this->assertNotFalse($posFar);
        $this->assertNotFalse($posNear);
        $this->assertNotFalse($posPast);
        $this->assertLessThan($posNear, $posFar, 'furthest future entry should render above the nearer future one');
        $this->assertLessThan($posPast, $posNear, 'future entries should render above past ones');

        // Only the single nearest upcoming transition gets highlighted.
        $this->assertSame(1, substr_count($body, 'Prochain changement'));
        $highlightPos = strpos($body, 'Prochain changement');
        $this->assertGreaterThan($posNear, $highlightPos);
        $this->assertLessThan($posPast, $highlightPos);
    }

    public function testPlannedTransitionsPaginateBeyondTenEntries(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');
        $schedulerService = new SchedulerService($this->schedulerRepository);
        $now = new \DateTimeImmutable();
        $today = $now->format('Y-m-d');

        for ($i = 0; $i < 12; $i++) {
            $schedulerService->schedule(
                OnCallService::MODULE_ID,
                OnCallService::TASK_KEY,
                $now->modify("+{$i} hours"),
                ['member_id' => $memberId],
                $today
            );
        }

        $page1 = $this->controller->index(new Request('GET', '/admin/sos', ['month' => $now->format('Y-m')], [], [], []), []);
        $this->assertStringContainsString('pagination', $page1->getBody());

        $page2 = $this->controller->index(new Request('GET', '/admin/sos', ['month' => $now->format('Y-m'), 'transitions_page' => '2'], [], [], []), []);
        $this->assertStringContainsString('pagination', $page2->getBody());
        $this->assertNotSame($page1->getBody(), $page2->getBody());
    }

    public function testPlannedTransitionsAjaxEndpointRendersSamePartialAsIndex(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');
        $schedulerService = new SchedulerService($this->schedulerRepository);
        $now = new \DateTimeImmutable();
        $today = $now->format('Y-m-d');

        for ($i = 0; $i < 12; $i++) {
            $schedulerService->schedule(
                OnCallService::MODULE_ID,
                OnCallService::TASK_KEY,
                $now->modify("+{$i} hours"),
                ['member_id' => $memberId],
                $today
            );
        }

        $response = $this->controller->plannedTransitions(
            new Request('GET', '/admin/sos/transitions', ['month' => $now->format('Y-m'), 'transitions_page' => '2'], [], [], []),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('list-group', $response->getBody());
        $this->assertStringContainsString('pagination', $response->getBody());
        // A bare fragment — no page chrome.
        $this->assertStringNotContainsString('SOS Staff', $response->getBody());
    }

    /**
     * RBAC boundary for /admin/sos (Espace admin, role_min admin):
     * admin -> 200, intendant -> 403.
     */
    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/admin/sos', SosAdminController::class, 'index', 'admin');

        $configFile = sys_get_temp_dir() . '/test_sos_admin_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(SosAdminController::class, $this->controller);

        return $fc;
    }

    public function testAdminGetsPage(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/sos', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIntendantIsDenied(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $response = $this->buildFrontController()->handle(new Request('GET', '/admin/sos', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }
}
