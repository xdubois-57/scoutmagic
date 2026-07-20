<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Calendar\Controller\CalendarConfigController;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarNotificationService;
use Modules\Calendar\Service\CalendarService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class CalendarConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private CalendarConfigController $controller;
    private CalendarService $calendarService;
    private CalendarEventRepository $eventRepository;
    private CalendarRepository $calendarRepository;
    private SettingService $settingService;
    private SectionService $sectionService;
    private JournalService $journalService;
    private CalendarNotificationService $notificationService;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->calendarRepository = new CalendarRepository($this->pdo);
        $this->eventRepository = new CalendarEventRepository($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $encryption, $memberBadgeRepository);
        $this->sectionService = $sectionService;
        $this->calendarService = new CalendarService(
            $this->calendarRepository,
            $this->eventRepository,
            $sectionService,
            new CalendarUnitFeedTokenRepository($this->pdo)
        );

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register('event_default_title', 'Réunion', 'text', 'Nom par défaut', 'desc', 'calendar');
        $this->settingService->register('event_default_start_time', '14:00', 'text', 'Heure début', 'desc', 'calendar');
        $this->settingService->register('event_default_end_time', '16:00', 'text', 'Heure fin', 'desc', 'calendar');
        $this->settingService->register('event_default_location', '', 'text', 'Lieu', 'desc', 'calendar');
        $this->settingService->register('notify_multiday_events_enabled', '0', 'boolean', 'Rappels', 'desc', 'calendar');
        $this->settingService->register('notify_multiday_events_days_before', '14', 'text', 'Délai', 'desc', 'calendar');
        $this->notificationService = new CalendarNotificationService(
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $this->settingService,
            $this->calendarService,
            $this->eventRepository
        );

        $journalService = new JournalService(new JournalRepository($this->pdo));
        $this->journalService = $journalService;

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/calendar/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'calendar');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig = $twig;
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'superadmin@test.be');
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new TwigFunction('param', fn(string $k) => 'https://example.test'));

        $this->controller = new CalendarConfigController(
            $twig,
            $this->calendarService,
            $sectionService,
            $this->settingService,
            $journalService,
            $this->notificationService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function createSection(string $deskCode, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $deskCode, 10]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testIndexRendersPageWithDefaultsSectionsAndUnitLink(): void
    {
        $this->createSection('BAL01', 'Renards');

        $request = new Request('GET', '/config/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Réunion', $body);
        $this->assertStringContainsString('Renards', $body);
        $this->assertStringContainsString('Animateurs', $body);
        $this->assertStringContainsString('Unité complète', $body);
        $this->assertStringContainsString('Notifications', $body);
        $this->assertStringContainsString('notify-days-before', $body);
    }

    public function testIndexShowsNonSupprimableForSectionCalendars(): void
    {
        $this->createSection('BAL01', 'Renards');

        $request = new Request('GET', '/config/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('Non supprimable', $response->getBody());
    }

    public function testIndexDisablesDeleteButtonForCalendarWithEvents(): void
    {
        $calendar = $this->calendarService->addCalendar('Anniversaires', 'public');
        $this->eventRepository->create($calendar->id, 'Event', '2026-01-01', null, null, null, null, null, null);

        $request = new Request('GET', '/config/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertMatchesRegularExpression(
            '/data-calendar-id="' . $calendar->id . '".*?disabled/s',
            $response->getBody()
        );
    }

    public function testUpdateDefaultsChangesSettings(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest([
            'event_default_title' => 'Activité',
            'event_default_start_time' => '10:00',
            'event_default_end_time' => '12:00',
            'event_default_location' => 'Local Scout',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->updateDefaults($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('Activité', $this->settingService->get('event_default_title', 'calendar'));
    }

    public function testUpdateDefaultsValidatesCsrf(): void
    {
        $request = $this->createJsonRequest(['event_default_title' => 'X', '_csrf_token' => 'bad']);
        $response = $this->controller->updateDefaults($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateNotificationSettingsPersistsBothValues(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['enabled' => true, 'days_before' => 21, '_csrf_token' => $token]);
        $response = $this->controller->updateNotificationSettings($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertTrue($this->notificationService->isEnabled());
        $this->assertSame(21, $this->notificationService->getDaysBefore());
    }

    public function testUpdateNotificationSettingsRejectsOutOfRangeDaysBefore(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['days_before' => 0, '_csrf_token' => $token]);
        $response = $this->controller->updateNotificationSettings($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testUpdateNotificationSettingsValidatesCsrf(): void
    {
        $request = $this->createJsonRequest(['enabled' => true, '_csrf_token' => 'bad']);
        $response = $this->controller->updateNotificationSettings($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateVisibilityChangesCalendarVisibility(): void
    {
        $calendar = $this->calendarService->addCalendar('Test', 'public');
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['calendar_id' => $calendar->id, 'visibility' => 'admin', '_csrf_token' => $token]);
        $response = $this->controller->updateVisibility($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('admin', $this->calendarRepository->findById($calendar->id)->visibility);
    }

    public function testAddCalendarCreatesCustomCalendar(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['name' => 'Anniversaires', 'visibility' => 'public', '_csrf_token' => $token]);
        $response = $this->controller->addCalendar($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNotNull($this->calendarRepository->findById($decoded['calendar_id']));
    }

    public function testAddCalendarRejectsDuplicateName(): void
    {
        $this->calendarService->addCalendar('Anniversaires', 'public');
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['name' => 'Anniversaires', 'visibility' => 'public', '_csrf_token' => $token]);
        $response = $this->controller->addCalendar($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testRegenerateTokenChangesIcsToken(): void
    {
        $calendar = $this->calendarService->addCalendar('Test', 'public');
        $oldToken = $calendar->icsToken;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['calendar_id' => $calendar->id, '_csrf_token' => $token]);
        $response = $this->controller->regenerateToken($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNotSame($oldToken, $decoded['ics_token']);
    }

    public function testDeleteCalendarRemovesUnusedCustomCalendar(): void
    {
        $calendar = $this->calendarService->addCalendar('Test', 'public');
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['calendar_id' => $calendar->id, '_csrf_token' => $token]);
        $response = $this->controller->deleteCalendar($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNull($this->calendarRepository->findById($calendar->id));
    }

    public function testDeleteCalendarRejectsCalendarWithEvents(): void
    {
        $calendar = $this->calendarService->addCalendar('Test', 'public');
        $this->eventRepository->create($calendar->id, 'Event', '2026-01-01', null, null, null, null, null, null);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['calendar_id' => $calendar->id, '_csrf_token' => $token]);
        $response = $this->controller->deleteCalendar($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertNotNull($this->calendarRepository->findById($calendar->id));
    }

    public function testDeleteCalendarRejectsSectionCalendar(): void
    {
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['calendar_id' => $calendar->id, '_csrf_token' => $token]);
        $response = $this->controller->deleteCalendar($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testRegenerateUnitTokenChangesToken(): void
    {
        $oldToken = $this->calendarService->getOrCreateUnitFeedToken();
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['_csrf_token' => $token]);
        $response = $this->controller->regenerateUnitToken($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNotSame($oldToken, $decoded['token']);
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/config/calendar', CalendarConfigController::class, 'index', 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_calendar_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(
            CalendarConfigController::class,
            new CalendarConfigController($this->twig, $this->calendarService, $this->sectionService, $this->settingService, $this->journalService, $this->notificationService)
        );

        return $fc;
    }

    /**
     * RBAC boundary for /config/calendar (Configuration menu, role_min
     * superadmin): superadmin -> 200 (renders), admin ("Chef d'Unité",
     * the espace_admin ceiling) -> 403 — confirms the page moved out from
     * under espace_admin, not just its label.
     */
    public function testSuperadminGetsPage(): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/calendar', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Calendrier', $response->getBody());
    }

    public function testAdminIsDenied(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/calendar', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/calendar/defaults', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
