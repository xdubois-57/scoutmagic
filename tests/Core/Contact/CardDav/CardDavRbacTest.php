<?php

declare(strict_types=1);

namespace Tests\Core\Contact\CardDav;

use Core\Config\AppConfig;
use Core\Contact\CardDav\AddressBookEntry;
use Core\Contact\CardDav\AddressBookService;
use Core\Http\Controller\AbstractController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The two boundaries the CardDAV server adds, which are deliberately not
 * the same boundary.
 *
 * **The protocol routes are `role_min: public`**, the second deliberate
 * exception to `SECURITY.md` §4. What that buys is that the RBAC guard
 * lets the request through to a controller that then demands a device
 * credential — so what has to be asserted here is that the guard really
 * does let it through, including for an anonymous caller: a `public`
 * floor that quietly redirected to `/login` would turn every client's
 * sync into an HTML page it cannot read, and the 401 that tells it to
 * offer a password would never arrive.
 *
 * **The probe is not part of that exception.** It is `admin`, session
 * authenticated like the page whose button calls it, and this file is
 * where that difference is pinned: the two live side by side in
 * `public/index.php`, and the next person to add a route there will copy
 * whichever neighbour they read first.
 */
class CardDavRbacTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates'),
            ['cache' => false, 'autoescape' => 'html']
        );
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn(string $path): string => $path));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
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
        // The same floors public/index.php declares.
        $router->addRoute('GET', '/.well-known/carddav', CardDavStubController::class, 'index', 'public');
        $router->addRoute('PROPFIND', '/carddav/', CardDavStubController::class, 'index', 'public');
        $router->addRoute('PROPFIND', '/carddav/staff/', CardDavStubController::class, 'index', 'public');
        $router->addRoute('REPORT', '/carddav/staff/', CardDavStubController::class, 'index', 'public');
        $router->addRoute('OPTIONS', '/carddav/staff/', CardDavStubController::class, 'index', 'public');
        $router->addRoute('GET', '/carddav/staff/{member_id}.vcf', CardDavStubController::class, 'index', 'public');
        $router->addRoute('PUT', '/carddav/staff/{member_id}.vcf', CardDavStubController::class, 'index', 'public');
        $router->addRoute('DELETE', '/carddav/staff/{member_id}.vcf', CardDavStubController::class, 'index', 'public');
        $router->addRoute('GET', '/api/carddav/probe', CardDavStubController::class, 'index', 'admin');
        $router->addRoute('PROPFIND', '/api/carddav/probe', CardDavStubController::class, 'index', 'admin');
        $router->addRoute('REPORT', '/api/carddav/probe', CardDavStubController::class, 'index', 'admin');

        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(CardDavStubController::class, new CardDavStubController($this->twig));

        return $frontController;
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    private function statusFor(string $method, string $path, ?string $role): int
    {
        $this->startTestSession();
        if ($role !== null) {
            AuthSession::login(1, $role . '@test.com', $role);
        }

        return $this->buildFrontController()->handle(new Request($method, $path, [], [], [], []))->getStatusCode();
    }

    /**
     * The guard lets an anonymous caller reach the controller — which is
     * the whole point of the `public` floor here. A redirect to `/login`
     * instead would hand a CardDAV client an HTML page where it expects
     * a 401 naming a scheme, and no client recovers from that.
     *
     * @dataProvider protocolRoutes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('protocolRoutes')]
    public function testTheProtocolRoutesReachTheControllerWithNoSession(string $method, string $path): void
    {
        $this->assertSame(200, $this->statusFor($method, $path, null));
    }

    /**
     * And they behave identically for a signed-in browser: nothing here
     * reads a session, so a role changes nothing about who gets through
     * the guard. What decides is the device credential, one layer in.
     *
     * @dataProvider protocolRoutes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('protocolRoutes')]
    public function testASessionChangesNothingOnTheProtocolRoutes(string $method, string $path): void
    {
        foreach (['identified', 'chief', 'admin', 'superadmin'] as $role) {
            $this->assertSame(200, $this->statusFor($method, $path, $role), $role);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function protocolRoutes(): array
    {
        return [
            'discovery' => ['GET', '/.well-known/carddav'],
            'root propfind' => ['PROPFIND', '/carddav/'],
            'collection propfind' => ['PROPFIND', '/carddav/staff/'],
            'collection report' => ['REPORT', '/carddav/staff/'],
            'collection options' => ['OPTIONS', '/carddav/staff/'],
            'one card' => ['GET', '/carddav/staff/42.vcf'],
            'refused write' => ['PUT', '/carddav/staff/42.vcf'],
            'refused delete' => ['DELETE', '/carddav/staff/42.vcf'],
        ];
    }

    /**
     * The probe is `admin`, like the page that offers its button — it is
     * NOT part of the §4 exception, and nothing below that floor reaches
     * it.
     *
     * @dataProvider probeRoutes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('probeRoutes')]
    public function testTheProbeRequiresAdminAndRefusesOneLevelBelow(string $method): void
    {
        $this->assertSame(200, $this->statusFor($method, '/api/carddav/probe', 'admin'));
        $this->assertSame(200, $this->statusFor($method, '/api/carddav/probe', 'superadmin'));
        $this->assertSame(403, $this->statusFor($method, '/api/carddav/probe', 'chief'));
        $this->assertSame(403, $this->statusFor($method, '/api/carddav/probe', 'identified'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function probeRoutes(): array
    {
        return [
            'get' => ['GET'],
            'propfind' => ['PROPFIND'],
            'report' => ['REPORT'],
        ];
    }

    public function testAnAnonymousVisitorIsSentToTheLoginPageOnTheProbe(): void
    {
        $this->startTestSession();
        $response = $this->buildFrontController()->handle(
            new Request('PROPFIND', '/api/carddav/probe', [], [], [], [])
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    /**
     * The paths in this file, in `public/index.php` and in
     * {@see AddressBookService}'s constants are three copies of one
     * decision, and the constants are the ones the XML is built from: a
     * route moved without them would publish `<D:href>`s pointing at a
     * 404, which a client reports as an empty address book rather than
     * as an error.
     */
    public function testTheDeclaredRoutesAreTheOnesTheXmlPointsAt(): void
    {
        $frontController = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        $this->assertIsString($frontController);

        $this->assertStringContainsString("'" . AddressBookService::ROOT_PATH . "',", $frontController);
        $this->assertStringContainsString("'" . AddressBookService::COLLECTION_PATH . "',", $frontController);
        $this->assertStringContainsString(
            "'" . AddressBookService::COLLECTION_PATH . "{member_id}.vcf',",
            $frontController
        );

        // And the href builder agrees with the route pattern: one carries
        // a placeholder the other fills with a member id.
        $entry = new AddressBookEntry(42, 7, '"x"');
        $this->assertSame(AddressBookService::COLLECTION_PATH . '42.vcf', $entry->href());
    }

    /**
     * A path this collection does not publish is a 404 from the router,
     * never a route that happens to match. `/carddav/staff/` and one
     * card are the only two shapes under it.
     */
    public function testAPathTheCollectionDoesNotPublishIsNotRouted(): void
    {
        $this->assertSame(404, $this->statusFor('GET', '/carddav/staff/', null));
        $this->assertSame(404, $this->statusFor('GET', '/carddav/staff/abc.vcf', null));
        $this->assertSame(404, $this->statusFor('PROPFIND', '/carddav/other/', null));
    }
}

class CardDavStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('ok', 200);
    }
}
