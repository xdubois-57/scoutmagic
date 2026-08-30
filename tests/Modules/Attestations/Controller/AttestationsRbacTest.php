<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Security\AuthSession;
use Modules\Attestations\Controller\AttestationsController;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Value\AttestationCategory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The RBAC boundary of every attestations route, exercised through the real
 * Router/RbacGuard/FrontController stack and the real templates — so a
 * template that fails to compile fails here rather than in production.
 *
 * Every route is `role_min: admin`, the floor of the Espace chefs d'U menu.
 * A `chief`, one level below, is refused: an animateur de section has no
 * business opening a file holding the whole unit's nominative paperwork.
 */
#[Group('database')]
class AttestationsRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        AttestationsTestHelper::createTables($this->pdo);
        $this->scoutYearId = AttestationsTestHelper::createScoutYear($this->pdo);
        $this->twig = $this->buildTwig();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    public function testTheUnitStaffReachesThePage(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $response = $this->frontController()->handle(new Request('GET', '/admin/attestations', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testASectionChiefIsRefused(): void
    {
        AuthSession::login(2, 'animateur@test.be', 'chief');

        $response = $this->frontController()->handle(new Request('GET', '/admin/attestations', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAnAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $response = $this->frontController()->handle(new Request('GET', '/admin/attestations', [], [], [], []));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('/login', (string) $response->getHeaders()['Location']);
    }

    /**
     * A unit that has deposited nothing sees a state, not a rendering
     * defect — the shared empty_state partial (design.md §7.7), and no
     * creation action, because depositing is not offered by this route yet.
     */
    public function testAUnitWithNoBatchSeesAnEmptyState(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $body = (string) $this->frontController()
            ->handle(new Request('GET', '/admin/attestations', [], [], [], []))
            ->getBody();

        // The message travels through the partial's `{{ message }}`, so
        // Twig escapes the apostrophe — asserting the raw form would fail
        // on correctly-escaped output.
        $this->assertStringContainsString('Aucun lot n&#039;a encore été déposé.', $body);
        $this->assertStringContainsString('empty-state', $body);
    }

    public function testADepositedBatchIsListedWithItsYearCategoryAndState(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        (new BatchRepository(Connection::withPdo($this->pdo)))->create(
            $this->scoutYearId,
            AttestationCategory::Tax,
            'Attestation fiscale 2025',
            10,
            2,
            5,
            1
        );

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->frontController()
            ->handle(new Request('GET', '/admin/attestations', [], [], [], []))
            ->getBody());

        $this->assertStringContainsString('Attestation fiscale 2025', $body);
        $this->assertStringContainsString('Attestation fiscale —', $body);
        $this->assertStringContainsString('2025-2026', $body);
        $this->assertStringContainsString('5 attestations', $body);
        $this->assertStringContainsString('À vérifier', $body);
    }

    /**
     * The page must never name a member. Nothing it reads carries one — a
     * batch holds counts and a label — and this pins that the day a later
     * iteration is tempted to add « et 2 écartées : Dupont, Martin ».
     */
    public function testThePageNamesNoMember(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        AttestationsTestHelper::createMember($this->pdo, $this->scoutYearId, 'Margaux', 'Vandenbrande');

        (new BatchRepository(Connection::withPdo($this->pdo)))->create(
            $this->scoutYearId,
            AttestationCategory::Tax,
            'Attestation fiscale 2025',
            2,
            2,
            1,
            1
        );

        $body = (string) $this->frontController()
            ->handle(new Request('GET', '/admin/attestations', [], [], [], []))
            ->getBody();

        $this->assertStringNotContainsString('Vandenbrande', $body);
        $this->assertStringNotContainsString('Margaux', $body);
    }

    private function frontController(): FrontController
    {
        $router = new Router();
        $router->addRoute(
            'GET',
            '/admin/attestations',
            AttestationsController::class,
            'index',
            'admin',
            ['label' => 'Attestations', 'parents' => ["Espace chefs d'U"]]
        );

        $configFile = sys_get_temp_dir() . '/test_attestations_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(
            AttestationsController::class,
            new AttestationsController(
                $this->twig,
                new BatchRepository(Connection::withPdo($this->pdo)),
                new ScoutYearService($this->pdo)
            )
        );

        return $frontController;
    }

    private function buildTwig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates');
        $loader->addPath(dirname(__DIR__, 4) . '/modules/attestations/views', 'attestations');

        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('asset', static fn(string $path): string => $path));
        $twig->addFilter(new TwigFilter(
            'date_fr',
            static fn($d) => $d === null || $d === ''
                ? ''
                : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')
        ));
        $twig->addFilter(new TwigFilter(
            'datetime_fr',
            static fn($d) => $d === null || $d === ''
                ? ''
                : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')
        ));

        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@test.be');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', static fn(): string => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', static fn(): string => 'test'));
        $twig->addFunction(new TwigFunction('get_flash', static fn() => null));
        $twig->addFunction(new TwigFunction('file_url', static fn(): string => ''));

        return $twig;
    }
}
