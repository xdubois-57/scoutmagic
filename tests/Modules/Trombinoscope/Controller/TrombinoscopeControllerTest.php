<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Controller;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\TextNormalizerExtension;
use Modules\Trombinoscope\Controller\TrombinoscopeController;
use Modules\Trombinoscope\Service\TrombinoscopeService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * RBAC boundary for /trombinoscope (Espace des animés, role_min identified):
 * identified -> 200 (renders), public -> 403.
 */
class TrombinoscopeControllerTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    /** @var array<int, array{id: int, desk_code: string, name: ?string, email: ?string, age_branch_id: int, branch_name: string, branch_sort_order: int}> */
    private array $sections = [];

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $coreTemplates = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/trombinoscope/views';
        $loader = new FilesystemLoader($coreTemplates);
        $loader->addPath($moduleViews, 'trombinoscope');

        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_email', 'member@test.be');
        $this->twig->addGlobal('current_user_role', 'identified');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'n');
        $this->twig->addGlobal('effective_scout_year_id', 1);
        $this->twig->addGlobal('_member_photo_service', null);
        $this->twig->addFunction(new TwigFunction('csrf_field', fn() => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn() => 't'));
        $this->twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $this->twig->addFunction(new TwigFunction('member_photo', fn() => '<div class="member-photo-placeholder"><span class="member-photo-initials">XX</span></div>', ['is_safe' => ['html']]));
        $this->twig->addExtension(new TextNormalizerExtension());
        $this->twig->addFilter(new TwigFilter('display_name', function ($member) {
            return $member instanceof MemberProfile ? $member->getDisplayName() : (string) $member;
        }));

        $configFile = sys_get_temp_dir() . '/test_trombinoscope_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);

        $this->sections = [
            ['id' => 1, 'desk_code' => 'ECL01', 'name' => 'Éclaireurs 1', 'email' => null, 'age_branch_id' => 1, 'branch_name' => 'Éclaireurs', 'branch_sort_order' => 30],
        ];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/trombinoscope', TrombinoscopeController::class, 'index', 'identified');

        $sections = $this->sections;
        $sectionService = new class($sections) extends SectionService {
            public function __construct(private array $sections)
            {
            }

            public function getAllWithBranches(bool $includeHidden = false): array
            {
                return $this->sections;
            }
        };

        $trombinoscopeService = new class extends TrombinoscopeService {
            public function __construct()
            {
            }

            public function getSectionStaff(int $sectionId, int $scoutYearId): array
            {
                return ['lead' => null, 'staff' => []];
            }
        };

        $resolver = new class extends ScoutYearResolver {
            public function __construct()
            {
            }

            public function getEffectiveYear(?int $sessionOverrideId, Role $role): EffectiveScoutYear
            {
                return new EffectiveScoutYear(1, '2025-2026', null);
            }
        };

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(
            TrombinoscopeController::class,
            new TrombinoscopeController($this->twig, $sectionService, $trombinoscopeService, $resolver)
        );

        return $fc;
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    public function testIdentifiedGetsPage(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'member@test.be', 'identified');

        $response = $this->buildFrontController()->handle(new Request('GET', '/trombinoscope', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Trombinoscope', $response->getBody());
        $this->assertStringContainsString('Éclaireurs 1', $response->getBody());
    }

    public function testPublicIsDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(0, '', 'public');

        $response = $this->buildFrontController()->handle(new Request('GET', '/trombinoscope', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }
}
