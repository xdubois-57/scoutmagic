<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\FunctionRepository;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\Module\ModuleManifest;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\MassMail\Controller\MailingListController;
use Modules\MassMail\Repository\ListAddressRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Service\ListAddressService;
use Modules\MassMail\Service\MailingListService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Twig\Environment;

/**
 * The mailing lists page moved out of Configuration (`superadmin`) into
 * Espace chefs d'U (`admin`), and this is the boundary that move created:
 * a chef d'unité is now IN, and a section chief is still OUT.
 *
 * Driven through the REAL Router/RbacGuard pipeline, on the role_min each
 * route actually declares in `module.json` rather than on a value retyped
 * here — a floor raised or lowered in the manifest changes what this test
 * asserts, which is the point. `Tests\Modules\MassMail\ModuleManifestTest`
 * pins the declared values themselves; this pins that the guard enforces
 * them.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailingListRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private MailingListController $controller;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([
            $encryption->encrypt('cu@test.com', 'user_accounts.email'),
            $encryption->blindIndex('cu@test.com', 'email'),
        ]);
        $this->accountId = (int) $this->pdo->lastInsertId();

        $connection = Connection::withPdo($this->pdo);
        $listService = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo)),
            new FunctionRepository($this->pdo)
        );

        $this->twig = $this->createMock(Environment::class);
        $this->controller = new MailingListController(
            $this->twig,
            $listService,
            new ScoutYearResolver(
                new ScoutYearService($this->pdo),
                new SettingService(new SettingRepository($this->pdo)),
                new MemberYearRepository($this->pdo)
            ),
            new ListAddressService(
                new ListAddressRepository($this->pdo, $encryption),
                new MailingListRepository($this->pdo),
                new SettingService(new SettingRepository($this->pdo)),
                $this->createMock(\Core\Journal\JournalService::class)
            )
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
     * Every route the manifest declares under `/admin/listes-de-diffusion`,
     * read from `module.json` itself so a route added later arrives with
     * its own coverage or not at all.
     *
     * @return array<string, array{string, string, string, string}> method, path, action, role_min
     */
    public static function mailingListRouteProvider(): array
    {
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/mass_mail/module.json');

        $cases = [];
        foreach ($manifest->routes as $route) {
            if (!str_starts_with($route['path'], '/admin/listes-de-diffusion')) {
                continue;
            }
            $cases["{$route['method']} {$route['path']}"] = [
                $route['method'],
                $route['path'],
                $route['action'],
                $route['role_min'],
            ];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailingListRouteProvider')]
    public function testEveryMailingListRouteOpensAtItsDeclaredFloor(
        string $method,
        string $path,
        string $action,
        string $roleMin
    ): void {
        $this->assertSame('admin', $roleMin, "Route {$path} should open to a chef d'unité");

        AuthSession::login($this->accountId, 'cu@test.com', $roleMin);

        $response = $this->frontController($method, $path, $action, $roleMin)
            ->handle(new Request($method, str_replace('{id}', '1', $path), [], [], [], []));

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            "Expected {$roleMin} to get past the guard on {$method} {$path}"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailingListRouteProvider')]
    public function testEveryMailingListRouteRefusesTheRoleBelowItsFloor(
        string $method,
        string $path,
        string $action,
        string $roleMin
    ): void {
        // One rung below `admin` is `chief` — a section chief, who animates
        // a section but does not decide who the unit writes to.
        AuthSession::login($this->accountId, 'chef@test.com', 'chief');

        $response = $this->frontController($method, $path, $action, $roleMin)
            ->handle(new Request($method, str_replace('{id}', '1', $path), [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The page left Configuration entirely — its old address is not a
     * second door into the same controller.
     */
    public function testTheOldConfigurationPathIsGone(): void
    {
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/mass_mail/module.json');

        foreach ($manifest->routes as $route) {
            $this->assertStringNotContainsString('/config/mass-mail', $route['path']);
        }
    }

    private function frontController(string $method, string $path, string $action, string $roleMin): FrontController
    {
        $router = new Router();
        $router->addRoute($method, $path, MailingListController::class, $action, $roleMin);

        $configFile = sys_get_temp_dir() . '/test_mass_mail_lists_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(MailingListController::class, $this->controller);

        return $frontController;
    }
}
