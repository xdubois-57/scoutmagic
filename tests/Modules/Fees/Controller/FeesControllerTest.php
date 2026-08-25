<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\View\TwigFactory;
use Modules\Fees\Controller\FeesController;
use Modules\Fees\Repository\FeesImportRepository;
use Core\Import\RosterSnapshotRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;
use Twig\Environment;

/**
 * The module's only route, and its RBAC boundary: allowed at `admin` (the
 * espace_admin menu's own floor), refused one level below at `chief`.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FeesControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private FeesController $controller;
    private RosterSnapshotRepository $snapshots;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->scoutYearId = $scoutYearService->ensureYear('2025-2026');
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->scoutYearId);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $this->snapshots = new RosterSnapshotRepository($this->pdo);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/fees/views';
        $twig = TwigFactory::create($templateDir, false, ['fees' => $moduleViews]);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/admin/fees');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig = $twig;

        $this->controller = new FeesController(
            $twig,
            $this->snapshots,
            new FeesImportRepository($this->pdo),
            $scoutYearResolver
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testAdminGetsThePage(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch();

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('Cotisations', (string) $response->getBody());
    }

    public function testOneRoleBelowIsRejected(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $this->assertSame(403, $this->dispatch()->getStatusCode());
    }

    /**
     * The page has to say that snapshots only start at activation — a
     * treasurer must not discover in March that November's invoice was
     * never checkable.
     */
    public function testThePageStatesThatEarlierInvoicesCannotBeChecked(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch()->getBody();

        $this->assertStringContainsString('jamais vérifiables ligne par ligne', $body);
        $this->assertStringContainsString('Aucune photographie pour', $body);
    }

    public function testThePageShowsTheLatestSnapshotOnceOneExists(): void
    {
        $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2025-11-02 08:30:00'));
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch()->getBody();

        $this->assertStringContainsString('02/11/2025', $body);
        $this->assertStringNotContainsString('Aucune photographie pour', $body);
    }

    public function testThePageStatesTheDateOfTheImportItReads(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, user_account_id, line_count, member_count, new_functions_count, imported_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->scoutYearId, null, 10, 8, 0, '2025-11-02 08:29:00']);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch()->getBody();

        $this->assertStringContainsString('Dernier import Desk', $body);
        $this->assertStringContainsString('02/11/2025 à 08:29', $body);
    }

    private function dispatch(): \Core\Http\Response
    {
        $router = new Router();
        $router->addRoute('GET', '/admin/fees', FeesController::class, 'index', 'admin');

        $configFile = sys_get_temp_dir() . '/test_fees_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(FeesController::class, $this->controller);

        return $fc->handle(new Request('GET', '/admin/fees', [], [], [], []));
    }
}
