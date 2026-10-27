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
use Tests\RequestWithInput;
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
use Modules\SosStaff\Service\OnCallService;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosSettingsService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;
use Tests\TestTwig;
use Twig\Environment;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
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
    /**
     * What the stubbed provider configuration answers: a provider by
     * default (the page's ordinary state), null for « nothing configured ».
     */
    private ?\Modules\SosStaff\Api\PhoneProviderInterface $activeProvider = null;
    private \Modules\SosStaff\Api\ForwardingState|\Modules\SosStaff\Api\ProviderException $forwarding;
    /**
     * What the stubbed RedirectService was asked to apply (issue #1026):
     * the member the calls go to, the one they leave, and the scout year.
     *
     * @var array<int, array{0: ?int, 1: ?int, 2: int}>
     */
    private array $applyCalls = [];
    /** A refusal for the stubbed apply() to throw, or null for a change that goes through. */
    private ?\Modules\SosStaff\Service\SosException $applyFailure = null;
    /** False: apply() answers without having changed anything at the provider. */
    private bool $applyChangesTheProvider = true;
    /** True: neither a duty nor the default number gives a destination. */
    private bool $noDestination = false;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, $memberBadgeRepository)
);
        $memberYearRepository = new MemberYearRepository($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('transition_hour', '10:00', 'text', 'Heure', 'desc', 'sos_staff');
        $settingService->register('email_notifications_enabled', '1', 'boolean', 'Emails', 'desc', 'sos_staff');

        $this->settingsService = new SosSettingsService(
            new ExcludedSectionRepository($this->pdo),
            new SosSettingsRepository($this->pdo),
            $sectionService,
            $memberYearRepository,
            new UnitStaffSectionService(new \Core\Member\Repository\UnitStaffSectionRepository($this->pdo)),
            $settingService
        );

        $this->onCallRepository = new OnCallRepository($this->pdo);
        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $schedulerService = new SchedulerService($this->schedulerRepository);
        $onCallService = new OnCallService($this->onCallRepository, $schedulerService, $this->settingsService);

        // A stubbed configuration with a provider whose state each test
        // can choose (issue #750): the real one would need OVH
        // credentials, and the live state would be a network call.
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(false, null);
        $provider = $this->createStub(\Modules\SosStaff\Api\PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnCallback(function () {
            if ($this->forwarding instanceof \Modules\SosStaff\Api\ProviderException) {
                throw $this->forwarding;
            }

            return $this->forwarding;
        });
        $this->activeProvider = $provider;
        $providerConfigService = $this->createStub(ProviderConfigService::class);
        $providerConfigService->method('getActiveProvider')->willReturnCallback(fn() => $this->activeProvider);
        $providerConfigService->method('getSosNumber')->willReturn('+32 2 000 00 00');
        $journalService = new JournalService(new JournalRepository($this->pdo));

        // The two RedirectService calls the page makes (issue #1026): which
        // number a redirection means — the member's mobile, else the default
        // number, as the real service resolves it — and the change itself,
        // which here moves the stubbed provider the way a real one would.
        $this->redirectService = $this->createStub(RedirectService::class);
        $this->redirectService->method('resolveNumber')->willReturnCallback(
            fn(?int $memberId, int $scoutYearId): ?string => $this->stubbedNumber($memberId, $scoutYearId)
        );
        $this->redirectService->method('apply')->willReturnCallback(
            function (?int $newMemberId, ?int $previousMemberId, int $scoutYearId): void {
                $this->applyCalls[] = [$newMemberId, $previousMemberId, $scoutYearId];
                if ($this->applyFailure !== null) {
                    throw $this->applyFailure;
                }
                if ($this->applyChangesTheProvider) {
                    $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(
                        true,
                        $this->stubbedNumber($newMemberId, $scoutYearId)
                    );
                }
            }
        );

        $scoutYearService = new ScoutYearService($this->pdo);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepository);

        $moduleViews = dirname(__DIR__, 4) . '/modules/sos_staff/views';
        $this->twig = TestTwig::create(['sos_staff' => $moduleViews]);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_email', 'admin@test.be');
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new SosAdminController(
            $this->twig,
            $providerConfigService,
            $this->settingsService,
            $onCallService,
            $this->redirectService,
            $sectionService,
            $schedulerService,
            $scoutYearResolver,
            $journalService,
            null
        );

        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)");
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
        $request = new RequestWithInput('POST', $path, [], [], [], [], json_encode($data));
        return $request;
    }

    private function stubbedNumber(?int $memberId, int $scoutYearId): ?string
    {
        if ($this->noDestination) {
            return null;
        }
        if ($memberId !== null) {
            foreach ($this->settingsService->getStaffOptions($scoutYearId) as $option) {
                if ((int) $option['member_id'] === $memberId) {
                    return $option['mobile'];
                }
            }
        }

        return $this->settingsService->getDefaultNumber($scoutYearId);
    }

    /**
     * Puts $memberId on duty for yesterday AND today, so that the expected
     * destination is theirs whichever side of the changeover hour the test
     * happens to run on.
     */
    private function putOnDuty(int $memberId): void
    {
        foreach (['yesterday', 'today'] as $day) {
            $date = (new \DateTimeImmutable($day))->format('Y-m-d');
            $this->onCallRepository->replaceRange($date, $date, [
                new OnCallAssignment($memberId, $date, OnCallAssignment::STATE_ONCALL),
            ]);
        }
    }

    /**
     * The three sub-pages (issue #1026), each rendered through its own
     * action — what the router would do for its own path.
     *
     * @param array<string, string> $query
     */
    private function dashboard(array $query = []): string
    {
        return $this->controller->index(new Request('GET', '/admin/sos', $query, [], [], []), [])->getBody();
    }

    /**
     * @param array<string, string> $query
     */
    private function planning(array $query = []): string
    {
        return $this->controller->planning(new Request('GET', '/admin/sos/planning', $query, [], [], []), [])->getBody();
    }

    private function settingsPage(): string
    {
        return $this->controller->settings(new Request('GET', '/admin/sos/reglages', [], [], [], []), [])->getBody();
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
        $staffduId = (new UnitStaffSectionService(new \Core\Member\Repository\UnitStaffSectionRepository($this->pdo)))->ensureSection();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, mobile_encrypted)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $encryption->encrypt('Jean', 'member_years.first_name'), $encryption->encrypt('Dupont', 'member_years.last_name'),
            $encryption->encrypt($totem, 'member_years.totem'), $encryption->encrypt($mobile, 'member_years.mobile'),
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
        $this->assertStringContainsString("Gérer le téléphone d&#039;urgence", $response->getBody());
    }

    public function testWithoutAProviderEachSubPageIsOneWarningAndNothingElse(): void
    {
        $this->activeProvider = null;

        foreach (['dashboard' => $this->dashboard(), 'planning' => $this->planning(), 'settings' => $this->settingsPage()] as $page => $body) {
            $this->assertSame(1, substr_count($body, 'class="alert alert-warning"'), "{$page}: one warning, not two");
            $this->assertStringContainsString("La téléphonie n'est pas encore configurée", $body);
            $this->assertStringNotContainsString('Aucun fournisseur de téléphonie actif configuré', $body);
            foreach ([
                'sos-grid', 'sos-day-list', 'planned-transitions-list', 'sos-settings', 'sos-admin.js',
                'sos-status-banner', 'sos-page-picker',
            ] as $absent) {
                $this->assertStringNotContainsString($absent, $body, "{$page}: {$absent} has no use without a provider");
            }
        }
    }

    public function testThePageLinksToTheConfigurationOnlyForWhoeverCanOpenIt(): void
    {
        $this->activeProvider = null;

        \Core\Security\AuthSession::login(1, 'super@test.be', 'superadmin');
        foreach ([$this->dashboard(), $this->planning(), $this->settingsPage()] as $body) {
            $this->assertStringContainsString('href="/config/sos"', $body);
        }

        \Core\Security\AuthSession::login(1, 'admin@test.be', 'admin');
        foreach ([$this->dashboard(), $this->planning(), $this->settingsPage()] as $body) {
            $this->assertStringNotContainsString('href="/config/sos"', $body);
        }
    }

    public function testTheBannerSaysWhereTheCallsReallyGoToAKnownMember(): void
    {
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(true, $this->firstStaffMobile());

        $body = $this->controller->index(new Request('GET', '/admin/sos', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Redirection active chez le fournisseur', $body);
        $this->assertMatchesRegularExpression(
            '/redirigés vers\s*<strong>[^<]+ — ' . preg_quote($this->firstStaffMobile(), '/') . '<\/strong>/',
            $body
        );
    }

    public function testAnUnknownNumberIsShownWithoutAnInventedName(): void
    {
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(true, '+32 499 99 99 99');

        $body = $this->controller->index(new Request('GET', '/admin/sos', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('<strong>+32 499 99 99 99</strong>', $body);
    }

    public function testAnInactiveRedirectionAndAReadErrorAreTwoDistinctStates(): void
    {
        $inactive = $this->controller->index(new Request('GET', '/admin/sos', [], [], [], []), [])->getBody();
        $this->assertStringContainsString('Redirection inactive chez le fournisseur', $inactive);

        $this->forwarding = new \Modules\SosStaff\Api\ProviderException('Le fournisseur ne répond pas.');
        $error = $this->controller->index(new Request('GET', '/admin/sos', [], [], [], []), [])->getBody();
        $this->assertStringContainsString('État de la redirection illisible chez le fournisseur', $error);
        $this->assertStringContainsString('Le fournisseur ne répond pas.', $error);
        $this->assertStringNotContainsString('Redirection inactive', $error);
    }

    public function testAShortSentenceExplainsTheDefaultNumberBeforeThePlanning(): void
    {
        $body = $this->planning();

        $this->assertStringContainsString('id="sos-planning-explanation"', $body);
        $this->assertStringContainsString('sauf les jours où quelqu\'un', html_entity_decode($body, ENT_QUOTES));
        // The default number lives in Réglages now: the sentence says where.
        $this->assertStringContainsString('<a href="/admin/sos/reglages">Réglages</a>', $body);
    }

    public function testTheSettingsAreInPlainSightAndTheNotificationSwitchHasItsLabel(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $body = $this->settingsPage();

        $this->assertStringNotContainsString('accordion', $body);
        $this->assertStringContainsString('id="sos-settings"', $body);
        // The three controls, each in its place.
        $this->assertStringContainsString('id="default-number-member"', $body);
        $this->assertStringContainsString('id="default-number-save"', $body);
        $this->assertStringContainsString('id="transition-hour"', $body);
        // The switch and its label share one .form-check — the shape that
        // drew the switch over the start of the sentence put the label
        // outside it, with nothing beside the input.
        $this->assertMatchesRegularExpression(
            '/<div class="form-check form-switch">\s*<input[^>]+id="email-notifications-toggle"[^>]*>\s*'
                . '<label class="form-check-label[^"]*" for="email-notifications-toggle">Notifications de garde<\/label>/s',
            $body
        );
        $this->assertStringContainsString('quand sa garde commence', $body);
    }

    public function testThePhoneTableHasTwoColumnsAndNoSectionColumn(): void
    {
        $body = $this->planning();

        $this->assertMatchesRegularExpression(
            '/<table[^>]+id="sos-day-list"[^>]*>\s*<thead>\s*<tr>\s*<th scope="col">Date<\/th>\s*'
                . '<th scope="col">Personne de garde<\/th>\s*<\/tr>/s',
            $body
        );
    }

    public function testTheDaySheetHasAPlaceForTheDaysEventsAndThreeStatesPerMember(): void
    {
        $body = $this->planning();

        $this->assertStringContainsString('Événements ce jour-là', $body);
        $this->assertStringContainsString('id="sos-day-sheet-activity-list"', $body);
    }

    private function firstStaffMobile(): string
    {
        $options = $this->settingsService->getStaffOptions($this->scoutYearId);
        if ($options === []) {
            $this->createStaffduMember('Akela', '+32470000001');
            $options = $this->settingsService->getStaffOptions($this->scoutYearId);
        }

        return $options[0]['mobile'];
    }

    public function testThePlanningRendersSectionActivityFromTheCalendarReadApi(): void
    {
        // A regular animés section, so it survives the excluded-sections
        // filter (Staff d'U never would).
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['BAL', 'Baladins', 10]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['BAL', $branchId, 'Baladins']);
        $sectionId = (int) $this->pdo->lastInsertId();

        // The calendar module's read Api (§7.5), stubbed at the contract:
        // the controller must render whatever activity the Api hands back,
        // without ever touching the calendar module's own classes.
        $firstOfMonth = (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $lookup = new class ($sectionId, $firstOfMonth) implements \Modules\Calendar\Api\CalendarEventLookupInterface {
            public function __construct(private readonly int $sectionId, private readonly string $day)
            {
            }

            public function findEventsInWindow(
                \DateTimeInterface $windowStart,
                \DateTimeInterface $windowEnd,
                ?int $sectionId,
                \Core\Security\Role $viewerRole
            ): array {
                return [];
            }

            public function findEventById(int $eventId, \Core\Security\Role $viewerRole): ?\Modules\Calendar\Api\EventSummary
            {
                return null;
            }

            public function sectionActivityForMonth(int $year, int $month, \Core\Security\Role $viewerRole): array
            {
                return [$this->sectionId => new \Modules\Calendar\Api\SectionMonthActivity(
                    sectionId: $this->sectionId,
                    color: '#123456',
                    eventTitlesByDay: [$this->day => ['Fête des Baladins']]
                )];
            }

            public function searchUpcomingEvents(string $query, \Core\Security\Role $viewerRole, int $limit = 20): array
            {
                return [];
            }
        };
        $property = new \ReflectionProperty(SosAdminController::class, 'calendarEvents');
        $property->setValue($this->controller, $lookup);

        $body = $this->planning();

        $this->assertStringContainsString('Baladins', $body);
        $this->assertStringContainsString('Fête des Baladins', $body);
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

    /**
     * getDefaultNumber() resolves whatever is stored here into the live
     * redirect target of the PUBLIC SOS line, so the member id has to be one
     * the picker actually offers — a Staff d'U member with a known mobile.
     * Unvalidated, an admin could point the unit's emergency number at any
     * member's personal phone.
     */
    public function testUpdateDefaultNumberRejectsAMemberOutsideTheStaffRoster(): void
    {
        $rosterMemberId = $this->createStaffduMember('Akela', '+32470123456');
        $token = $this->csrfToken();
        $this->controller->updateDefaultNumber(
            $this->jsonRequest(['member_id' => $rosterMemberId, '_csrf_token' => $token]),
            []
        );

        // A member with no Staff d'U function at all.
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_OUTSIDER')");
        $outsiderId = (int) $this->pdo->lastInsertId();

        $token = $this->csrfToken();
        $response = $this->controller->updateDefaultNumber(
            $this->jsonRequest(['member_id' => $outsiderId, '_csrf_token' => $token]),
            []
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getBody(), true)['success']);
        $this->assertSame(
            '+32470123456',
            $this->settingsService->getDefaultNumber($this->scoutYearId),
            'the live SOS redirect must be unchanged'
        );
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

    /**
     * `member_id` is a foreign key, so an id that is merely well formed
     * still reaches MySQL as an integrity violation and — before
     * SECURITY.md § 35 — an uncaught PDOException and a 500. The roster
     * this page is displaying is already in hand, and checking against
     * it is both stricter and cheaper than checking the id is a number.
     */
    public function testSaveOnCallIgnoresAMemberWhoIsNotOnTheRoster(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');

        $token = $this->csrfToken();
        $response = $this->controller->saveOnCall(
            $this->jsonRequest([
                'year' => 2026,
                'month' => 7,
                'cells' => [
                    ['member_id' => 999999, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
                ],
                '_csrf_token' => $token,
            ]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success'], 'the save itself succeeds; the unknown cell is simply not in it');
        $this->assertSame([], $this->onCallRepository->findForDate('2026-07-05'));
    }

    /**
     * A number wider than `member_id`'s INT UNSIGNED column, which is
     * what a long digit string in the grid produced.
     */
    public function testSaveOnCallIgnoresAMemberIdNoColumnCouldHold(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');

        $token = $this->csrfToken();
        $this->controller->saveOnCall(
            $this->jsonRequest([
                'year' => 2026,
                'month' => 7,
                'cells' => [
                    ['member_id' => '99999999999999999999', 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
                ],
                '_csrf_token' => $token,
            ]),
            []
        );

        $this->assertSame([], $this->onCallRepository->findForDate('2026-07-05'));
    }

    /**
     * `assignment_date` is a DATE column and took whatever the grid sent.
     * A scan put a path-traversal payload in it; MySQL refused it as an
     * uncaught exception.
     */
    public function testSaveOnCallIgnoresACellWhoseDateIsNotADate(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');

        $token = $this->csrfToken();
        $this->controller->saveOnCall(
            $this->jsonRequest([
                'year' => 2026,
                'month' => 7,
                'cells' => [
                    ['member_id' => $memberId, 'date' => '../../../../etc/passwd', 'state' => OnCallAssignment::STATE_ONCALL],
                    // A NUL *inside* the value, which is what made
                    // createFromFormat raise rather than return false. A
                    // trailing one is a different case: trim() removes it,
                    // and what is left is a date (Core\Service\DateInput).
                    ['member_id' => $memberId, 'date' => "2026-07\0-05", 'state' => OnCallAssignment::STATE_ONCALL],
                ],
                '_csrf_token' => $token,
            ]),
            []
        );

        $this->assertSame([], $this->onCallRepository->findForDate('2026-07-05'));
    }

    /**
     * saveMonth() replaces the whole month in one transaction: it DELETEs
     * the range, then inserts these rows. A row dated outside that range
     * would be written once and never cleared by any later save.
     */
    public function testSaveOnCallIgnoresACellOutsideTheMonthBeingSaved(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');

        $token = $this->csrfToken();
        $this->controller->saveOnCall(
            $this->jsonRequest([
                'year' => 2026,
                'month' => 7,
                'cells' => [
                    ['member_id' => $memberId, 'date' => '2026-08-05', 'state' => OnCallAssignment::STATE_ONCALL],
                    ['member_id' => $memberId, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
                ],
                '_csrf_token' => $token,
            ]),
            []
        );

        $this->assertCount(1, $this->onCallRepository->findForDate('2026-07-05'));
        $this->assertSame([], $this->onCallRepository->findForDate('2026-08-05'));
    }

    /**
     * OnCallService::saveMonth() composes the month with sprintf and used
     * to hand the result to `new DateTimeImmutable()`, which throws on
     * what a year of 99999999999 formats into.
     */
    public function testSaveOnCallRejectsAYearNoCalendarHas(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->saveOnCall(
            $this->jsonRequest(['year' => 99999999999, 'month' => 7, 'cells' => [], '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
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

        $body = $this->dashboard();

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

    /**
     * The list is about what is still to come: a handover scheduled for the
     * 1st of next month is the NEXT one on the 31st, and the dashboard — which
     * has no month to browse — must show it, highlighted.
     */
    public function testTheDashboardListsTransitionsOfLaterMonthsToo(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');
        $schedulerService = new SchedulerService($this->schedulerRepository);
        $nextMonth = new \DateTimeImmutable('first day of next month 10:00');
        $schedulerService->schedule(
            OnCallService::MODULE_ID,
            OnCallService::TASK_KEY,
            $nextMonth,
            ['member_id' => $memberId],
            $nextMonth->format('Y-m-d')
        );

        $body = $this->dashboard();

        $this->assertStringContainsString($nextMonth->format('d/m/Y H:i'), $body);
        $this->assertSame(1, substr_count($body, 'Prochain changement'));
        $this->assertStringNotContainsString('Aucune redirection planifiée', $body);
    }

    public function testTheNextTransitionRowKeepsItsHighlightRuleInTheStylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 4) . '/public/assets/css/app.css');

        $this->assertMatchesRegularExpression('/\.sos-next-transition\s*\{[^}]*background-color/', $css);
        $this->assertMatchesRegularExpression('/\.sos-next-transition\s*\{[^}]*border-left/', $css);
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

        $page1 = $this->dashboard();
        $this->assertStringContainsString('pagination', $page1);

        $page2 = $this->dashboard(['transitions_page' => '2']);
        $this->assertStringContainsString('pagination', $page2);
        $this->assertNotSame($page1, $page2);
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

    // ------------------------------------------------------------------
    // The phone layout (spec §3): a day list naming who really takes the
    // calls, one edit sheet for the whole month, and the « Ma
    // disponibilité » tab.
    // ------------------------------------------------------------------

    public function testThePlanningNamesThePersonWhoActuallyReceivesTheCallsOnADay(): void
    {
        $akela = $this->createStaffduMember('Akela', '+32470000001');
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->onCallRepository->replaceRange($today, $today, [
            new OnCallAssignment($akela, $today, OnCallAssignment::STATE_ONCALL),
        ]);

        $body = $this->planning();

        $this->assertStringContainsString('sos-day-list', $body);
        $this->assertStringContainsString('data-date="' . $today . '"', $body);
        $this->assertStringContainsString('Akela', $body);
    }

    /**
     * Module spec §2.6: several people can be marked on call the same day
     * and the ROSTER ORDER decides who receives the calls. The page must
     * name that person and flag the day, never name whoever was clicked
     * last — the resolution lives in OnCallService, not in the template.
     */
    public function testThePlanningFlagsADayWithSeveralPeopleMarkedOnCall(): void
    {
        $first = $this->createStaffduMember('Akela', '+32470000001');
        $second = $this->createStaffduMember('Baloo', '+32470000002');
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->onCallRepository->replaceRange($today, $today, [
            new OnCallAssignment($second, $today, OnCallAssignment::STATE_ONCALL),
            new OnCallAssignment($first, $today, OnCallAssignment::STATE_ONCALL),
        ]);

        $body = $this->planning();

        // The flag is drawn on every row and hidden with `d-none` — a
        // visible one is the one without it.
        $this->assertStringContainsString('class="bi bi-people"', $body);
        // The roster sorts Akela before Baloo, and the FIRST one is who
        // the calls actually reach.
        $this->assertMatchesRegularExpression(
            '/data-date="' . preg_quote($today, '/') . '"[^>]*>.*?data-day-target>\\s*Akela/s',
            $body
        );
    }

    public function testThePlanningDoesNotFlagADayWithASingleOnCall(): void
    {
        $akela = $this->createStaffduMember('Akela', '+32470000001');
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->onCallRepository->replaceRange($today, $today, [
            new OnCallAssignment($akela, $today, OnCallAssignment::STATE_ONCALL),
        ]);

        $body = $this->planning();

        $this->assertStringNotContainsString('class="bi bi-people"', $body);
        $this->assertStringContainsString('class="bi bi-people d-none"', $body);
    }

    public function testThePlanningShowsTheDefaultNumberHolderOnADayWithNoOnCall(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');

        $body = $this->planning();

        $this->assertStringContainsString('Par défaut — Akela', $body);
    }

    /**
     * The running month starts at today: past days are not editable in
     * practice and pushing the useful part of the list off the first two
     * screens is the defect this layout exists to fix. Only the phone
     * layout is trimmed — the desktop grid still renders the whole month.
     */
    public function testThePlanningStartsTheCurrentMonthAtTodayOnThePhoneLayoutOnly(): void
    {
        $now = new \DateTimeImmutable();
        if ((int) $now->format('j') < 3) {
            $this->markTestSkipped('No past day in the current month to assert on.');
        }
        // A roster member, so the desktop grid actually has cells to
        // compare the trimmed phone list against.
        $this->createStaffduMember('Akela', '+32470000001');
        $firstOfMonth = $now->format('Y-m-01');

        $body = $this->planning();

        $this->assertStringNotContainsString('id="sos-day-' . $firstOfMonth . '"', $body);
        // The desktop grid is untouched: it still carries a cell for the
        // first of the month.
        $this->assertStringContainsString('data-date="' . $firstOfMonth . '"', $body);
    }

    public function testThePlanningShowsAnotherMonthWhole(): void
    {
        $other = (new \DateTimeImmutable())->modify('first day of next month');

        $body = $this->planning(['month' => $other->format('Y-m')]);

        $this->assertStringContainsString('id="sos-day-' . $other->format('Y-m-d') . '"', $body);
    }

    /**
     * Issue #1024: on a phone the day sheet is the whole screen, not
     * Bootstrap's 30vh strip. The rule is a stylesheet's and the browser
     * scenario measures it; these two keep it from being unwired on every
     * push: the sheet asks for the class, the class exists, and the day
     * and the way back sit in the header, outside the scrolling body.
     */
    public function testTheDaySheetAsksForTheFullScreenHeightAndKeepsTheDayAndTheWayBackInView(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $body = $this->planning();

        $this->assertMatchesRegularExpression(
            '/<div class="offcanvas offcanvas-bottom sos-day-sheet d-md-none"[^>]*id="sos-day-sheet"/',
            $body
        );
        $this->assertSame(
            1,
            preg_match(
                '/<div class="offcanvas-header[^"]*">(.*?)<\/div>\s*<div class="offcanvas-body">/s',
                $body,
                $header
            ),
            'the header closes before the scrolling body opens'
        );
        $this->assertStringContainsString('id="sos-day-sheet-title"', $header[1]);
        $this->assertMatchesRegularExpression('/<button[^>]*data-bs-dismiss="offcanvas"[^>]*>.*?Fermer\s*<\/button>/s', $header[1]);
    }

    public function testTheFullScreenRuleOfTheDaySheetIsInTheStylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 4) . '/public/assets/css/app.css');

        $this->assertMatchesRegularExpression('/\.sos-day-sheet\s*\{[^}]*--bs-offcanvas-height:\s*100%/', $css);
        $this->assertMatchesRegularExpression(
            '/@supports \(height: 100dvh\)\s*\{\s*\.sos-day-sheet\s*\{[^}]*--bs-offcanvas-height:\s*100dvh/',
            $css
        );
    }

    public function testThePlanningRendersOneEditSheetForTheWholeMonth(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');

        $body = $this->planning();

        $this->assertSame(1, substr_count($body, 'id="sos-day-sheet"'));
        $this->assertSame(1, substr_count($body, 'data-sheet-member-id="'));
        // Three states, named in words rather than ✓ / ✗ / —.
        $this->assertStringContainsString('>Garde</button>', $body);
        $this->assertStringContainsString('>Indispo</button>', $body);
        $this->assertStringContainsString('>Rien</button>', $body);
    }

    /**
     * The day button is named by what it shows — who receives the calls —
     * never by an aria-label that would hide it from a screen reader; the
     * row header already says which day it is.
     */
    public function testADayButtonIsNamedByItsRecipientNotByADateLabel(): void
    {
        $body = $this->planning();

        $this->assertSame(1, preg_match('/<tr class="sos-day-row[^"]*".*?<button[^>]*>(.*?)<\/button>/s', $body, $m));
        $this->assertStringNotContainsString('Modifier les gardes du', $body);
        $this->assertStringContainsString('data-day-target', $m[1]);
        $this->assertStringContainsString('<span class="visually-hidden">— modifier les gardes</span>', $m[1]);
    }

    /**
     * « Ma disponibilité » is gone (issue #1026): it was never a rule, the
     * planning edits everybody, and it does not become a fourth sub-page.
     * A bookmark that still says `?tab=me` simply opens the planning.
     */
    public function testThereIsNoMyAvailabilityViewAnyMore(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');

        foreach ([$this->planning(), $this->planning(['tab' => 'me'])] as $body) {
            $this->assertStringNotContainsString('Ma disponibilité', $body);
            $this->assertStringNotContainsString('id="sos-my-availability"', $body);
            $this->assertStringNotContainsString('id="sos-mobile-tabs"', $body);
        }
    }

    /**
     * One legend, since the dots now mean one thing: who receives the
     * calls — including the default number, the most common day.
     */
    public function testThePlanningLegendExplainsTheDots(): void
    {
        $body = $this->planning();
        $this->assertSame(1, preg_match('/<p[^>]+id="sos-legend"[^>]*>(.*?)<\/p>/s', $body, $m));

        $this->assertSame(
            "quelqu'un de garde numéro par défaut aucun numéro",
            trim((string) preg_replace('/\s+/', ' ', strip_tags($m[1])))
        );
    }

    /**
     * The JSON island carries dates, member ids and duty states — never a
     * phone number, and never a name either (AGENTS.md § Security
     * checklist). The names the phone layout shows are rendered
     * server-side, and read back out of the DOM by sos-admin.js.
     */
    public function testTheJsonIslandCarriesNoPhoneNumberAndNoName(): void
    {
        $akela = $this->createStaffduMember('Akela', '+32470111222');
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->onCallRepository->replaceRange($today, $today, [
            new OnCallAssignment($akela, $today, OnCallAssignment::STATE_ONCALL),
        ]);

        $body = $this->planning();

        $start = strpos($body, 'id="sos-admin-data"');
        $this->assertNotFalse($start);
        $island = substr($body, $start, strpos($body, '</script>', $start) - $start);

        $this->assertStringNotContainsString('+32470111222', $island);
        $this->assertStringNotContainsString('Akela', $island);
        $this->assertStringContainsString('orderedMemberIds', $island);
    }

    // ------------------------------------------------------------------
    // §4b — the default-number warning is conditional.
    // ------------------------------------------------------------------

    public function testDefaultNumberWarningIsShownWhenTodayHasNoOnCall(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');

        $body = $this->settingsPage();

        $this->assertStringContainsString('default-number-immediate-warning', $body);
        $this->assertStringContainsString("Aujourd'hui n'a pas de garde attribuée", $body);
    }

    public function testDefaultNumberWarningIsHiddenWhenTodayHasAnOnCall(): void
    {
        $akela = $this->createStaffduMember('Akela', '+32470000001');
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->onCallRepository->replaceRange($today, $today, [
            new OnCallAssignment($akela, $today, OnCallAssignment::STATE_ONCALL),
        ]);

        $body = $this->settingsPage();

        $this->assertStringNotContainsString('default-number-immediate-warning', $body);
    }

    // ------------------------------------------------------------------
    // Issue #1026 — three sub-pages.
    // ------------------------------------------------------------------

    /**
     * @return array<int, array{href: string, selected: bool}>
     */
    private function railOf(string $body): array
    {
        $this->assertSame(1, preg_match('/<nav class="nav-rail" id="sos-page-picker".*?<\/nav>/s', $body, $nav));
        preg_match_all('/<a href="([^"]+)"[^>]*data-selected="(true|false)"/s', $nav[0], $links, PREG_SET_ORDER);

        return array_map(
            static fn(array $link) => ['href' => $link[1], 'selected' => $link[2] === 'true'],
            $links
        );
    }

    public function testEverySubPageOffersTheThreeSubPagesInOrderAndMarksItsOwn(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');

        foreach ([
            0 => $this->dashboard(),
            1 => $this->planning(),
            2 => $this->settingsPage(),
        ] as $current => $body) {
            $rail = $this->railOf($body);

            $this->assertSame(
                ['/admin/sos', '/admin/sos/planning', '/admin/sos/reglages'],
                array_column($rail, 'href')
            );
            $this->assertSame(
                [$current === 0, $current === 1, $current === 2],
                array_column($rail, 'selected'),
                'only the page being drawn is selected'
            );
            $this->assertMatchesRegularExpression(
                '/Tableau de bord<\/span>.*Planning<\/span>.*Réglages<\/span>/s',
                $body
            );
        }
    }

    public function testTheDashboardShowsTheCurrentRedirectionThenThePlannedOnesAndNothingElse(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $body = $this->dashboard();

        $banner = strpos($body, 'id="sos-status-banner"');
        $planned = strpos($body, 'id="planned-transitions-list"');
        $this->assertNotFalse($banner);
        $this->assertNotFalse($planned);
        $this->assertLessThan($planned, $banner, '« Redirection actuelle » comes first');
        $this->assertStringContainsString('Redirections planifiées', $body);

        foreach (['sos-day-list', 'sos-grid', 'sos-day-sheet', 'sos-settings', 'default-number-member'] as $elsewhere) {
            $this->assertStringNotContainsString($elsewhere, $body, "{$elsewhere} lives on another sub-page");
        }
    }

    public function testThePlanningAndTheSettingsKeepTheirOwnBlocksOnly(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $planning = $this->planning();
        $settings = $this->settingsPage();

        foreach (['sos-day-list', 'sos-grid', 'sos-day-sheet', 'oncall-save-status'] as $present) {
            $this->assertStringContainsString($present, $planning);
            $this->assertStringNotContainsString($present, $settings);
        }
        foreach (['sos-status-banner', 'planned-transitions-list', 'default-number-member', 'sos-settings'] as $absent) {
            $this->assertStringNotContainsString($absent, $planning, "{$absent} is not the planning's");
        }
        foreach (['sos-status-banner', 'planned-transitions-list'] as $absent) {
            $this->assertStringNotContainsString($absent, $settings, "{$absent} is not the settings'");
        }
    }

    public function testTheOldAddressWithAMonthStillLandsOnThatMonthOfThePlanning(): void
    {
        $response = $this->controller->index(
            new Request('GET', '/admin/sos', ['month' => '2026-11'], [], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/sos/planning?month=2026-11', $response->getHeaders()['Location'] ?? null);
    }

    public function testTheMonthNavigationStaysOnThePlanning(): void
    {
        $body = $this->planning(['month' => '2026-11']);

        $this->assertStringContainsString('href="/admin/sos/planning?month=2026-10"', $body);
        $this->assertStringContainsString('href="/admin/sos/planning?month=2026-12"', $body);
    }

    // ------------------------------------------------------------------
    // Issue #1026 — « Redirection actuelle » and its one correction.
    // ------------------------------------------------------------------

    public function testAnInactiveRedirectionWithAnExpectedDestinationOffersToReactivateIt(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(false, null);

        $body = $this->dashboard();

        $this->assertStringContainsString('Réactiver la redirection', $body);
        $this->assertStringContainsString('data-redirect-action="reactivate"', $body);
        $this->assertStringNotContainsString('Resynchroniser la redirection', $body);
        $this->assertStringContainsString('+32470000001', $body, 'the expected destination is named');
    }

    public function testARedirectionOnAnotherNumberOffersToResynchronizeIt(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(true, '+32 499 99 99 99');

        $body = $this->dashboard();

        $this->assertStringContainsString('Resynchroniser la redirection', $body);
        $this->assertStringContainsString('data-redirect-action="resync"', $body);
        $this->assertStringNotContainsString('Réactiver la redirection', $body);
    }

    public function testAConformRedirectionHasNoCorrectionButton(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(true, '+32470000001');

        $body = $this->dashboard();

        $this->assertStringContainsString('Redirection active chez le fournisseur', $body);
        $this->assertStringNotContainsString('sos-redirect-sync', $body);
        $this->assertStringNotContainsString('sos-redirect-expected', $body);
    }

    public function testAnUnreadableProviderIsNotPresentedAsACorrectableMisalignment(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ProviderException('Le fournisseur ne répond pas.');

        $body = $this->dashboard();

        $this->assertStringContainsString('État de la redirection illisible', $body);
        $this->assertStringNotContainsString('sos-redirect-sync', $body);
        $this->assertStringNotContainsString('sos-redirect-no-destination', $body);
    }

    public function testWithNoValidDestinationThePageSaysSoInsteadOfOfferingACorrection(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->noDestination = true;
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(false, null);

        $body = $this->dashboard();

        $this->assertStringNotContainsString('sos-redirect-sync', $body);
        $this->assertStringContainsString('id="sos-redirect-no-destination"', $body);
    }

    public function testTheExpectedDestinationIsTheMemberOnDutyThenTheDefaultNumber(): void
    {
        $akela = $this->createStaffduMember('Akela', '+32470000001');
        $baloo = $this->createStaffduMember('Baloo', '+32470000002');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(true, '+32470000009');

        // Nobody on duty: the default number (the roster's first member).
        $this->assertMatchesRegularExpression(
            '/id="sos-redirect-expected".*?<strong>[^<]*\+32470000001<\/strong>/s',
            $this->dashboard()
        );

        // An explicit duty wins over the default number.
        $this->putOnDuty($baloo);
        $this->assertMatchesRegularExpression(
            '/id="sos-redirect-expected".*?<strong>[^<]*\+32470000002<\/strong>/s',
            $this->dashboard()
        );

        // And the duty holder's own number is conform: no button.
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(true, '+32470000002');
        $this->assertStringNotContainsString('sos-redirect-sync', $this->dashboard());
        $this->assertNotSame(0, $akela);
    }

    // ------------------------------------------------------------------
    // Issue #1026 — the correction itself.
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra whatever else a hostile client might send
     * @return array<string, mixed> the decoded answer, status code under `_status`
     */
    private function resynchronize(array $extra = [], ?string $token = null): array
    {
        $response = $this->controller->resynchronizeRedirect(
            $this->jsonRequest($extra + ['_csrf_token' => $token ?? $this->csrfToken()], '/admin/sos/redirection'),
            []
        );

        return json_decode($response->getBody(), true) + ['_status' => $response->getStatusCode()];
    }

    public function testTheCorrectionRefusesARequestWithoutAValidCsrfToken(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');

        $answer = $this->resynchronize([], 'bad');

        $this->assertSame(403, $answer['_status']);
        $this->assertSame([], $this->applyCalls);
    }

    public function testReactivatingAnInactiveRedirectionIsAnnouncedOnlyOnceTheProviderReadsBackRight(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(false, null);

        $answer = $this->resynchronize();

        $this->assertSame(200, $answer['_status']);
        $this->assertTrue($answer['success']);
        $this->assertSame('Redirection réactivée et vérifiée.', $answer['message']);
        $this->assertCount(1, $this->applyCalls);
        // The block comes back drawn from what the provider NOW says, with
        // no correction left to offer.
        $this->assertStringContainsString('Redirection active chez le fournisseur', $answer['html']);
        $this->assertStringContainsString('+32470000001', $answer['html']);
        $this->assertStringNotContainsString('sos-redirect-sync', $answer['html']);
    }

    public function testResynchronizingAWrongNumberSaysSo(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(true, '+32470000009');

        $answer = $this->resynchronize();

        $this->assertTrue($answer['success']);
        $this->assertSame('Redirection resynchronisée et vérifiée.', $answer['message']);
    }

    public function testTheDestinationComesFromTheServerNeverFromTheRequest(): void
    {
        $akela = $this->createStaffduMember('Akela', '+32470000001');
        $baloo = $this->createStaffduMember('Baloo', '+32470000002');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(false, null);
        $this->putOnDuty($akela);

        // A hostile body: a number, a member, a month, an action.
        $answer = $this->resynchronize([
            'number' => '+32400000000',
            'member_id' => $baloo,
            'month' => '2030-01',
            'action' => 'resync',
        ]);

        $this->assertTrue($answer['success']);
        $this->assertCount(1, $this->applyCalls);
        $this->assertSame($akela, $this->applyCalls[0][0], 'the duty holder, as the planning says');
        $this->assertEquals(
            new \Modules\SosStaff\Api\ForwardingState(true, '+32470000001'),
            $this->forwarding
        );
    }

    public function testTheDefaultNumberIsTheDestinationWhenNobodyIsOnDuty(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(true, '+32470000009');

        $answer = $this->resynchronize();

        $this->assertTrue($answer['success']);
        $this->assertNull($this->applyCalls[0][0], 'no member: apply() resolves the default number');
        $this->assertEquals(new \Modules\SosStaff\Api\ForwardingState(true, '+32470000001'), $this->forwarding);
    }

    public function testTheMemberTheLineIsLeavingIsPassedOnSoTheyAreToldTheirDutyEnded(): void
    {
        $akela = $this->createStaffduMember('Akela', '+32470000001');
        $baloo = $this->createStaffduMember('Baloo', '+32470000002');
        $this->putOnDuty($baloo);
        // The line is still on Akela's phone.
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(true, '+32470000001');

        $this->resynchronize();

        $this->assertSame([[$baloo, $akela, $this->scoutYearId]], $this->applyCalls);
    }

    public function testNothingIsDoneWhenTheRedirectionIsAlreadyConform(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(true, '+32470000001');

        $answer = $this->resynchronize();

        $this->assertTrue($answer['success']);
        $this->assertSame('La redirection est déjà conforme.', $answer['message']);
        $this->assertSame([], $this->applyCalls);
    }

    public function testAnUnreadableProviderRefusesTheCorrectionRatherThanGuessing(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ProviderException('Le fournisseur ne répond pas.');

        $answer = $this->resynchronize();

        $this->assertSame(409, $answer['_status']);
        $this->assertFalse($answer['success']);
        $this->assertStringContainsString('Le fournisseur ne répond pas.', $answer['error']);
        $this->assertSame([], $this->applyCalls);
    }

    public function testNoValidDestinationRefusesTheCorrection(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->noDestination = true;
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(false, null);

        $answer = $this->resynchronize();

        $this->assertSame(409, $answer['_status']);
        $this->assertFalse($answer['success']);
        $this->assertSame([], $this->applyCalls);
    }

    public function testNoProviderRefusesTheCorrection(): void
    {
        $this->activeProvider = null;

        $answer = $this->resynchronize();

        $this->assertSame(409, $answer['_status']);
        $this->assertFalse($answer['success']);
        $this->assertSame([], $this->applyCalls);
    }

    public function testAFailedChangeIsNeverAnnouncedAsASuccess(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(false, null);
        $this->applyFailure = new \Modules\SosStaff\Service\SosException('Le changement de redirection du numéro SOS a échoué.');

        $answer = $this->resynchronize();

        $this->assertSame(502, $answer['_status']);
        $this->assertFalse($answer['success']);
        $this->assertSame('Le changement de redirection du numéro SOS a échoué.', $answer['error']);
        $this->assertArrayNotHasKey('message', $answer);
    }

    public function testAnAcknowledgedChangeThatTheProviderDoesNotReflectIsNotASuccess(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(false, null);
        // apply() returns without error, and the provider still says « inactive ».
        $this->applyChangesTheProvider = false;

        $answer = $this->resynchronize();

        $this->assertSame(502, $answer['_status']);
        $this->assertFalse($answer['success']);
        $this->assertStringContainsString("n'a pas pu être vérifiée", $answer['error']);
        $this->assertArrayNotHasKey('html', $answer, 'the block on screen is left as it was');
    }

    public function testTheCorrectionNeitherRewritesThePlanningNorMovesScheduledTransitions(): void
    {
        $akela = $this->createStaffduMember('Akela', '+32470000001');
        $baloo = $this->createStaffduMember('Baloo', '+32470000002');
        $this->putOnDuty($baloo);
        $this->schedulerRepository->create(
            OnCallService::MODULE_ID,
            OnCallService::TASK_KEY,
            (new \DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s'),
            json_encode(['member_id' => $akela]) ?: null,
            (new \DateTimeImmutable('+2 days'))->format('Y-m-d')
        );
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(false, null);
        $before = $this->schedulerRepository->findByModuleAndTaskKey(OnCallService::MODULE_ID, OnCallService::TASK_KEY);
        $duties = $this->onCallRepository->findForRange(
            (new \DateTimeImmutable('yesterday'))->format('Y-m-d'),
            (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')
        );

        $this->assertTrue($this->resynchronize()['success']);

        $this->assertEquals(
            $before,
            $this->schedulerRepository->findByModuleAndTaskKey(OnCallService::MODULE_ID, OnCallService::TASK_KEY)
        );
        $this->assertEquals(
            $duties,
            $this->onCallRepository->findForRange(
                (new \DateTimeImmutable('yesterday'))->format('Y-m-d'),
                (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')
            )
        );
    }

    public function testTheManualRequestIsJournaled(): void
    {
        $akela = $this->createStaffduMember('Akela', '+32470000001');
        $this->forwarding = new \Modules\SosStaff\Api\ForwardingState(false, null);

        $this->resynchronize();

        $rows = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE category = 'sos_staff' AND event_type = 'redirect_manual_requested'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('Réactivation manuelle de la redirection demandée', $rows[0]['description']);
        // A member id, never a phone number (SECURITY.md §11).
        $this->assertSame(['member_id' => null], json_decode((string) $rows[0]['context'], true));
        $this->assertStringNotContainsString('+32470000001', (string) $rows[0]['context']);
        $this->assertNotSame(0, $akela);
    }

    /**
     * RBAC boundary for /admin/sos (Espace chefs d'U, role_min admin):
     * admin -> 200, intendant -> 403.
     */
    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/admin/sos', SosAdminController::class, 'index', 'admin');
        $router->addRoute('GET', '/admin/sos/planning', SosAdminController::class, 'planning', 'admin');
        $router->addRoute('GET', '/admin/sos/reglages', SosAdminController::class, 'settings', 'admin');
        $router->addRoute('POST', '/admin/sos/redirection', SosAdminController::class, 'resynchronizeRedirect', 'admin');

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

    /**
     * The permissions are the ones the single page had: every sub-page, and
     * the correction, are for the chef d'unité and above.
     */
    public function testEverySubPageAndTheCorrectionKeepTheSamePermissions(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        foreach (['/admin/sos/planning', '/admin/sos/reglages'] as $path) {
            $this->assertSame(
                200,
                $this->buildFrontController()->handle(new Request('GET', $path, [], [], [], []))->getStatusCode(),
                $path
            );
        }

        AuthSession::login(1, 'intendant@test.be', 'intendant');
        foreach (['GET' => ['/admin/sos/planning', '/admin/sos/reglages'], 'POST' => ['/admin/sos/redirection']] as $method => $paths) {
            foreach ($paths as $path) {
                $this->assertSame(
                    403,
                    $this->buildFrontController()->handle(new Request($method, $path, [], [], [], []))->getStatusCode(),
                    "{$method} {$path}"
                );
            }
        }
    }
}
