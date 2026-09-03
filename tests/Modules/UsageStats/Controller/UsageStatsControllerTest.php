<?php

declare(strict_types=1);

namespace Tests\Modules\UsageStats\Controller;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Module\ModuleInfo;
use Core\Module\ModuleManager;
use Core\Module\ModuleManifest;
use Core\Security\AuthSession;
use Modules\UsageStats\Controller\UsageStatsController;
use Modules\UsageStats\Repository\AccountActivityRepository;
use Modules\UsageStats\Repository\PageViewRepository;
use Modules\UsageStats\Service\UsageStatsService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\UsageStats\UsageStatsTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The RBAC boundary of the three screens — `superadmin` renders, `admin`
 * (one level below) is refused — and the real templates are exercised for
 * the allowed case, so a Twig error in one of them fails here rather than
 * on a live page.
 *
 * The floor is `superadmin` rather than `admin` because that is the floor
 * of every other page of the Configuration menu; the screens carry an
 * installation's own measurements, not a unit's.
 */
class UsageStatsControllerTest extends TestCase
{
    private const PATHS = ['/config/usage', '/config/usage/modules', '/config/usage/pages'];

    private Environment $twig;
    private AppConfig $config;
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $root = dirname(__DIR__, 4);
        $loader = new FilesystemLoader($root . '/core/View/templates');
        $loader->addPath($root . '/modules/usage_stats/views', 'usage_stats');

        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addFunction(new TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_email', 'super@test.be');
        $this->twig->addGlobal('current_user_role', 'superadmin');
        $this->twig->addGlobal('current_path', '/config/usage');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'n');
        $this->twig->addFunction(new TwigFunction('csrf_field', fn () => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn () => 't'));
        $this->twig->addFunction(new TwigFunction('get_flash', fn () => null));
        $this->twig->addFunction(new TwigFunction('file_url', fn () => ''));

        $configFile = sys_get_temp_dir() . '/test_usage_stats_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        UsageStatsTestHelper::createTables($this->pdo);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paths')]
    public function testASuperadminGetsThePage(string $path): void
    {
        $this->seed();
        AuthSession::login(1, 'super@test.be', 'superadmin');
        $this->twig->addGlobal('current_path', $path);

        $response = $this->buildFrontController()->handle(new Request('GET', $path, [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Fréquentation', $response->getBody());
    }

    /**
     * One level below the floor. Every page of the Configuration menu
     * answers the same way, and this module is no exception.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('paths')]
    public function testAnAdminIsRefused(string $path): void
    {
        AuthSession::login(2, 'admin@test.be', 'admin');

        $response = $this->buildFrontController()->handle(new Request('GET', $path, [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        $cases = [];
        foreach (self::PATHS as $path) {
            $cases[$path] = [$path];
        }

        return $cases;
    }

    /**
     * The route pattern is printed as such — `{id}` and all — because
     * showing it is the clearest possible statement that no identifier is
     * kept (ARCHITECTURE.md §8.93).
     */
    public function testThePagesScreenPrintsThePatternRatherThanAnIdentifier(): void
    {
        (new PageViewRepository($this->pdo))->increment('2026-08', '/members/{id}', 'core', 'identified');
        AuthSession::login(1, 'super@test.be', 'superadmin');
        $this->twig->addGlobal('current_path', '/config/usage/pages');

        $body = $this->buildFrontController()
            ->handle(new Request('GET', '/config/usage/pages', ['month' => '2026-08'], [], [], []))
            ->getBody();

        $this->assertStringContainsString('/members/{id}', $body);
    }

    public function testTheAudienceFilterNarrowsTheList(): void
    {
        $pageViews = new PageViewRepository($this->pdo);
        $pageViews->increment('2026-08', '/calendar', 'calendar', 'identified');
        $pageViews->increment('2026-08', '/news', 'news', 'anonymous');
        AuthSession::login(1, 'super@test.be', 'superadmin');
        $this->twig->addGlobal('current_path', '/config/usage/pages');

        $body = $this->buildFrontController()->handle(
            new Request('GET', '/config/usage/pages', ['month' => '2026-08', 'audience' => 'anonymous'], [], [], [])
        )->getBody();

        $this->assertStringContainsString('/news', $body);
        $this->assertStringNotContainsString('/calendar ·', $body);
    }

    private function seed(): void
    {
        $pageViews = new PageViewRepository($this->pdo);
        $pageViews->increment(date('Y-m'), '/calendar', 'calendar', 'identified');
        $pageViews->increment(date('Y-m'), '/', 'core', 'anonymous');
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        // Mirrors module.json — three GET routes, all superadmin.
        $router->addRoute('GET', '/config/usage', UsageStatsController::class, 'overview', 'superadmin', ['label' => 'Fréquentation', 'parents' => []]);
        $router->addRoute('GET', '/config/usage/modules', UsageStatsController::class, 'modules', 'superadmin', ['label' => 'Modules', 'parents' => []]);
        $router->addRoute('GET', '/config/usage/pages', UsageStatsController::class, 'pages', 'superadmin', ['label' => 'Pages', 'parents' => []]);
        $router->addRoute('GET', '/calendar', 'X', 'index', 'identified', ['label' => 'Calendrier', 'parents' => []]);
        $router->addRoute('GET', '/news', 'X', 'index', 'public', ['label' => 'Actualités', 'parents' => []]);

        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(
            UsageStatsController::class,
            new UsageStatsController(
                $this->twig,
                new UsageStatsService(
                    new PageViewRepository($this->pdo),
                    new AccountActivityRepository($this->pdo),
                    self::moduleManager(),
                    $router
                )
            )
        );

        return $frontController;
    }

    private static function moduleManager(): ModuleManager
    {
        return new class () extends ModuleManager {
            public function __construct()
            {
            }

            /** @return ModuleInfo[] */
            public function discoverModules(): array
            {
                return [
                    new ModuleInfo(
                        new ModuleManifest('calendar', 'Calendrier', '1.0.0', [], [], [], [], []),
                        true,
                        '1.0.0',
                        true,
                        null
                    ),
                    new ModuleInfo(
                        new ModuleManifest('news', 'Actualités', '1.0.0', [], [], [], [], []),
                        true,
                        '1.0.0',
                        true,
                        null
                    ),
                ];
            }
        };
    }
}
