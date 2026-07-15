<?php

declare(strict_types=1);

namespace Tests\Modules\MemberStats\Controller;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\MemberStats\Controller\MemberStatsController;
use Modules\MemberStats\Repository\MemberStatsRepository;
use Modules\MemberStats\Service\MemberStatsService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * RBAC boundary for /chiefs/stats (Espace des chefs, role_min chief):
 * chief → 200 (renders), intendant → 403, identified → 403.
 * The real controller + real module template are exercised for the chief case.
 */
class MemberStatsControllerTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $coreTemplates = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/member_stats/views';
        $loader = new FilesystemLoader($coreTemplates);
        $loader->addPath($moduleViews, 'member_stats');

        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_email', 'chief@test.be');
        $this->twig->addGlobal('current_user_role', 'chief');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'n');
        $this->twig->addFunction(new TwigFunction('csrf_field', fn() => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn() => 't'));
        $this->twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $configFile = sys_get_temp_dir() . '/test_member_stats_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        // Mirrors the module.json route (role_min: chief).
        $router->addRoute('GET', '/chiefs/stats', MemberStatsController::class, 'index', 'chief');

        $service = new MemberStatsService($this->stubRepository());
        $resolver = $this->stubResolver();

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(
            MemberStatsController::class,
            new MemberStatsController($this->twig, $service, $resolver)
        );

        return $fc;
    }

    private function stubRepository(): MemberStatsRepository
    {
        return new class extends MemberStatsRepository {
            public function __construct()
            {
                // No DB in this test.
            }

            public function getMemberBranchData(int $scoutYearId): array
            {
                return [];
            }
        };
    }

    private function stubResolver(): ScoutYearResolver
    {
        return new class extends ScoutYearResolver {
            public function __construct()
            {
                // No settings/DB in this test.
            }

            public function getEffectiveYear(?int $sessionOverrideId, Role $role): EffectiveScoutYear
            {
                return new EffectiveScoutYear(1, '2025-2026', null);
            }
        };
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    public function testChiefGetsPage(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->buildFrontController()->handle(new Request('GET', '/chiefs/stats', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Statistiques des membres', $response->getBody());
    }

    public function testIntendantIsDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $response = $this->buildFrontController()->handle(new Request('GET', '/chiefs/stats', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testIdentifiedIsDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'member@test.be', 'identified');

        $response = $this->buildFrontController()->handle(new Request('GET', '/chiefs/stats', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }
}
