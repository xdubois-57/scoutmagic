<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Controller;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Controller\InboundMailConfigController;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Service\MailboxAdminService;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailProviderRecognizer;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;
use Tests\TestTwig;

/**
 * Every route of `Configuration > Courrier entrant`, through the REAL
 * Router/RbacGuard pipeline, at the role_min module.json declares for it —
 * allowed at that floor (superadmin), refused one level below (the Staff
 * d'U's admin). The GET pages are rendered for real at the floor, so a
 * template that breaks fails here too.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class InboundMailConfigRbacTest extends TestCase
{
    private InboundMailConfigController $controller;
    private int $mailboxId;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $mailboxes = new InboundMailboxRepository($pdo, $encryption);

        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::IMAP, new FakeMailboxClient());
        $registry = new MessageConsumerRegistry();
        $registry->register(new FakeMessageConsumer('rental'));

        $twig = TestTwig::create(['inbound_mail']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', true);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new InboundMailConfigController(
            $twig,
            new MailboxAdminService($mailboxes, $factory, new MailboxErrorFormatter()),
            new JournalService(new JournalRepository($pdo)),
            $registry,
            null,
            new MailProviderRecognizer(static fn(string $domain): array => [])
        );

        $this->mailboxId = $mailboxes->create(
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            'contact@unite.be',
            'secret',
            [],
            true
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
     * Every configuration route module.json declares.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function routes(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/inbound_mail/module.json'),
            true
        );

        $cases = [];
        foreach ($manifest['routes'] as $route) {
            if (!str_starts_with($route['path'], '/config/')) {
                continue;
            }
            $cases[$route['method'] . ' ' . $route['path']] = [
                $route['method'],
                $route['path'],
                $route['action'],
                $route['role_min'],
            ];
        }

        return $cases;
    }

    /** The routes this lot added or changed are among those checked. */
    public function testTheRoutesOfTheNewScreenAreCovered(): void
    {
        $covered = array_keys(self::routes());

        foreach ([
            'GET /config/courrier-entrant',
            'GET /config/courrier-entrant/boites/nouvelle',
            'GET /config/courrier-entrant/boites/{id}/modification',
            'POST /config/courrier-entrant/boites',
            'POST /config/courrier-entrant/test-connexion',
            'POST /config/courrier-entrant/fournisseur',
        ] as $route) {
            $this->assertContains($route, $covered);
        }
        $this->assertNotContains('POST /config/courrier-entrant/rafraichir', $covered);
        $this->assertNotContains('POST /config/courrier-entrant/boites/{id}/test', $covered);
    }

    #[DataProvider('routes')]
    public function testTheDeclaredFloorGetsThrough(string $method, string $path, string $action, string $floor): void
    {
        AuthSession::login(1, 'superadmin@test.be', $floor);

        $response = $this->frontController($method, $path, $action, $floor)
            ->handle(new Request($method, $this->resolve($path), [], [], [], []));

        $this->assertNotSame(403, $response->getStatusCode(), "{$floor} refused on {$method} {$path}");
        if ($method === 'GET') {
            $this->assertSame(200, $response->getStatusCode(), "{$method} {$path}");
        }
    }

    #[DataProvider('routes')]
    public function testOneLevelBelowIsRefused(string $method, string $path, string $action, string $floor): void
    {
        $this->assertSame('superadmin', $floor, 'every configuration route is superadmin-only');
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->frontController($method, $path, $action, $floor)
            ->handle(new Request($method, $this->resolve($path), [], [], [], []));

        // An authenticated visitor below the floor gets the guard's 403 on
        // every route, never a redirect: only an anonymous one is sent to
        // the login page (Core\Security\RbacGuard::enforce()).
        $this->assertSame(403, $response->getStatusCode(), "admin reached {$method} {$path}");
    }

    private function resolve(string $path): string
    {
        return str_replace('{id}', (string) $this->mailboxId, $path);
    }

    private function frontController(string $method, string $path, string $action, string $floor): FrontController
    {
        $router = new Router();
        $router->addRoute($method, $path, InboundMailConfigController::class, $action, $floor);

        $configFile = sys_get_temp_dir() . '/test_inbound_mail_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $front = new FrontController($router, TestTwig::create(['inbound_mail']), new AppConfig($configFile));
        $front->registerController(InboundMailConfigController::class, $this->controller);

        return $front;
    }
}
