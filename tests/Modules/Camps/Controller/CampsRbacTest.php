<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Controller;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Camps\Controller\CampsChiefController;
use Modules\Camps\Controller\CampsConfigController;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\PlaceService;
use Modules\Camps\Service\SectionDescriber;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;
use Twig\Environment;

/**
 * RBAC boundary through the REAL Router/RbacGuard pipeline, against the
 * role_min each route declares in module.json.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampsRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private CampsChiefController $chiefController;
    private CampsConfigController $configController;
    private int $accountId;
    private int $placeId;
    private int $campId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('chief@test.com', 'user_accounts.email'), $encryption->blindIndex('chief@test.com', 'email')]);
        $this->accountId = (int) $this->pdo->lastInsertId();

        $places = new PlaceRepository($this->pdo);
        $camps = new CampRepository($this->pdo, $encryption);
        $this->placeId = $places->create('Domaine de Mozet', 'Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique', null);
        $this->campId = $camps->create(
            $this->placeId, Camp::STAY_GRAND_CAMP, '2028-07-12', '2028-07-19', null,
            Camp::STATUS_CONFIRMED, 245000, 65, null, 'Thomas Dupont', []
        );

        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $settings = new SettingService(new SettingRepository($this->pdo));
        $sections = new SectionService(
            Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo)
        );

        $root = dirname(__DIR__, 4);
        $twig = TwigFactory::create($root . '/core/View/templates', false, ['camps' => $root . '/modules/camps/views']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $this->twig = $twig;

        $this->chiefController = new CampsChiefController(
            $twig, $places, $camps,
            new PlaceService($places, $audit),
            new CampService($camps, $audit),
            new SectionDescriber($sections),
            $sections,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $audit,
            $settings
        );
        $this->configController = new CampsConfigController($twig, $settings);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'list' => ['/chefs/camps', 'chief', 'index', 'chief', 'intendant'],
            'new camp form' => ['/chefs/camps/nouveau', 'chief', 'create', 'chief', 'intendant'],
            'place sheet' => ['/chefs/camps/lieux/{place}', 'chief', 'showPlace', 'chief', 'intendant'],
            'place form' => ['/chefs/camps/lieux/{place}/modifier', 'chief', 'editPlace', 'chief', 'intendant'],
            'camp detail' => ['/chefs/camps/sejours/{camp}', 'chief', 'showCamp', 'chief', 'intendant'],
            'camp form' => ['/chefs/camps/sejours/{camp}/modifier', 'chief', 'editCamp', 'chief', 'intendant'],
            'configuration' => ['/config/camps', 'config', 'index', 'superadmin', 'admin'],
        ];
    }

    /**
     * @dataProvider routeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testTheDeclaredRoleGetsThrough(
        string $path,
        string $controller,
        string $action,
        string $allowed,
        string $denied
    ): void {
        AuthSession::login($this->accountId, 'allowed@test.com', $allowed);

        $response = $this->frontController($path, $controller, $action, $allowed)
            ->handle(new Request('GET', $this->resolve($path), [], [], [], []));

        $this->assertNotSame(403, $response->getStatusCode(), "role {$allowed} refused on {$path}");
    }

    /**
     * @dataProvider routeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testOneLevelBelowIsRefusedByTheGuard(
        string $path,
        string $controller,
        string $action,
        string $allowed,
        string $denied
    ): void {
        AuthSession::login($this->accountId, 'denied@test.com', $denied);

        $response = $this->frontController($path, $controller, $action, $allowed)
            ->handle(new Request('GET', $this->resolve($path), [], [], [], []));

        $this->assertSame(403, $response->getStatusCode(), "role {$denied} reached {$path}");
    }

    public function testAnAnonymousVisitorReachesNothing(): void
    {
        $response = $this->frontController('/chefs/camps', 'chief', 'index', 'chief')
            ->handle(new Request('GET', '/chefs/camps', [], [], [], []));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    /**
     * A camp id that does not exist must answer 404 rather than render an
     * empty page — the ids here are sequential, and a blank camp sheet for
     * every number a visitor tries reads as "this exists but is empty".
     */
    public function testAnUnknownCampOrPlaceIsNotFound(): void
    {
        AuthSession::login($this->accountId, 'chief@test.com', 'chief');

        $camp = $this->frontController('/chefs/camps/sejours/{camp}', 'chief', 'showCamp', 'chief')
            ->handle(new Request('GET', '/chefs/camps/sejours/999999', [], [], [], []));
        $place = $this->frontController('/chefs/camps/lieux/{place}', 'chief', 'showPlace', 'chief')
            ->handle(new Request('GET', '/chefs/camps/lieux/999999', [], [], [], []));

        $this->assertSame(404, $camp->getStatusCode());
        $this->assertSame(404, $place->getStatusCode());
    }

    private function resolve(string $path): string
    {
        return str_replace(
            ['{place}', '{camp}'],
            [(string) $this->placeId, (string) $this->campId],
            $path
        );
    }

    private function frontController(string $path, string $controller, string $action, string $roleMin): FrontController
    {
        $class = $controller === 'config' ? CampsConfigController::class : CampsChiefController::class;
        $routePath = str_replace(['{place}', '{camp}'], ['{id}', '{id}'], $path);

        $router = new Router();
        $router->addRoute('GET', $routePath, $class, $action, $roleMin);

        $configFile = sys_get_temp_dir() . '/test_camps_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $fc = new FrontController($router, $this->twig, new AppConfig($configFile));
        $fc->registerController(
            $class,
            $controller === 'config' ? $this->configController : $this->chiefController
        );

        return $fc;
    }
}
