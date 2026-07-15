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
use Core\ScoutYear\ScoutYearSession;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
class ScoutYearControllerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;
    private ScoutYearController $controller;
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

        $this->controller = new ScoutYearController($twig, $resolver, $adminService, $scoutYearService, $journalService);

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
        $this->assertStringContainsString("Aller à l'import Desk", $body);
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

    private function assertJournalHas(string $eventType, string $level): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM event_log WHERE category = ? AND event_type = ? AND level = ?');
        $stmt->execute(['core', $eventType, $level]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), "Expected one journal entry for {$eventType}");
    }
}
