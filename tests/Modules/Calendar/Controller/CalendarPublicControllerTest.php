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
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Core\View\MonthGrid\MonthGridBuilder;
use Modules\Calendar\Controller\CalendarPublicController;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarPersonalTokenRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarPickerService;
use Modules\Calendar\Service\CalendarService;
use Modules\Calendar\Service\IcsBuilder;
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
class CalendarPublicControllerTest extends TestCase
{
    private \PDO $pdo;
    private CalendarPublicController $controller;
    private CalendarService $calendarService;
    private CalendarEventRepository $eventRepository;
    private CalendarRepository $calendarRepository;
    private PersonalFeedService $personalFeedService;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->calendarRepository = new CalendarRepository($this->pdo);
        $this->eventRepository = new CalendarEventRepository($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, $memberBadgeRepository);
        $this->calendarService = new CalendarService(
            $this->calendarRepository,
            $this->eventRepository,
            $sectionService,
            new CalendarUnitFeedTokenRepository($this->pdo)
        );

        $memberYearRepo = new MemberYearRepository($this->pdo);
        $roleResolver = new RoleResolver($memberYearRepo, $this->encryption, $this->pdo);
        $memberService = new MemberService($memberYearRepo, $this->encryption, $connection);
        $userAccountRepository = new UserAccountRepository($this->pdo, $this->encryption);
        $this->personalFeedService = new PersonalFeedService(
            new CalendarPersonalTokenRepository($this->pdo),
            $this->calendarService,
            $this->eventRepository,
            $roleResolver,
            $memberService,
            $userAccountRepository,
            $sectionService
        );

