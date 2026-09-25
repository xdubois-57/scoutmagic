<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\TwigFactory;
use Modules\Covoiturage\Controller\CarpoolController;
use Modules\Covoiturage\Controller\CarpoolOrganizerController;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolBoard;
use Modules\Covoiturage\Service\CarpoolService;
use Modules\Covoiturage\Service\CarpoolViewerResolver;
use Modules\Covoiturage\Service\OfferService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;
use Tests\Modules\Covoiturage\FakeCalendar;
use Twig\Environment;

/**
 * Every route of the module, through the REAL Router/RbacGuard pipeline, at
 * the role_min module.json declares for it — allowed at that floor, refused
 * one level below. The GET pages are rendered for real at the floor, so a
 * template that breaks fails here too.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CovoiturageRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private CarpoolController $members;
    private CarpoolOrganizerController $organizer;
    private int $accountId;
    private int $carpoolId;
    private int $offerId;
    private int $requestId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $encryption = H::encryption();

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('parent@test.be', 'user_accounts.email'), $encryption->blindIndex('parent@test.be', 'email')]);
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 1)");

        $this->carpoolId = H::carpool($this->pdo, 10, 11);
        // Its creator: past the route's floor, editing is narrowed further
        // to whoever may organise THIS carpool (Service\CarpoolViewer).
        $this->pdo->exec('UPDATE carpools SET created_by_user_account_id = ' . $this->accountId);
        $this->offerId = H::offer($this->pdo, $this->carpoolId, $this->accountId);
        $this->requestId = H::request($this->pdo, $this->offerId, 99, ['Tom Leroy']);

        $root = dirname(__DIR__, 4);
        $twig = TwigFactory::create($root . '/core/View/templates', false, ['covoiturage' => $root . '/modules/covoiturage/views']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        // Registered by the composition root in production.
        $twig->addFunction(new \Twig\TwigFunction('param', static fn(string $key): string => 'Test Unit'));
        $this->twig = $twig;

        $settings = new SettingService(new SettingRepository($this->pdo));
        $carpools = new CarpoolRepository($this->pdo);
        $offers = new OfferRepository($this->pdo, $encryption);
        $requests = new SeatRequestRepository($this->pdo, $encryption);
        $sections = new SectionService(Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo));
        $board = new CarpoolBoard($carpools, $offers, $requests, $settings);
        $viewers = new CarpoolViewerResolver(new ScoutYearResolver(
            new ScoutYearService($this->pdo),
            $settings,
            new MemberYearRepository($this->pdo)
        ));

        $this->members = new CarpoolController(
            $twig,
            $carpools,
            $offers,
            $requests,
            $board,
            new OfferService($offers, $requests, $this->pdo),
            $viewers
        );
        $this->organizer = new CarpoolOrganizerController(
            $twig,
            $carpools,
            new CarpoolService($carpools, $offers, $sections, new FakeCalendar([])),
            $board,
            $sections,
            $viewers
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * Every route module.json declares, with the role one level below its
     * floor.
     *
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function routes(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/covoiturage/module.json'),
            true
        );
        $below = ['identified' => 'public', 'chief' => 'intendant'];
        $cases = [];
        foreach ($manifest['routes'] as $route) {
            $cases[$route['method'] . ' ' . $route['path']] = [
                $route['method'],
                $route['path'],
                $route['action'],
                $route['role_min'],
                $below[$route['role_min']],
            ];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testTheDeclaredFloorGetsThrough(string $method, string $path, string $action, string $floor, string $below): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', $floor);

        $response = $this->frontController($method, $path, $action, $floor)
            ->handle(new Request($method, $this->resolve($path), [], [], [], []));

        $this->assertNotSame(403, $response->getStatusCode(), "{$floor} refused on {$method} {$path}");
        if ($method === 'GET') {
            $this->assertLessThan(400, $response->getStatusCode(), "{$method} {$path}: " . substr($response->getBody(), 0, 400));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testOneLevelBelowIsRefused(string $method, string $path, string $action, string $floor, string $below): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', $below);

        $response = $this->frontController($method, $path, $action, $floor)
            ->handle(new Request($method, $this->resolve($path), [], [], [], []));

        $this->assertContains($response->getStatusCode(), [302, 403], "{$below} reached {$method} {$path}");
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function testThePagesSayWhatTheMaquetteSays(): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', Role::IDENTIFIED->value);

        $list = $this->frontController('GET', '/covoiturage', 'index', 'identified')
            ->handle(new Request('GET', '/covoiturage', [], [], [], []))->getBody();
        $this->assertStringContainsString('Covoiturages passés', $list);
        $this->assertStringContainsString('Trajet libre', $list);

        $page = $this->frontController('GET', '/covoiturage/{id}', 'show', 'identified')
            ->handle(new Request('GET', '/covoiturage/' . $this->carpoolId, [], [], [], []))->getBody();
        $this->assertStringContainsString('Sophie Martin (vous)', $page);
        $this->assertStringContainsString('Demandes (1)', $page);
        $this->assertStringContainsString('Accepter la place', $page);
        // Pending: no phone for the driver yet.
        $this->assertStringNotContainsString('0495', $page);

        $form = $this->frontController('GET', '/covoiturage/{id}/proposer', 'offerForm', 'identified')
            ->handle(new Request('GET', '/covoiturage/' . $this->carpoolId . '/proposer', [], [], [], []))->getBody();
        $this->assertStringContainsString('Un point de rendez-vous, pas votre adresse', $form);
        $this->assertStringContainsString('Fixé par le covoiturage', $form);
    }

    public function testAChiefOfAnotherSectionCannotEditACarpoolTheyDoNotOrganize(): void
    {
        // Past the route's floor, the controller narrows to whoever may
        // organise THIS carpool: its creator, the staff it concerns, the
        // Staff d'U.
        $this->pdo->exec('UPDATE carpools SET created_by_user_account_id = NULL');
        AuthSession::login($this->accountId, 'parent@test.be', Role::CHIEF->value);

        $response = $this->frontController('GET', '/covoiturage/organiser/{id}/modifier', 'edit', 'chief')
            ->handle(new Request('GET', '/covoiturage/organiser/' . $this->carpoolId . '/modifier', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());

        AuthSession::login($this->accountId, 'parent@test.be', Role::ADMIN->value);
        $response = $this->frontController('GET', '/covoiturage/organiser/{id}/modifier', 'edit', 'chief')
            ->handle(new Request('GET', '/covoiturage/organiser/' . $this->carpoolId . '/modifier', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    private function resolve(string $path): string
    {
        $id = match (true) {
            str_starts_with($path, '/covoiturage/voitures/') => $this->offerId,
            str_starts_with($path, '/covoiturage/demandes/') => $this->requestId,
            default => $this->carpoolId,
        };

        return str_replace('{id}', (string) $id, $path);
    }

    private function frontController(string $method, string $path, string $action, string $floor): FrontController
    {
        $class = str_starts_with($path, '/covoiturage/organiser') ? CarpoolOrganizerController::class : CarpoolController::class;
        $router = new Router();
        $router->addRoute($method, $path, $class, $action, $floor);

        $configFile = sys_get_temp_dir() . '/test_covoiturage_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $front = new FrontController($router, $this->twig, new AppConfig($configFile));
        $front->registerController($class, $class === CarpoolController::class ? $this->members : $this->organizer);

        return $front;
    }
}
