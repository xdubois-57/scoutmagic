<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\ScoutYearController;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Photo\SectionPhotoRepository;
use Core\ScoutYear\ScoutYearSession;
use Core\ScoutYear\ScoutYearTransitionService;
use Core\ScoutYear\ScoutYearTransitionStepRepository;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Api\ScoutYearEventCountProvider;
use Modules\Registration\Api\ScoutYearPreparationProvider;
use Modules\Registration\Api\ScoutYearTransitionVetoProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
        $adminService = new ScoutYearAdminService($this->settingService);
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

        $this->assertSame(403, $response->getStatusCode());
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
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->yearB);

        $request = $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $this->controller->activatePublic($request, []);

        $this->assertSame((string) $this->yearB, $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
        $this->assertJournalHas('scout_year_public_activated', 'security');
    }

    public function testActivateStaffRejectsInvalidCsrf(): void
    {
        $request = $this->post('/admin/scout-year/activate-staff', ['_csrf_token' => 'wrong', 'scout_year_id' => $this->yearB]);
        $response = $this->controller->activateStaff($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR));
    }

    public function testTransitionStepsRenderWithNoneCompleted(): void
    {
        // Pin the public year so the target (next year) is deterministic.
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearB); // 2025-2026

        $body = $this->controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Importer les données Desk', $body);
        $this->assertStringContainsString('Activer pour le staff', $body);
        // Step labels are data now (ScoutYearTransitionService), so Twig
        // escapes their apostrophes — decode before matching on French text.
        $this->assertStringContainsString("Aller à l'import Desk", html_entity_decode($body, ENT_QUOTES, 'UTF-8'));
        $this->assertStringContainsString('2026-2027', $body); // dynamic target label
        $this->assertStringNotContainsString('bi-check-lg', $body); // nothing completed yet
    }

    public function testStaffStepShowsCompletedWhenStaffYearIsTarget(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearB);
        $targetId = (new ScoutYearService($this->pdo))->ensureYear('2026-2027');
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
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->yearB);

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
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->yearB);

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
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->yearB);

        $request = $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $controller->activatePublic($request, []);

        $this->assertSame('0', $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
    }

    public function testActivatePublicSucceedsWhenVetoReportsNothingOpen(): void
    {
        $controller = $this->buildControllerWithVeto(0);
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->yearB);

        $request = $this->post('/admin/scout-year/activate-public', ['_csrf_token' => $this->token, 'scout_year_id' => $this->yearB]);
        $controller->activatePublic($request, []);

        $this->assertSame((string) $this->yearB, $this->settingService->get(ScoutYearResolver::SETTING_PUBLIC_YEAR));
    }

    public function testActivateStaffNotBlockedByVeto(): void
    {
        // Step 3 only warns, never blocks — activating the staff year must
        // still succeed with open requests present.
        $controller = $this->buildControllerWithVeto(4);

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
        $this->assertStringContainsString('/config/inscriptions', $body);
    }

    public function testIndexShowsBlockingAlertAtStepFourAndDisablesActivation(): void
    {
        $controller = $this->buildControllerWithVeto(2);
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->yearB);

        $body = $controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Activation bloquée', $body);
    }

    public function testIndexHasNoWarningWhenNothingBlocks(): void
    {
        $controller = $this->buildControllerWithVeto(0);

        $body = $controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('Activation bloquée', $body);
        $this->assertStringNotContainsString("ne sont pas encore clôturées", $body);
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

        $request = $this->post('/admin/scout-year/step', ['_csrf_token' => $this->token, 'step_key' => 'fees', 'done' => '1']);
        $response = $this->controller->toggleStep($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([$targetId, 'fees'], $this->firstStepMark());
        $this->assertJournalHas('scout_year_step_marked', 'info');

        $body = $this->controller->index(new Request('GET', '/admin/scout-year', [], [], [], []), [])->getBody();
        $this->assertStringContainsString('bi-check-lg', $body);

        $request = $this->post('/admin/scout-year/step', ['_csrf_token' => $this->token, 'step_key' => 'fees', 'done' => '0']);
        $this->controller->toggleStep($request, []);

        $this->assertNull($this->firstStepMark());
        $this->assertJournalHas('scout_year_step_unmarked', 'info');
    }

    public function testToggleStepRejectsInvalidCsrf(): void
    {
        $request = $this->post('/admin/scout-year/step', ['_csrf_token' => 'wrong', 'step_key' => 'fees', 'done' => '1']);
        $response = $this->controller->toggleStep($request, []);

        $this->assertSame(403, $response->getStatusCode());
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
        $veto = $this->createMock(ScoutYearTransitionVetoProvider::class);
        $veto->method('countBlockingRequests')->willReturn($blockingCount);

        $scoutYearService = new ScoutYearService($this->pdo);

        return $this->makeController(
            new ScoutYearResolver($scoutYearService, $this->settingService, new MemberYearRepository($this->pdo)),
            new ScoutYearAdminService($this->settingService, $veto),
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
     */
    private function makeController(
        ScoutYearResolver $resolver,
        ScoutYearAdminService $adminService,
        ScoutYearService $scoutYearService,
        JournalService $journalService,
        array $enabledModuleIds = [],
        ?ScoutYearPreparationProvider $preparation = null,
        ?ScoutYearEventCountProvider $eventCount = null
    ): ClockableScoutYearController {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chief@test.com');
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new \Twig\TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new \Twig\TwigFunction('param', fn(string $k) => 'Test'));

        $transitionService = new ScoutYearTransitionService(
            $resolver,
            new ScoutYearTransitionStepRepository($this->pdo),
            new SectionPhotoRepository($this->pdo),
            new UserAccountRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            $this->settingService,
            $enabledModuleIds,
            $preparation,
            $eventCount
        );

        $controller = new ClockableScoutYearController(
            $twig, $resolver, $adminService, $scoutYearService, $transitionService, $journalService
        );
        $controller->fakeNow = new \DateTimeImmutable('2026-08-15');

        return $controller;
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
