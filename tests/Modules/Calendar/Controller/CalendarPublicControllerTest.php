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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

        $this->calendarRepository = new CalendarRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->eventRepository = new CalendarEventRepository($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, $memberBadgeRepository);
        $this->calendarService = new CalendarService(
            $this->calendarRepository,
            $this->eventRepository,
            $sectionService,
            new CalendarUnitFeedTokenRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)))
        );

        $memberYearRepo = new MemberYearRepository($this->pdo);
        $roleResolver = new RoleResolver($memberYearRepo, $this->encryption, $this->pdo);
        $memberService = new MemberService($memberYearRepo, $this->encryption, $connection);
        $userAccountRepository = new UserAccountRepository($this->pdo, $this->encryption);
        $this->personalFeedService = new PersonalFeedService(
            new CalendarPersonalTokenRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
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
        // The shared French format filters (core/View/TwigFactory.php) used by
        // the templates under test - same rendering as the shipped ones.
        $twig->addFilter(new \Twig\TwigFilter('date_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')));
        $twig->addFilter(new \Twig\TwigFilter('datetime_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')));
        $twig->addFilter(new \Twig\TwigFilter('money', fn($a) => $a === null || $a === '' ? '' : number_format((float) $a, 2, ',', ' ') . ' €'));
        $twig->addFilter(new \Twig\TwigFilter('money_cents', fn($c) => $c === null || $c === '' ? '' : number_format(((int) $c) / 100, 2, ',', ' ') . ' €'));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/calendar');
        $twig->addGlobal('route_breadcrumb', ['label' => 'Calendrier', 'parents' => ['Notre unité']]);
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

    public function testMonthGridRowHeightUsesTheSharedCssVariableNotAHardcodedValue(): void
    {
        // Real bug: month_grid.html.twig hardcoded 1.35rem for the grid row
        // height while components.css grew the bar to 44px on touch devices
        // — the two drifted apart and bars overflowed their track. Both now
        // read the same --calendar-bar-height custom property.
        $calendar = $this->calendarService->addCalendar('Test', 'public');
        $this->eventRepository->create($calendar->id, 'Réunion', '2026-03-11', null, null, null, null, null, null);

        $request = new Request('GET', '/calendar', ['month' => '2026-03'], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertMatchesRegularExpression(
            '/grid-template-rows:\s*auto repeat\(\d+,\s*var\(--calendar-bar-height\)\);/',
            $body
        );
        $this->assertStringNotContainsString('1.35rem', $body);
    }

    public function testMonthGridShowsOverflowBadgeWhenADayExceedsTheRowCap(): void
    {
        // MonthGridBuilder caps visible rows at 3 (DEFAULT_MAX_VISIBLE_ROWS)
        // regardless of any CSS/viewport value — 5 same-day events means 2
        // hidden behind the "+2" overflow badge.
        $calendar = $this->calendarService->addCalendar('Test', 'public');
        for ($i = 1; $i <= 5; $i++) {
            $this->eventRepository->create($calendar->id, "Event {$i}", '2026-03-11', null, null, null, null, null, null);
        }

        $request = new Request('GET', '/calendar', ['month' => '2026-03'], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('calendar-day-overflow', $body);
        $this->assertStringContainsString('+2', $body);
    }

    public function testUpcomingEventListItemsCarryTheSameDataAttributesAsGridBars(): void
    {
        // Real bug: the "Prochains évènements" list items had no data-*
        // attributes and no click handler at all, so clicking one did
        // nothing — the modal only ever opened from a grid bar. Both now
        // emit the identical data-* bag (color included, since a list item
        // has no colored background to read it back off of like a bar does).
        //
        // The data-* bag lives on the trigger, and the trigger is a real
        // <button>: a list item holding block-level content cannot be one
        // itself, and must not fake it with role/tabindex — the keyboard
        // handler that needs is an inline attribute this site's CSP
        // (Core\Http\Response::buildCsp()) refuses to run.
        $calendar = $this->calendarService->addCalendar('Anniversaires', 'public');
        $this->eventRepository->create($calendar->id, 'Grand jeu', '2099-01-01', null, '14:00:00', '16:00:00', 'Local scout', 'Une belle description', null);

        $request = new Request('GET', '/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('calendar-upcoming-event-item', $body);
        $this->assertMatchesRegularExpression(
            '/<button type="button" class="calendar-upcoming-event-trigger stretched-link"/',
            $body
        );
        // No fake button left behind anywhere on this page.
        $this->assertStringNotContainsString('role="button"', $body);
        $this->assertStringContainsString('data-title="Grand jeu"', $body);
        $this->assertStringContainsString('data-calendar-label="Anniversaires"', $body);
        $this->assertStringContainsString('data-start-time="14:00"', $body);
        $this->assertStringContainsString('data-end-time="16:00"', $body);
        $this->assertStringContainsString('data-location="Local scout"', $body);
        $this->assertStringContainsString('data-description="Une belle description"', $body);
        $this->assertMatchesRegularExpression('/data-color="#?[0-9a-fA-F]{3,6}"/', $body);
    }

    public function testUpcomingEventListItemLeavesTimeAndLocationDataAttributesEmptyWhenAbsent(): void
    {
        $calendar = $this->calendarService->addCalendar('Anniversaires', 'public');
        $this->eventRepository->create($calendar->id, 'Réunion silencieuse', '2099-01-01', null, null, null, null, null, null);

        $request = new Request('GET', '/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('data-start-time=""', $body);
        $this->assertStringContainsString('data-location=""', $body);
    }

    public function testEventDetailsModalJsIsSharedByGridBarsAndUpcomingEventItems(): void
    {
        $request = new Request('GET', '/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        // The behaviour lives in a file now (public/assets/js/calendar-public.js,
        // spec tests/js/calendar-public.test.js) — the page's job is to load
        // it and to carry no script of its own.
        $this->assertStringContainsString('/assets/js/calendar-public.js', $body);
        $this->assertStringNotContainsString('function showEventDetails(', $body);
        // Both triggers are real buttons, so Enter and Space activate them
        // with no script: the page must carry no keyboard handler of its own
        // for them (it used to, and an inline attribute could never work
        // under this site's CSP anyway).
        $this->assertStringNotContainsString("e.key === 'Enter'", $body);

        $js = (string) file_get_contents(
            dirname(__DIR__, 4) . '/public/assets/js/calendar-public.js'
        );
        // One fill function, one query selecting both trigger sources — not
        // two copies of the modal-filling logic.
        $this->assertSame(1, substr_count($js, 'showEventDetails = function ('));
        $this->assertStringContainsString(
            "'.calendar-event-bar--clickable, .calendar-upcoming-event-trigger'",
            $js
        );
        $this->assertStringContainsString('data.color', $js);
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

    /**
     * Member page "Link to Calendrier filtered on that section" (§3) —
     * ?section={id} pre-selects that section's own calendar.
     */
    public function testIndexPreselectsTheCalendarForAValidSectionQueryParam(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['BAL', 'Baladins', 10]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['BAL01', $branchId, 'Renards']);
        $sectionId = (int) $this->pdo->lastInsertId();

        $this->calendarService->ensureSectionCalendars();
        $sectionCalendar = $this->calendarRepository->findAll()[0];

        $request = new Request('GET', '/calendar', ['section' => (string) $sectionId], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertMatchesRegularExpression(
            '/href="\/calendar\?calendar=' . $sectionCalendar->id . '[^"]*"\s+class="btn btn-sm btn-primary/',
            $response->getBody()
        );
    }

    public function testIndexBreadcrumbReflectsTheSelectedCalendar(): void
    {
        // The calendar picker changes what this page shows without changing
        // its URL — the breadcrumb's own active segment must reflect the
        // currently selected calendar, not just the static "Calendrier"
        // label.
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['BAL', 'Baladins', 10]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['BAL01', $branchId, 'Renards']);
        $this->calendarService->ensureSectionCalendars();
        $sectionCalendar = $this->calendarRepository->findAll()[0];

        $request = new Request('GET', '/calendar', ['calendar' => (string) $sectionCalendar->id], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Calendrier · Renards\s*</',
            $response->getBody()
        );
    }

    /**
     * An unknown/bogus section id is silently ignored (same treatment as
     * an unknown ?calendar= id) rather than trusted or erroring.
     */
    public function testIndexIgnoresAnUnknownSectionQueryParam(): void
    {
        $request = new Request('GET', '/calendar', ['section' => '999999'], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
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
