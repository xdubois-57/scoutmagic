<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\SupportDashboard\Controller\DeskMappingGapController;
use Modules\SupportDashboard\Repository\DeskMappingGapRepository;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Service\DeskMappingGapReport;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * `/support-dashboard/correspondances` — the third screen of Supervision
 * (issue #356), through the REAL Router/RbacGuard pipeline for the
 * boundary, like its two siblings.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeskMappingGapControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private DeskMappingGapRepository $gaps;
    private DeskMappingGapController $controller;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $installations = new SupportInstallationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $payload = [
            'statistics_schema_version' => 2,
            'installation_id' => 'aaaabbbbccccdddd',
            'instance_url' => 'https://25e-sv.be',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
            'desk_unresolved' => ['total' => 1, 'listed' => [['kind' => 'branch', 'value' => 'Nutons']]],
        ];
        $installations->register(
            'aaaabbbbccccdddd',
            password_hash('x', PASSWORD_DEFAULT),
            (string) json_encode($payload),
            StatisticsIntakeService::denormalize($payload)
        );

        $this->gaps = new DeskMappingGapRepository($this->pdo);
        $this->gaps->rememberIfNew('branch', 'nutons', 'Nutons');

        $this->twig = TwigFactory::create(
            dirname(__DIR__, 3) . '/core/View/templates',
            false,
            ['support_dashboard' => dirname(__DIR__, 3) . '/modules/support_dashboard/views']
        );
        $this->twig->addGlobal('site_name', 'Test Unit');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'superadmin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('current_path', '/support-dashboard/correspondances');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new DeskMappingGapController(
            $this->twig,
            new DeskMappingGapReport($installations, $this->gaps),
            $this->gaps,
            new JournalService(new JournalRepository($this->pdo))
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testSuperadminReadsTheValueItsEffectAndTheTableToComplete(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController('/support-dashboard/correspondances', 'index')
            ->handle(new Request('GET', '/support-dashboard/correspondances', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Nutons', $body);
        $this->assertStringContainsString('Classée en dernier', $body);
        $this->assertStringContainsString('canonicalSortOrder()', $body);
        // The third pill, so the screen is reachable from its siblings.
        $this->assertStringContainsString('Correspondances', $body);
    }

    public function testAdminIsRejectedByTheGuard(): void
    {
        AuthSession::login(2, 'admin@test.com', 'admin');

        $response = $this->frontController('/support-dashboard/correspondances', 'index')
            ->handle(new Request('GET', '/support-dashboard/correspondances', [], [], [], []));

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Nutons', $response->getBody());
    }

    public function testSettingAValueAsideHidesItAndLeavesItReadableBehindTheFilter(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');
        $id = $this->gaps->findAllKeyed()['branch|nutons']['id'];

        $response = $this->frontController('/support-dashboard/correspondances/{id}/ecarter', 'ignore', 'POST')
            ->handle(new Request(
                'POST',
                '/support-dashboard/correspondances/' . $id . '/ecarter',
                [],
                ['_csrf_token' => CsrfGuard::generateToken()],
                [],
                []
            ));

        $this->assertSame(302, $response->getStatusCode());

        $hidden = $this->controller->index(
            new Request('GET', '/support-dashboard/correspondances', [], [], [], []),
            []
        )->getBody();
        $this->assertStringNotContainsString('Nutons', $hidden);

        $shown = $this->controller->index(
            new Request('GET', '/support-dashboard/correspondances', ['ecartees' => '1'], [], [], []),
            []
        )->getBody();
        $this->assertStringContainsString('Nutons', $shown);
        $this->assertStringContainsString('Réactiver', $shown);
    }

    /**
     * Never a delete — the next report would recreate the row and the
     * judgement would have to be made again.
     */
    public function testSettingAValueAsideKeepsItsRow(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');
        $id = $this->gaps->findAllKeyed()['branch|nutons']['id'];

        $this->gaps->setIgnored($id, true);

        $this->assertNotNull($this->gaps->findById($id));
        $this->assertNotNull($this->gaps->findById($id)['ignored_at']);
    }

    public function testAPostWithoutACsrfTokenChangesNothing(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');
        $id = $this->gaps->findAllKeyed()['branch|nutons']['id'];

        $this->frontController('/support-dashboard/correspondances/{id}/ecarter', 'ignore', 'POST')
            ->handle(new Request(
                'POST',
                '/support-dashboard/correspondances/' . $id . '/ecarter',
                [],
                [],
                [],
                []
            ));

        $this->assertNull($this->gaps->findById($id)['ignored_at']);
    }

    private function frontController(string $path, string $action, string $method = 'GET'): FrontController
    {
        $router = new Router();
        $router->addRoute($method, $path, DeskMappingGapController::class, $action, 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_desk_gaps_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(DeskMappingGapController::class, $this->controller);

        return $frontController;
    }

    /**
     * The RBAC boundary on the two POST routes, which only the GET route
     * had (`AGENTS.md` § Controllers: allowed at `role_min`, denied one
     * level below). Both carry `role_min: superadmin`, and both change
     * stored state, so a guard that let an admin through would matter more
     * here than on the page that merely reads.
     */
    public function testAnAdminCannotSetAValueAside(): void
    {
        AuthSession::login(2, 'admin@test.com', 'admin');
        $id = $this->gaps->findAllKeyed()['branch|nutons']['id'];

        $response = $this->frontController('/support-dashboard/correspondances/{id}/ecarter', 'ignore', 'POST')
            ->handle(new Request(
                'POST',
                '/support-dashboard/correspondances/' . $id . '/ecarter',
                [],
                ['_csrf_token' => CsrfGuard::generateToken()],
                [],
                []
            ));

        $this->assertNotSame(302, $response->getStatusCode());
        $this->assertNull(
            $this->gaps->findById($id)['ignored_at'],
            'the row must be untouched: a refused request that still wrote would be the worse failure'
        );
    }

    public function testAnAdminCannotBringAValueBack(): void
    {
        $id = $this->gaps->findAllKeyed()['branch|nutons']['id'];
        $this->gaps->setIgnored($id, true);

        AuthSession::login(2, 'admin@test.com', 'admin');

        $response = $this->frontController('/support-dashboard/correspondances/{id}/reactiver', 'restore', 'POST')
            ->handle(new Request(
                'POST',
                '/support-dashboard/correspondances/' . $id . '/reactiver',
                [],
                ['_csrf_token' => CsrfGuard::generateToken()],
                [],
                []
            ));

        $this->assertNotSame(302, $response->getStatusCode());
        $this->assertNotNull(
            $this->gaps->findById($id)['ignored_at'],
            'the value must still be set aside'
        );
    }

    /**
     * And the route itself, which no test reached: « réactiver » was only
     * ever exercised by calling the repository directly, so the router,
     * the guard and the controller method were all untested on the one
     * path a reader uses.
     */
    public function testTheSuperadminBringsAValueBackThroughTheRoute(): void
    {
        $id = $this->gaps->findAllKeyed()['branch|nutons']['id'];
        $this->gaps->setIgnored($id, true);

        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController('/support-dashboard/correspondances/{id}/reactiver', 'restore', 'POST')
            ->handle(new Request(
                'POST',
                '/support-dashboard/correspondances/' . $id . '/reactiver',
                [],
                ['_csrf_token' => CsrfGuard::generateToken()],
                [],
                []
            ));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->gaps->findById($id)['ignored_at']);

        // Back in the default view, which is the point of bringing it back.
        $shown = $this->controller->index(
            new Request('GET', '/support-dashboard/correspondances', [], [], [], []),
            []
        )->getBody();
        $this->assertStringContainsString('Nutons', $shown);
    }
}
