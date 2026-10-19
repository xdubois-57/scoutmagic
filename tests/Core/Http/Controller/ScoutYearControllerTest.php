<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\ScoutYearController;
use Core\Http\Request;
use Core\Import\ImportJournalRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;
use Modules\Registration\Api\ScoutYearTransitionVetoProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\ScoutYear\TransitionServiceFactory;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearControllerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;
    private ClockableScoutYearController $controller;
    private string $token = 'test-token';
    private int $yearA;
    private int $yearB;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'Public year id', null, '^[0-9]+$', null, false);
        $this->settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'Staff year id', null, '^[0-9]+$', null, false);

        $resolver = new ScoutYearResolver($scoutYearService, $this->settingService, new MemberYearRepository($this->pdo));
        $adminService = new ScoutYearAdminService($this->settingService, new ImportJournalRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->yearA = $scoutYearService->ensureYear('2024-2025');
        $this->yearB = $scoutYearService->ensureYear('2025-2026');

        $this->controller = $this->makeController($resolver, $adminService, $scoutYearService, $journalService);

        $this->startSession();
        $_SESSION['_csrf_token'] = $this->token;
        $_SESSION['_auth'] = ['user_account_id' => 1, 'email' => 'chief@test.com', 'role' => 'chief'];
        ScoutYearSession::clear();
    }

    protected function tearDown(): void
    {
        ScoutYearSession::clear();
        $_SESSION = [];
    }

    private function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            session_start();
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): Request
    {
        return new Request('POST', $path, [], $body, [], []);
    }

    public function testIndexRenders(): void
    {
        $request = new Request('GET', '/admin/scout-year', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Année scoute', $response->getBody());
    }

    public function testPreviewRejectsInvalidCsrf(): void
    {
        $request = $this->post('/admin/scout-year/preview', ['_csrf_token' => 'wrong', 'scout_year_id' => $this->yearB]);
        $response = $this->controller->preview($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertNull(ScoutYearSession::getPreviewId());
    }

    public function testPreviewSetsSessionOverride(): void
    {
        $request = $this->post('/admin/scout-year/preview', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $response = $this->controller->preview($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($this->yearB, ScoutYearSession::getPreviewId());
    }

    public function testPreviewRejectsUnknownYear(): void
    {
        $request = $this->post('/admin/scout-year/preview', ['_csrf_token' => $this->token, 'scout_year_id' => 99999]);
        $this->controller->preview($request, []);

        $this->assertNull(ScoutYearSession::getPreviewId());
    }

    public function testClearPreviewClearsSessionOverride(): void
    {
        ScoutYearSession::setPreview($this->yearB);

        $request = $this->post('/admin/scout-year/clear-preview', ['_csrf_token' => $this->token]);
        $this->controller->clearPreview($request, []);

        $this->assertNull(ScoutYearSession::getPreviewId());
    }

    public function testActivateStaffSetsSettingAndJournals(): void
    {
        $this->givenImport($this->yearB);

        $request = $this->post('/admin/scout-year/activate-staff', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $response = $this->controller->activateStaff($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame((string) $this->yearB, $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
        $this->assertJournalHas('scout_year_staff_activated', 'security');
    }

    public function testDeactivateStaffClearsSettingAndJournals(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->yearB);

        $request = $this->post('/admin/scout-year/deactivate-staff', ['_csrf_token' => $this->token]);
        $this->controller->deactivateStaff($request, []);

        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
        $this->assertJournalHas('scout_year_staff_deactivated', 'security');
    }

    public function testActivatePublicSetsCurrentClearsStaffAndJournals(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearA);
        $this->givenReadyForTheSwitch($this->yearB);

        $request = $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $this->controller->activatePublic($request, []);

        $this->assertSame((string) $this->yearB, $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
        $this->assertJournalHas('scout_year_public_activated', 'security');
        // What the « Dernière bascule » line reads back.
        $context = json_decode((string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'scout_year_public_activated'"
        )->fetchColumn(), true);
        $this->assertSame(
            ['old_year_id' => $this->yearA, 'new_year_id' => $this->yearB, 'old_year_label' => '2024-2025', 'new_year_label' => '2025-2026'],
            $context
        );
    }

    public function testActivateStaffRejectsInvalidCsrf(): void
    {
        $request = $this->post('/admin/scout-year/activate-staff', ['_csrf_token' => 'wrong', 'scout_year_id' => $this->yearB]);
        $response = $this->controller->activateStaff($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    public function testTransitionStepsRenderWithNoneCompleted(): void
    {
        // Pin the public year so the target (next year) is deterministic.
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearB); // 2025-2026

        $body = $this->controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Importer les données Desk pour 2026-2027', $body);
        $this->assertStringContainsString('Activer pour le staff', $body);
        // Step labels are data now (ScoutYearTransitionService), so Twig
        // escapes their apostrophes — decode before matching on French text.
        $this->assertStringContainsString("Aller à l'import Desk (2026-2027)", html_entity_decode($body, ENT_QUOTES, 'UTF-8'));
        $this->assertStringContainsString('2026-2027', $body); // dynamic target label
        $this->assertStringNotContainsString('bi-check-lg', $body); // nothing completed yet
    }

    public function testStaffStepShowsCompletedWhenStaffYearIsTarget(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearB);
        $targetId = (new ScoutYearService($this->pdo))->ensureYear('2026-2027');
        $this->givenImport($targetId);
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $targetId);

        $body = $this->controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        // The "activate for staff" step is now marked done (green check).
        $this->assertStringContainsString('bi-check-lg', $body);
    }

    /**
     * The transition is manual-only now (itération 3): no date restricts it
     * any more, so a date that used to fall well outside the old August–
     * September window must still succeed.
     */
    public function testActivatePublicAllowedAnyTimeOfYear(): void
    {
        $this->controller->fakeNow = new \DateTimeImmutable('2026-01-15');
        $this->givenReadyForTheSwitch($this->yearB);

        $request = $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $response = $this->controller->activatePublic($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame((string) $this->yearB, $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
    }

    public function testWarningAbsentWhenPublicYearHasNotEnded(): void
    {
        // yearB ("2025-2026") runs through 2026-08-31; "now" is well within it.
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearB);
        $this->controller->fakeNow = new \DateTimeImmutable('2026-03-01');

        $body = $this->controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('est terminée depuis le', $body);
    }

    public function testWarningShownWhenPublicYearHasEnded(): void
    {
        // yearA ("2024-2025") ended 2025-08-31; "now" is well past it.
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearA);
        $this->controller->fakeNow = new \DateTimeImmutable('2026-03-01');

        $body = $this->controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('est terminée depuis le', $body);
        $this->assertStringContainsString('2024-2025', $body);
    }

    /**
     * §8.38 — the registration module's veto, exercised at the controller
     * layer: blocked at step 4, shown as a non-blocking warning at step 3,
     * and refused server-side even when replayed directly (not merely a
     * disabled button).
     */
    public function testActivatePublicBlockedWhenVetoReportsOpenRequests(): void
    {
        $controller = $this->buildControllerWithVeto(3);
        $this->givenReadyForTheSwitch($this->yearB);

        $request = $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $response = $controller->activatePublic($request, []);

        $this->assertSame(302, $response->getStatusCode());
        // The old (unset) public year is unchanged — the transition never happened.
        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
        $this->assertJournalHas('scout_year_public_activation_blocked', 'security');
    }

    public function testActivatePublicBlockedEvenWhenReplayedDirectly(): void
    {
        // No prior GET, no button state — a bare POST straight to the
        // endpoint, exactly like a replayed/forged request would look.
        $controller = $this->buildControllerWithVeto(1);
        $this->givenReadyForTheSwitch($this->yearB);

        $request = $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $controller->activatePublic($request, []);

        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
    }

    public function testActivatePublicSucceedsWhenVetoReportsNothingOpen(): void
    {
        $controller = $this->buildControllerWithVeto(0);
        $this->givenReadyForTheSwitch($this->yearB);

        $request = $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $controller->activatePublic($request, []);

        $this->assertSame((string) $this->yearB, $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
    }

    public function testActivateStaffNotBlockedByVeto(): void
    {
        // The staff step only warns, never blocks on the veto — activating
        // the staff year must still succeed with open requests present.
        $controller = $this->buildControllerWithVeto(4);
        $this->givenImport($this->yearB);

        $request = $this->post('/admin/scout-year/activate-staff', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $response = $controller->activateStaff($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame((string) $this->yearB, $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    public function testIndexShowsNonBlockingWarningAtStepThree(): void
    {
        $controller = $this->buildControllerWithVeto(2);

        $body = $controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('2 demande', $body);
        $this->assertStringContainsString('href="/config/inscriptions/demandes"', $body);
    }

    public function testIndexShowsTheSwitchBlockedByTheVeto(): void
    {
        $controller = $this->buildControllerWithVeto(2);

        $body = html_entity_decode(
            $controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody(),
            ENT_QUOTES,
            'UTF-8'
        );

        $this->assertStringContainsString("2 demande(s) d'inscription restent ouvertes", $body);
        $this->assertStringContainsString('href="/config/inscriptions/demandes"', $body);
    }

    public function testIndexHasNoWarningWhenNothingBlocks(): void
    {
        $controller = $this->buildControllerWithVeto(0);

        $body = $controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('demande(s) d', $body);
    }

    /**
     * The manual check-off, end to end through the controller: the mark
     * lands on the transition's target year (public + 1, never the year
     * being previewed), survives a reload, is journalled, and can be taken
     * back.
     */
    public function testToggleStepMarksAndUnmarksAStepForTheTargetYear(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearB);
        $targetId = (new ScoutYearService($this->pdo))->ensureYear('2026-2027');

        $request = $this->post('/admin/scout-year/step', ['_csrf_token' => $this->token, 'step_key' => 'badges', 'done' => '1']);
        $response = $this->controller->toggleStep($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([$targetId, 'badges'], $this->firstStepMark());
        $this->assertJournalHas('scout_year_step_marked', 'info');

        $body = $this->controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();
        $this->assertStringContainsString('bi-check-lg', $body);

        $request = $this->post('/admin/scout-year/step', ['_csrf_token' => $this->token, 'step_key' => 'badges', 'done' => '0']);
        $this->controller->toggleStep($request, []);

        $this->assertNull($this->firstStepMark());
        $this->assertJournalHas('scout_year_step_unmarked', 'info');
    }

    public function testToggleStepRejectsInvalidCsrf(): void
    {
        $request = $this->post('/admin/scout-year/step', ['_csrf_token' => 'wrong', 'step_key' => 'badges', 'done' => '1']);
        $response = $this->controller->toggleStep($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertNull($this->firstStepMark());
    }

    /**
     * The steps the site derives itself are not a chief's to assert. A
     * forged POST naming one is refused rather than quietly ignored, so the
     * page can never be made to claim the public year was activated when it
     * was not.
     */
    public function testToggleStepRefusesAnAutoDerivedStep(): void
    {
        $request = $this->post('/admin/scout-year/step', ['_csrf_token' => $this->token, 'step_key' => 'activate_public', 'done' => '1']);
        $response = $this->controller->toggleStep($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->firstStepMark());
    }

    /**
     * Same refusal for a step whose module is disabled — here Inscriptions,
     * which this controller is built without.
     */
    public function testToggleStepRefusesAStepOfADisabledModule(): void
    {
        $request = $this->post('/admin/scout-year/step', ['_csrf_token' => $this->token, 'step_key' => 'departures', 'done' => '1']);
        $this->controller->toggleStep($request, []);

        $this->assertNull($this->firstStepMark());
    }

    // ---------------------------------------------------------------
    // The hard blocks, through direct POSTs (specifications.md §16.3): no prior
    // GET, no button state — exactly what a forged or replayed request
    // looks like.
    // ---------------------------------------------------------------

    public function testActivateStaffIsRefusedWithoutASuccessfulImport(): void
    {
        $request = $this->post('/admin/scout-year/activate-staff', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $response = $this->controller->activateStaff($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
        $this->assertJournalHas('scout_year_staff_activation_blocked', 'security');
        $this->assertJournalLacks('scout_year_staff_activated');
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('Aucun import Desk réussi pour 2025-2026', (string) ($flash['message'] ?? ''));
    }

    public function testActivatePublicIsRefusedWhileTheStaffYearIsNotTheTarget(): void
    {
        $this->givenImport($this->yearB);

        $request = $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $this->controller->activatePublic($request, []);

        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
        $this->assertJournalHas('scout_year_public_activation_blocked', 'security');
        $this->assertJournalLacks('scout_year_public_activated');
        $this->assertStringContainsString(
            "2025-2026 n'est pas activée pour les staffs.",
            (string) (\Core\Http\FlashMessage::get()['message'] ?? '')
        );
    }

    /**
     * The staff year can point at a year without an import only through a
     * hand-written or restored setting; the switch still refuses.
     */
    public function testActivatePublicIsRefusedWithoutASuccessfulImport(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->yearB);

        $request = $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $this->controller->activatePublic($request, []);

        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
        $this->assertSame((string) $this->yearB, $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
        $context = json_decode((string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'scout_year_public_activation_blocked'"
        )->fetchColumn(), true);
        $this->assertSame(['no_successful_import'], $context['blockers'] ?? null);
    }

    /**
     * Preview stopped being observed: it is a human step now, marked by
     * whoever has reviewed the preview.
     */
    public function testToggleStepAcceptsTheStepsThatBecameHuman(): void
    {
        foreach (['preview', 'staff_photos'] as $key) {
            $request = $this->post('/admin/scout-year/step', ['_csrf_token' => $this->token, 'step_key' => $key, 'done' => '1']);
            $this->controller->toggleStep($request, []);
        }

        $keys = $this->pdo->query('SELECT step_key FROM scout_year_transition_steps ORDER BY step_key')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['preview', 'staff_photos'], $keys);
    }

    public function testToggleStepRefusesTheImportStep(): void
    {
        $request = $this->post('/admin/scout-year/step', ['_csrf_token' => $this->token, 'step_key' => 'import', 'done' => '1']);
        $this->controller->toggleStep($request, []);

        $this->assertNull($this->firstStepMark());
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
    }

    /**
     * A human step whose check refuses the mark (step 1 during the
     * re-enrolment campaign, once lot E writes it) is refused on the POST
     * itself, with the check's own reason, and nothing is journalled.
     */
    public function testToggleStepRefusesAMarkTheStepsCheckRefuses(): void
    {
        $refusing = new class () implements StepCheckProvider {
            public function stepKey(): string
            {
                return 'departures';
            }

            public function check(StepContext $context): StepCheck
            {
                return new StepCheck(markRefusal: 'La campagne de réinscription est en cours.');
            }
        };
        $controller = $this->controllerWith(['registration'], [$refusing]);

        $request = $this->post('/admin/scout-year/step', ['_csrf_token' => $this->token, 'step_key' => 'departures', 'done' => '1']);
        $controller->toggleStep($request, []);

        $this->assertNull($this->firstStepMark());
        $this->assertJournalLacks('scout_year_step_marked');
        $this->assertSame(
            'La campagne de réinscription est en cours.',
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
    }

    /**
     * Every step of a fully equipped site renders, its own detail template
     * included — the fourteen files lot E fills must keep rendering here.
     */
    public function testEveryStepRendersWithItsDetailTemplate(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearA);
        $controller = $this->controllerWith(['registration', 'fees', 'calendar', 'trombinoscope']);

        $body = $controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        foreach (array_keys(\Core\ScoutYear\ScoutYearTransitionService::STEP_KEYS) as $key) {
            $this->assertStringContainsString('data-step="' . $key . '"', $body);
        }
        $this->assertStringContainsString('data-state="blocked"', $body);
        $this->assertStringContainsString('Le contrôle sera disponible après un import réussi de 2025-2026.', html_entity_decode($body, ENT_QUOTES, 'UTF-8'));
    }

    public function testIndexShowsTheLastSwitchFromTheJournal(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearA);
        $this->givenReadyForTheSwitch($this->yearB);
        $this->controller->activatePublic(
            $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]),
            []
        );

        $body = $this->controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Dernière bascule :', $body);
        $this->assertStringContainsString('2024-2025 → 2025-2026', $body);
    }

    private function assertJournalLacks(string $eventType): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM event_log WHERE event_type = ?');
        $stmt->execute([$eventType]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), "Expected no journal entry for {$eventType}");
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function firstStepMark(): ?array
    {
        $row = $this->pdo->query('SELECT scout_year_id, step_key FROM scout_year_transition_steps')->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : [(int) $row['scout_year_id'], (string) $row['step_key']];
    }

    private function buildControllerWithVeto(int $blockingCount): ClockableScoutYearController
    {
        $veto = $this->createStub(ScoutYearTransitionVetoProvider::class);
        $veto->method('countBlockingRequests')->willReturn($blockingCount);

        $scoutYearService = new ScoutYearService($this->pdo);

        return $this->makeController(
            new ScoutYearResolver($scoutYearService, $this->settingService, new MemberYearRepository($this->pdo)),
            new ScoutYearAdminService($this->settingService, new ImportJournalRepository($this->pdo), $veto),
            $scoutYearService,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    /**
     * One controller, built the way the composition root builds it. Every
     * optional module provider defaults to absent — the shape a site with
     * neither Inscriptions nor Calendrier enabled has, which is also the
     * shape that must keep working (ARCHITECTURE.md §7.5).
     *
     * @param array<int, string> $enabledModuleIds
     * @param list<StepCheckProvider> $overrides
     */
    private function makeController(
        ScoutYearResolver $resolver,
        ScoutYearAdminService $adminService,
        ScoutYearService $scoutYearService,
        JournalService $journalService,
        array $enabledModuleIds = [],
        array $overrides = []
    ): ClockableScoutYearController {
        $twig = TestTwig::create([], ['param' => fn(string $k) => 'Test']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chief@test.com');
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $transitionService = TransitionServiceFactory::create(
            $this->pdo,
            $this->settingService,
            $adminService,
            $enabledModuleIds,
            null,
            null,
            $overrides
        );

        $controller = new ClockableScoutYearController(
            $twig, $resolver, $adminService, $scoutYearService, $transitionService, $journalService
        );
        $controller->fakeNow = new \DateTimeImmutable('2026-08-15');

        return $controller;
    }

    private function givenImport(int $yearId): void
    {
        (new ImportJournalRepository($this->pdo))->create($yearId, 1, 10, 9, 0);
    }

    /** A successful import of the year, and the staff year on it: nothing left in the way of the switch. */
    private function givenReadyForTheSwitch(int $yearId): void
    {
        $this->givenImport($yearId);
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $yearId);
    }

    /**
     * @param array<int, string> $enabledModuleIds
     * @param list<StepCheckProvider> $overrides
     */
    private function controllerWith(array $enabledModuleIds, array $overrides = []): ClockableScoutYearController
    {
        $scoutYearService = new ScoutYearService($this->pdo);

        return $this->makeController(
            new ScoutYearResolver($scoutYearService, $this->settingService, new MemberYearRepository($this->pdo)),
            new ScoutYearAdminService($this->settingService, new ImportJournalRepository($this->pdo)),
            $scoutYearService,
            new JournalService(new JournalRepository($this->pdo)),
            $enabledModuleIds,
            $overrides
        );
    }

    private function assertJournalHas(string $eventType, string $level): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM event_log WHERE category = ? AND event_type = ? AND level = ?');
        $stmt->execute(['core', $eventType, $level]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), "Expected one journal entry for {$eventType}");
    }
}

/**
 * Test double allowing the current date to be controlled, for the
 * "public year ended" warning (§8.26).
 */
class ClockableScoutYearController extends ScoutYearController
{
    public ?\DateTimeImmutable $fakeNow = null;

    protected function now(): \DateTimeImmutable
    {
        return $this->fakeNow ?? new \DateTimeImmutable();
    }
}
