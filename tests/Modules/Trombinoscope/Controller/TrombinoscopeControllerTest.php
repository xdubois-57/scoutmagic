<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Controller;

use Core\Config\AppConfig;
use Core\Config\SettingService;
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
 * RBAC boundary for /trombinoscope (Espace membres, role_min identified):
 * identified -> 200 (renders), public -> 403.
 */
class TrombinoscopeControllerTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;
    private bool $showContacts = true;

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
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_email', 'member@test.be');
        $this->twig->addGlobal('current_user_role', 'identified');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('current_path', '/trombinoscope');
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

        $this->showContacts = true;
        $this->sections = [
            ['id' => 1, 'desk_code' => 'ECL01', 'name' => 'Éclaireurs 1', 'email' => 'eclaireurs1@test.be', 'age_branch_id' => 1, 'branch_name' => 'Éclaireurs', 'branch_sort_order' => 30],
        ];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/trombinoscope', TrombinoscopeController::class, 'index', 'identified', [
            'label' => 'Trombinoscope',
            'parents' => ['Espace membres'],
        ]);

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

        $trombinoscopeService = new class($this->staffProfile()) extends TrombinoscopeService {
            public function __construct(private MemberProfile $lead)
            {
            }

            public function getSectionStaff(int $sectionId, int $scoutYearId): array
            {
                return ['lead' => $this->lead, 'staff' => []];
            }
        };

        $settingService = new class($this->showContacts) extends SettingService {
            public function __construct(private bool $showContacts)
            {
            }

            public function get(string $key, ?string $moduleId = null, mixed $default = null): mixed
            {
                return $key === TrombinoscopeController::SETTING_SHOW_CONTACTS
                    ? ($this->showContacts ? '1' : '0')
                    : $default;
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
            new TrombinoscopeController($this->twig, $sectionService, $trombinoscopeService, $resolver, $settingService)
        );

        return $fc;
    }

    /**
     * One animateur carrying both a mobile number and a personal e-mail
     * address — the two fields the module's single setting governs.
     */
    private function staffProfile(): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 10,
            memberId: 100,
            deskId: 'D100',
            firstName: 'Antonin',
            lastName: 'Grandjean',
            totem: 'Chacal',
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: '0496 88 41 20',
            email: 'antonin@test.be',
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [],
            scoutYearLabel: '2025-2026'
        );
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

    public function testBreadcrumbReflectsTheSelectedSection(): void
    {
        // The section picker changes what this page shows without changing
        // its URL — the breadcrumb's own active segment must reflect the
        // currently selected section, not just the static "Trombinoscope"
        // label.
        $this->startTestSession();
        AuthSession::login(1, 'member@test.be', 'identified');

        $response = $this->buildFrontController()->handle(
            new Request('GET', '/trombinoscope', ['section' => '1'], [], [], [])
        );

        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Trombinoscope · Éclaireurs 1\s*</',
            $response->getBody()
        );
    }

    public function testContactsAreShownWhenTheSettingIsOn(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'member@test.be', 'identified');

        $body = $this->buildFrontController()
            ->handle(new Request('GET', '/trombinoscope', [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('0496 88 41 20', $body);
        $this->assertStringContainsString('antonin@test.be', $body);
    }

    public function testContactsAreHiddenWhenTheSettingIsOffButTheSectionAddressStays(): void
    {
        // The setting governs PERSONAL data only. A section's own address is
        // organizational (design.md §2.6), survives a change of responsable,
        // and is what makes the page useful with the setting off.
        $this->showContacts = false;
        $this->startTestSession();
        AuthSession::login(1, 'member@test.be', 'identified');

        $body = $this->buildFrontController()
            ->handle(new Request('GET', '/trombinoscope', [], [], [], []))
            ->getBody();

        $this->assertStringNotContainsString('0496 88 41 20', $body);
        $this->assertStringNotContainsString('antonin@test.be', $body);
        $this->assertStringContainsString('eclaireurs1@test.be', $body);
    }

    public function testPublicIsDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(0, '', 'public');

        $response = $this->buildFrontController()->handle(new Request('GET', '/trombinoscope', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }
}
