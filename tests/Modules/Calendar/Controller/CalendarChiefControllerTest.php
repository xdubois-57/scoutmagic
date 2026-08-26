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
use Core\Member\MemberEmailRepository;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

        $this->calendarRepository = new CalendarRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->eventRepository = new CalendarEventRepository($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $encryption, $memberBadgeRepository);
        $this->calendarService = new CalendarService(
            $this->calendarRepository,
            $this->eventRepository,
            $sectionService,
            new CalendarUnitFeedTokenRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)))
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
            new CalendarPersonalTokenRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
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
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chief@test.be');
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/chefs/calendar');
        $twig->addGlobal('route_breadcrumb', ['label' => 'Calendrier', 'parents' => ['Espace animateurs']]);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $calendarPickerService = new CalendarPickerService($this->calendarService, $personalFeedService);

        $moduleManager = $this->createMock(\Core\Module\ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn([]);

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
            $settingService,
            $moduleManager,
            new SectionStaffAuthorizationService(
                $connection,
                $encryption,
                $sectionService,
                new MemberEmailRepository($this->pdo, $encryption)
            )
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

    /**
     * Gives $email a chief-role Desk function on $sectionId, which is the
     * only thing that makes Core\Member\SectionStaffAuthorizationService
     * treat the account as an animateur of it — and therefore the only
     * thing that lets the writes below through since IT-02.
     */
    private function makeAnimateurOf(int $sectionId, string $email = 'chief@test.be'): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            // Really encrypted, not a placeholder: "Mes évènements" walks
            // MemberService::getLinkedMembers(), which decrypts these.
            $encryption->encrypt('Alice', 'member_years.first_name'),
            $encryption->encrypt('Dupont', 'member_years.last_name'),
            $encryption->blindIndex(strtolower($email), 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('chief', 'Animateur', 'chief')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'chief'")->fetchColumn();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)');
        $stmt->execute([$memberYearId, $functionId, $sectionId]);
    }

    public function testIndexRendersPage(): void
    {
        $request = new Request('GET', '/chefs/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Calendrier', $response->getBody());
    }

    public function testEventFormStaysInsideTheModalBodySoTheScrollableLayoutWorks(): void
    {
        // Historical bug, now structurally impossible: .modal-dialog-scrollable
        // relies on .modal-body/.modal-footer being direct flex children of
        // .modal-content. The shared partials/modal.html.twig embed renders
        // them that way, the whole #event-form lives inside .modal-body, and
        // the footer's submit button reaches it through form="event-form".
        $request = new Request('GET', '/chefs/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);
        $body = $response->getBody();

        $this->assertStringContainsString('<form id="event-form">', $body);
        $this->assertStringContainsString('form="event-form"', $body);
        $this->assertStringContainsString('modal-dialog-scrollable', $body);
        // The embed's title contract, which calendar-chief.js targets.
        $this->assertStringContainsString('id="eventModal-title"', $body);
    }

    public function testIndexPrefillsFormWithConfiguredDefaults(): void
    {
        $request = new Request('GET', '/chefs/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        // The defaults reach public/assets/js/calendar-chief.js through the
        // `calendar-chief-data` JSON island the template renders (they used
        // to be bare `const`s in an inline script).
        $this->assertStringContainsString('"defaultStartTime":"14:00"', $response->getBody());
        $this->assertStringContainsString('"defaultEndTime":"16:00"', $response->getBody());
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

    public function testClickableDayCellsAndEventBarsAreRealButtons(): void
    {
        // Not cosmetic. A clickable cell used to be a div carrying
        // tabindex="0" and role="button", with Enter/Space wired up in the
        // page's own script. A real button is focusable, is announced as a
        // button, and activates on Enter and Space with no script at all —
        // which matters here because this site's CSP
        // (Core\Http\Response::buildCsp() sends script-src 'self'
        // 'nonce-…', with neither 'unsafe-inline' nor 'unsafe-hashes')
        // would refuse to run the inline onKeyDown attribute a div would
        // otherwise need. partials/month_grid.html.twig picks the element
        // from days_clickable / events_clickable.
        $section = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($section);
        $this->eventRepository->create($calendar->id, 'Réunion renards', '2099-01-01', null, null, null, null, null, null);

        $request = new Request('GET', '/chefs/calendar', ['calendar' => (string) $calendar->id, 'month' => '2099-01'], [], [], []);
        $body = $this->controller->index($request, [])->getBody();

        $this->assertMatchesRegularExpression('/<button class="calendar-day-cell calendar-day-cell--clickable/', $body);
        $this->assertMatchesRegularExpression('/<button class="calendar-event-bar calendar-event-bar--clickable/', $body);
        $this->assertStringNotContainsString('role="button"', $body);
        $this->assertStringNotContainsString('tabindex="0"', $body);
    }

    public function testIndexBreadcrumbReflectsTheSelectedCalendar(): void
    {
        // The calendar picker changes what this page shows without changing
        // its URL — the breadcrumb's own active segment must reflect the
        // currently selected calendar, not just the static "Calendrier"
        // label.
        $sectionA = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendarA = $this->calendarRepository->findBySectionId($sectionA);

        $request = new Request('GET', '/chefs/calendar', ['calendar' => (string) $calendarA->id], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Calendrier · Renards\s*</',
            $response->getBody()
        );
    }

    public function testIndexBreadcrumbFallsBackToTheStaticLabelForMesEvenements(): void
    {
        // No calendar selected ("Mes évènements", the sentinel default) —
        // still a real, matched picker option, so it still shows.
        $request = new Request('GET', '/chefs/calendar', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Calendrier · Mes évènements\s*</',
            $response->getBody()
        );
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

        // The closing brace ends the JSON island's last property, so an id
        // that merely starts with another one cannot match it.
        $this->assertStringContainsString("\"defaultCalendarId\":{$calendarB->id}}", $response->getBody());
        $this->assertStringNotContainsString("\"defaultCalendarId\":{$calendarA->id}}", $response->getBody());
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
        $this->makeAnimateurOf($sectionId);
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
        $this->makeAnimateurOf($sectionId);
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
        $this->makeAnimateurOf($sectionId);
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

    // --- IT-02: the page SHOWS the whole unit, the dialog and the three
    // writes are narrowed to the sections this account animates ---

    public function testTheGridAndPickerStillShowASectionTheChiefDoesNotAnimate(): void
    {
        $mine = $this->createSection('BAL01', 'Renards');
        $this->createSection('ECL01', 'Éclaireurs');
        $this->makeAnimateurOf($mine);
        $this->calendarService->ensureSectionCalendars();

        $body = $this->controller->index(new Request('GET', '/chefs/calendar', [], [], [], []), [])->getBody();

        // Narrowing the WRITE must never narrow the READ: the whole point of
        // this page is that an animateur follows what the unit is doing.
        $this->assertStringContainsString('Éclaireurs', $body);
        $this->assertStringContainsString('Renards', $body);
    }

    public function testTheDialogOnlyOffersTheSectionsTheChiefAnimates(): void
    {
        $mine = $this->createSection('BAL01', 'Renards');
        $theirs = $this->createSection('ECL01', 'Éclaireurs');
        $this->makeAnimateurOf($mine);
        $this->calendarService->ensureSectionCalendars();
        $mineCalendar = $this->calendarRepository->findBySectionId($mine);
        $theirsCalendar = $this->calendarRepository->findBySectionId($theirs);

        $body = $this->controller->index(new Request('GET', '/chefs/calendar', [], [], [], []), [])->getBody();

        // Scoped to the DIALOG's select on purpose: the page's own calendar
        // picker above it lists the other section too, and must keep doing
        // so — that is the read side, which this iteration does not touch.
        $dialogSelect = $this->dialogCalendarSelect($body);
        $this->assertStringContainsString('value="' . $mineCalendar->id . '"', $dialogSelect);
        $this->assertStringNotContainsString('value="' . $theirsCalendar->id . '"', $dialogSelect);

        // The same write set reaches the script that gates the dialog. It
        // also carries the section-less "Animateurs" calendar, which is
        // open to every chief — so assert on membership, not on the whole
        // list.
        $editableIds = $this->editableCalendarIdsFrom($body);
        $this->assertContains($mineCalendar->id, $editableIds);
        $this->assertNotContains($theirsCalendar->id, $editableIds);
    }

    public function testAnAnimateurWithNoSectionIsToldWhyNothingIsEditable(): void
    {
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        // chief@test.be is deliberately not linked to any Desk function.

        $body = $this->controller->index(new Request('GET', '/chefs/calendar', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('aucune fonction d\'animateur ne vous est rattachée', $body);

        // Not "nothing is editable": the supplementary "Animateurs"
        // calendar has no section, so it stays open to them. What they lose
        // is every SECTION calendar.
        $sectionCalendar = $this->calendarRepository->findBySectionId($sectionId);
        $this->assertNotContains($sectionCalendar->id, $this->editableCalendarIdsFrom($body));
    }

    public function testCreateEventInAnotherSectionsCalendarIsRefused(): void
    {
        $mine = $this->createSection('BAL01', 'Renards');
        $theirs = $this->createSection('ECL01', 'Éclaireurs');
        $this->makeAnimateurOf($mine);
        $this->calendarService->ensureSectionCalendars();
        $theirsCalendar = $this->calendarRepository->findBySectionId($theirs);

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        // The dialog never offers this calendar — the only way this request
        // exists is forged, and the server refuses it on its own.
        $request = $this->createJsonRequest([
            'calendar_id' => $theirsCalendar->id,
            'title' => 'Intrus',
            'start_date' => '2026-03-15',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->createEvent($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertFalse($this->eventRepository->calendarHasEvents($theirsCalendar->id));
    }

    public function testDeletingAnEventOfAnotherSectionIsRefused(): void
    {
        $mine = $this->createSection('BAL01', 'Renards');
        $theirs = $this->createSection('ECL01', 'Éclaireurs');
        $this->makeAnimateurOf($mine);
        $this->calendarService->ensureSectionCalendars();
        $theirsCalendar = $this->calendarRepository->findBySectionId($theirs);
        $eventId = $this->eventRepository->create($theirsCalendar->id, 'Leur réunion', '2026-01-01', null, null, null, null, null, null);

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $response = $this->controller->deleteEvent($this->createJsonRequest(['event_id' => $eventId, '_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertNotNull($this->eventRepository->findById($eventId));
    }

    public function testAnEventCannotBeMovedIntoTheChiefsOwnSection(): void
    {
        $mine = $this->createSection('BAL01', 'Renards');
        $theirs = $this->createSection('ECL01', 'Éclaireurs');
        $this->makeAnimateurOf($mine);
        $this->calendarService->ensureSectionCalendars();
        $mineCalendar = $this->calendarRepository->findBySectionId($mine);
        $theirsCalendar = $this->calendarRepository->findBySectionId($theirs);
        $eventId = $this->eventRepository->create($theirsCalendar->id, 'Leur réunion', '2026-01-01', null, null, null, null, null, null);

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        // Both ends of the move are checked, so owning the DESTINATION is
        // not enough to drag an event out of a section that isn't ours.
        $response = $this->controller->updateEvent($this->createJsonRequest([
            'event_id' => $eventId,
            'calendar_id' => $mineCalendar->id,
            'title' => 'Détourné',
            'start_date' => '2026-01-02',
            '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame($theirsCalendar->id, $this->eventRepository->findById($eventId)->calendarId);
    }

    public function testAChefDUniteWritesInEverySection(): void
    {
        AuthSession::login(2, 'cu@test.be', 'admin');
        $theirs = $this->createSection('ECL01', 'Éclaireurs');
        $this->calendarService->ensureSectionCalendars();
        $theirsCalendar = $this->calendarRepository->findBySectionId($theirs);

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $response = $this->controller->createEvent($this->createJsonRequest([
            'calendar_id' => $theirsCalendar->id,
            'title' => 'Réunion d\'unité',
            'start_date' => '2026-03-15',
            '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success'], 'admin/superadmin get every section from the service itself');
    }

    /**
     * The add/edit dialog's own calendar <select>, isolated from the page's
     * calendar picker — two different lists with two different rules.
     */
    private function dialogCalendarSelect(string $body): string
    {
        $start = strpos($body, 'id="event-calendar"');
        self::assertNotFalse($start, 'the dialog must render its calendar select');
        $end = strpos($body, '</select>', $start);
        self::assertNotFalse($end);

        return substr($body, $start, $end - $start);
    }

    /**
     * The editableCalendarIds the page hands calendar-chief.js, read back
     * out of its JSON island.
     *
     * @return int[]
     */
    private function editableCalendarIdsFrom(string $body): array
    {
        self::assertMatchesRegularExpression('/"editableCalendarIds":\[[0-9,]*\]/', $body);
        preg_match('/"editableCalendarIds":\[([0-9,]*)\]/', $body, $matches);

        return $matches[1] === '' ? [] : array_map('intval', explode(',', $matches[1]));
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