        $scoutYearService = new ScoutYearService($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/calendar/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'calendar');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addGlobal('_editable_content_service', null);
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new TwigFunction('param', fn(string $k) => 'https://example.test'));
        $twig->addFunction(new TwigFunction('editable', fn() => '', ['is_safe' => ['html']]));

        $calendarPickerService = new CalendarPickerService($this->calendarService, $this->personalFeedService);

        $this->controller = new CalendarPublicController(
            $twig,
            $this->calendarService,
            $calendarPickerService,
            new MonthGridBuilder(),
            $this->personalFeedService,
            new IcsBuilder(),
            $scoutYearResolver,
            $journalService
        );
    }

    public function testIndexRendersPageWithMonthGrid(): void
    {
        $request = new Request('GET', '/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Calendrier', $response->getBody());
    }

    public function testIndexShowsUpcomingEventInSelectedSection(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['BAL', 'Baladins', 10]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['BAL01', $branchId, 'Renards']);

        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findAll()[0];
        $this->eventRepository->create($calendar->id, 'Grand jeu', '2099-01-01', null, null, null, null, null, null);

        $request = new Request('GET', '/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('Grand jeu', $response->getBody());
    }

    public function testIndexShowsCalendarLabelInUpcomingEventsList(): void
    {
        $calendar = $this->calendarService->addCalendar('Anniversaires', 'public');
        $this->eventRepository->create($calendar->id, 'Grand jeu', '2099-01-01', null, '14:00:00', null, 'Local scout', null, null);

        $request = new Request('GET', '/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Grand jeu', $body);
        $this->assertStringContainsString('Anniversaires', $body);
        $this->assertStringContainsString('14:00', $body);
        $this->assertStringContainsString('Local scout', $body);
    }

    public function testIndexEventBarsAreClickableWithDetailDataAttributes(): void
    {
        $calendar = $this->calendarService->addCalendar('Anniversaires', 'public');
        $this->eventRepository->create($calendar->id, 'Réunion mensuelle', '2026-03-11', null, null, null, null, null, null);

        $request = new Request('GET', '/calendar', ['month' => '2026-03'], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('calendar-event-bar--clickable', $body);
        $this->assertStringContainsString('data-calendar-label="Anniversaires"', $body);
        $this->assertStringContainsString('eventDetailsModal', $body);
    }

    public function testIndexRendersEventBarInsideTheMonthGrid(): void
    {
        $calendar = $this->calendarService->addCalendar('Test', 'public');
        $this->eventRepository->create($calendar->id, 'Réunion mensuelle', '2026-03-11', null, null, null, null, null, null);

        $request = new Request('GET', '/calendar', ['month' => '2026-03'], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('calendar-event-bar', $body);
        $this->assertStringContainsString('Réunion mensuelle', $body);
        $this->assertStringContainsString('calendar-day-number', $body);
    }

    public function testIndexRendersMultiDayEventAsASpanningBar(): void
    {
        $calendar = $this->calendarService->addCalendar('Test', 'public');
        $this->eventRepository->create($calendar->id, 'Camp', '2026-03-11', '2026-03-13', null, null, null, null, null);

        $request = new Request('GET', '/calendar', ['month' => '2026-03'], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertMatchesRegularExpression('/grid-column:\s*3\s*\/\s*span\s*3/', $response->getBody());
    }

    public function testIndexDoesNotShowChiefOnlyCalendarToPublicVisitor(): void
    {
        $calendar = $this->calendarService->addCalendar('Réservé', 'chief');
        $this->eventRepository->create($calendar->id, 'Réunion chefs', '2099-01-01', null, null, null, null, null, null);

        $request = new Request('GET', '/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('Réunion chefs', $response->getBody());
    }

    public function testIndexShowsPersonalLinkOnlyWhenAuthenticated(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'member@test.be', 'identified');

        $request = new Request('GET', '/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringContainsString('personal-ics-link', $response->getBody());
        AuthSession::logout();
    }

    public function testIndexHidesPersonalLinkWhenNotAuthenticated(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::logout();

        $request = new Request('GET', '/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertStringNotContainsString('personal-ics-link', $response->getBody());
        $this->assertStringContainsString('Connectez-vous', $response->getBody());
    }

    public function testCalendarFeedReturnsIcsContentType(): void
    {
        $calendar = $this->calendarService->addCalendar('Test', 'public');
        $this->eventRepository->create($calendar->id, 'Réunion', '2026-03-15', null, null, null, null, null, null);

        $request = new Request('GET', "/calendar/feed/{$calendar->icsToken}.ics", [], [], [], []);
        $response = $this->controller->calendarFeed($request, ['token' => $calendar->icsToken]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/calendar; charset=utf-8', $response->getHeaders()['Content-Type']);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $response->getBody());
        $this->assertStringContainsString('Réunion', $response->getBody());
    }

    public function testCalendarFeedReturns404ForUnknownToken(): void
    {
        $request = new Request('GET', '/calendar/feed/nope.ics', [], [], [], []);
        $response = $this->controller->calendarFeed($request, ['token' => 'nope']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCalendarFeedIsReachableRegardlessOfVisibility(): void
    {
        // Visibility restricts display on the public page only, not the ICS
        // link itself (see module spec §2).
        $calendar = $this->calendarService->addCalendar('Réservé', 'admin');
        $this->eventRepository->create($calendar->id, 'Secret', '2026-03-15', null, null, null, null, null, null);

        $request = new Request('GET', "/calendar/feed/{$calendar->icsToken}.ics", [], [], [], []);
        $response = $this->controller->calendarFeed($request, ['token' => $calendar->icsToken]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Secret', $response->getBody());
    }

    public function testUnitFeedAggregatesAllCalendars(): void
    {
        $calendarA = $this->calendarService->addCalendar('A', 'public');
        $calendarB = $this->calendarService->addCalendar('B', 'admin');
        $this->eventRepository->create($calendarA->id, 'Event A', '2026-01-01', null, null, null, null, null, null);
        $this->eventRepository->create($calendarB->id, 'Event B', '2026-01-01', null, null, null, null, null, null);

        $token = $this->calendarService->getOrCreateUnitFeedToken();
        $request = new Request('GET', "/calendar/feed/unit/{$token}.ics", [], [], [], []);
        $response = $this->controller->unitFeed($request, ['token' => $token]);

        $this->assertStringContainsString('Event A', $response->getBody());
        $this->assertStringContainsString('Event B', $response->getBody());
    }

    public function testUnitFeedReturns404ForInvalidToken(): void
    {
        $request = new Request('GET', '/calendar/feed/unit/bad.ics', [], [], [], []);
        $response = $this->controller->unitFeed($request, ['token' => 'bad']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPersonalFeedReturnsEmptyValidCalendarForUnknownToken(): void
    {
        $request = new Request('GET', '/calendar/feed/personal/nope.ics', [], [], [], []);
        $response = $this->controller->personalFeed($request, ['token' => 'nope']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('BEGIN:VCALENDAR', $response->getBody());
        $this->assertStringContainsString('END:VCALENDAR', $response->getBody());
        $this->assertStringNotContainsString('BEGIN:VEVENT', $response->getBody());
    }

    public function testRegeneratePersonalTokenRequiresAuthentication(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::logout();
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest(['_csrf_token' => $token]);
        $response = $this->controller->regeneratePersonalToken($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRegeneratePersonalTokenValidatesCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'member@test.be', 'identified');

        $request = $this->createJsonRequest(['_csrf_token' => 'bad']);
        $response = $this->controller->regeneratePersonalToken($request, []);

        $this->assertSame(403, $response->getStatusCode());
        AuthSession::logout();
    }

    public function testRegeneratePersonalTokenSucceedsForAuthenticatedUser(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $csrfToken = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $csrfToken;
        AuthSession::login(1, 'member@test.be', 'identified');

        $request = $this->createJsonRequest(['_csrf_token' => $csrfToken]);
        $response = $this->controller->regeneratePersonalToken($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNotEmpty($decoded['token']);
        AuthSession::logout();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/calendar/personal-token/regenerate', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
