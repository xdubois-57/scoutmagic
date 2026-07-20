<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Core\View\MonthGrid\MonthGridBuilder;
use Modules\Calendar\Controller\CalendarChiefController;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarPersonalTokenRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarEventService;
use Modules\Calendar\Service\CalendarNotificationService;
use Modules\Calendar\Service\CalendarPickerService;
use Modules\Calendar\Service\CalendarService;
use Modules\Calendar\Service\PersonalFeedService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class CalendarChiefControllerTest extends TestCase
{
    private \PDO $pdo;
    private CalendarChiefController $controller;
    private CalendarService $calendarService;
    private CalendarEventRepository $eventRepository;
    private CalendarRepository $calendarRepository;
    private int $scoutYearId;

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
        $this->calendarService = new CalendarService(
            $this->calendarRepository,
            $this->eventRepository,
            $sectionService,
            new CalendarUnitFeedTokenRepository($this->pdo)
        );
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('notify_multiday_events_enabled', '0', 'boolean', 'Rappels', 'desc', 'calendar');
        $settingService->register('notify_multiday_events_days_before', '14', 'text', 'Délai', 'desc', 'calendar');
        $notificationService = new CalendarNotificationService(
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $settingService,
            $this->calendarService,
            $this->eventRepository
        );
        $calendarEventService = new CalendarEventService($this->eventRepository, $this->calendarService, $notificationService);

        $memberYearRepo = new MemberYearRepository($this->pdo);
        $memberService = new MemberService($memberYearRepo, $encryption, $connection);
        $scoutYearService = new ScoutYearService($this->pdo);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $roleResolver = new RoleResolver($memberYearRepo, $encryption, $this->pdo);
        $userAccountRepository = new UserAccountRepository($this->pdo, $encryption);
        $personalFeedService = new PersonalFeedService(
            new CalendarPersonalTokenRepository($this->pdo),
            $this->calendarService,
            $this->eventRepository,
            $roleResolver,
            $memberService,
            $userAccountRepository,
            $sectionService
        );

        $settingService->register('event_default_title', 'Réunion', 'text', 'Nom par défaut', 'desc', 'calendar');
        $settingService->register('event_default_start_time', '14:00', 'text', 'Heure début', 'desc', 'calendar');
        $settingService->register('event_default_end_time', '16:00', 'text', 'Heure fin', 'desc', 'calendar');
        $settingService->register('event_default_location', '', 'text', 'Lieu', 'desc', 'calendar');

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/calendar/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'calendar');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chief@test.be');
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $calendarPickerService = new CalendarPickerService($this->calendarService, $personalFeedService);

        $this->controller = new CalendarChiefController(
            $twig,
            $this->calendarService,
            $calendarPickerService,
            new MonthGridBuilder(),
            $calendarEventService,
            $sectionService,
            $memberService,
            $scoutYearResolver,
            $journalService,
            $settingService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.be', 'chief');
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

    public function testIndexRendersPage(): void
    {
        $request = new Request('GET', '/chefs/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Calendrier', $response->getBody());
    }

    public function testIndexPrefillsFormWithConfiguredDefaults(): void
    {
        $request = new Request('GET', '/chefs/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('const defaultStartTime = "14:00"', $response->getBody());
        $this->assertStringContainsString('const defaultEndTime = "16:00"', $response->getBody());
    }

    public function testIndexRendersMonthGridWithCalendarPicker(): void
    {
        $request = new Request('GET', '/chefs/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('calendar-picker', $response->getBody());
        $this->assertStringContainsString('Mes évènements', $response->getBody());
        $this->assertStringContainsString('calendar-week', $response->getBody());
    }

    public function testIndexSelectedCalendarOnlyShowsThatCalendarsEvents(): void
    {
        $sectionA = $this->createSection('BAL01', 'Renards');
        $sectionB = $this->createSection('LOU01', 'Meute');
        $this->calendarService->ensureSectionCalendars();
        $calendarA = $this->calendarRepository->findBySectionId($sectionA);
        $calendarB = $this->calendarRepository->findBySectionId($sectionB);
        $this->eventRepository->create($calendarA->id, 'Réunion renards', '2099-01-01', null, null, null, null, null, null);
        $this->eventRepository->create($calendarB->id, 'Réunion louveteaux', '2099-01-01', null, null, null, null, null, null);

        $request = new Request('GET', '/chefs/calendar', ['calendar' => (string) $calendarA->id, 'month' => '2099-01'], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('Réunion renards', $response->getBody());
        $this->assertStringNotContainsString('Réunion louveteaux', $response->getBody());
        $this->assertStringContainsString('calendar-event-bar--clickable', $response->getBody());
    }

    public function testIndexAddModalDefaultsToTheSelectedPickerCalendar(): void
    {
        $sectionA = $this->createSection('BAL01', 'Renards');
        $sectionB = $this->createSection('LOU01', 'Meute');
        $this->calendarService->ensureSectionCalendars();
        $calendarA = $this->calendarRepository->findBySectionId($sectionA);
        $calendarB = $this->calendarRepository->findBySectionId($sectionB);

        // Selecting calendar B in the picker must default the add-modal to
        // calendar B, even though the chief's own linked section (if any)
        // would otherwise point elsewhere — matches whatever is currently
        // being viewed, unambiguous, no need to fall back to linked members.
        $request = new Request('GET', '/chefs/calendar', ['calendar' => (string) $calendarB->id], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString("const defaultCalendarId = {$calendarB->id};", $response->getBody());
        $this->assertStringNotContainsString("const defaultCalendarId = {$calendarA->id};", $response->getBody());
    }

    public function testIndexDefaultMyEventsExcludesEventsFromUnlinkedSection(): void
    {
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);
        $this->eventRepository->create($calendar->id, 'Réunion section', '2099-01-01', null, null, null, null, null, null);

        $request = new Request('GET', '/chefs/calendar', ['month' => '2099-01'], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('Réunion section', $response->getBody());
    }

    public function testCreateEventSucceeds(): void
    {
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest([
            'calendar_id' => $calendar->id,
            'title' => 'Réunion',
            'start_date' => '2026-03-15',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->createEvent($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertTrue($this->eventRepository->calendarHasEvents($calendar->id));
    }

    public function testCreateEventValidatesCsrf(): void
    {
        $request = $this->createJsonRequest(['calendar_id' => 1, 'title' => 'X', 'start_date' => '2026-01-01', '_csrf_token' => 'bad']);
        $response = $this->controller->createEvent($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCreateEventRejectsInvalidData(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['calendar_id' => 9999, 'title' => 'X', 'start_date' => '2026-01-01', '_csrf_token' => $token]);
        $response = $this->controller->createEvent($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testUpdateEventSucceeds(): void
    {
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);
        $eventId = $this->eventRepository->create($calendar->id, 'Old', '2026-01-01', null, null, null, null, null, null);

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest([
            'event_id' => $eventId,
            'calendar_id' => $calendar->id,
            'title' => 'New',
            'start_date' => '2026-01-02',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->updateEvent($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('New', $this->eventRepository->findById($eventId)->title);
    }

    public function testDeleteEventSucceeds(): void
    {
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);
        $eventId = $this->eventRepository->create($calendar->id, 'Title', '2026-01-01', null, null, null, null, null, null);

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['event_id' => $eventId, '_csrf_token' => $token]);
        $response = $this->controller->deleteEvent($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNull($this->eventRepository->findById($eventId));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/chefs/calendar/event-create', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
