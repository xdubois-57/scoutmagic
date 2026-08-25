<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Config\AppConfig;
use Core\Http\Controller\AbstractController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The floor underneath input validation (SECURITY.md § 35): a write the
 * schema refused because of the values the request carried is answered
 * as the client error it is, and everything else still crashes loudly.
 *
 * Both halves matter equally. A net that catches too little leaves the
 * 500s a dynamic scan found; a net that catches too much turns this
 * project's own bugs into a tidy 400 page nobody investigates.
 */
class FrontControllerConstraintViolationTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn (): string => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'test'));
        $this->twig->addFunction(new \Twig\TwigFunction('editable', fn (): string => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('editable_image', fn (): string => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('file_url', fn (): string => ''));
        $this->twig->addFunction(new \Twig\TwigFunction(
            'person_avatar',
            fn (string $name, array $options = []): string => '',
            ['is_safe' => ['html']]
        ));

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * @param array<string, string> $server
     */
    private function handleThrowing(\Throwable $toThrow, array $server = []): Response
    {
        ThrowingStubController::$toThrow = $toThrow;

        $router = new Router();
        $router->addRoute('POST', '/throws', ThrowingStubController::class, 'act', 'public');

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(ThrowingStubController::class, new ThrowingStubController($this->twig));

        return $fc->handle(new Request('POST', '/throws', [], [], [], $server));
    }

    private function pdoException(string $sqlstate, ?int $driverCode, string $message = 'boom'): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = [$sqlstate, $driverCode, $message];

        return $e;
    }

    /**
     * The scan's own case: a member row that no longer exists, referenced
     * by an INSERT. Nothing about the request was malformed — the world
     * moved between the page being drawn and the form being sent.
     */
    public function testAForeignKeyThatNoLongerResolvesIsAConflictNotACrash(): void
    {
        $response = $this->handleThrowing($this->pdoException(
            '23000',
            1452,
            'Cannot add or update a child row: a foreign key constraint fails'
        ));

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testAValueTooWideForItsColumnIsABadRequest(): void
    {
        $response = $this->handleThrowing($this->pdoException(
            '22003',
            1264,
            "Out of range value for column 'capacity' at row 1"
        ));

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * What the visitor is shown must be the sentence this project wrote,
     * not the driver's. MySQL names the table, the column and the
     * constraint, in English — the leak
     * Core\Exception\UserFacingException exists to prevent.
     */
    public function testTheDriversOwnWordsNeverReachThePage(): void
    {
        $response = $this->handleThrowing($this->pdoException(
            '23000',
            1452,
            'Cannot add or update a child row: a foreign key constraint fails '
            . '(`scoutmagic`.`discussion_group_members`, CONSTRAINT `fk_dgm_member` FOREIGN KEY (`member_id`))'
        ));

        $body = $response->getBody();

        $this->assertStringNotContainsString('FOREIGN KEY', $body);
        $this->assertStringNotContainsString('discussion_group_members', $body);
        $this->assertStringNotContainsString('CONSTRAINT', $body);
        $this->assertStringNotContainsString('member_id', $body);
        $this->assertStringContainsString('Action impossible', $body);
        $this->assertStringContainsString('Rechargez', $body);
    }

    /**
     * The other half. A NOT NULL column this application forgot to
     * populate arrives with the SAME SQLSTATE as the foreign key above,
     * and is a bug here — it has to keep reaching ErrorHandler as a 500.
     */
    public function testAColumnTheApplicationForgotStillCrashes(): void
    {
        $this->expectException(\PDOException::class);

        $this->handleThrowing($this->pdoException('23000', 1048, "Column 'name' cannot be null"));
    }

    public function testAMissingTableStillCrashes(): void
    {
        $this->expectException(\PDOException::class);

        $this->handleThrowing($this->pdoException('42S02', 1146, "Table 'x' doesn't exist"));
    }

    public function testAnOrdinaryBugStillCrashes(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->handleThrowing(new \RuntimeException('a real bug'));
    }

    /**
     * A repository that wraps its statement in a transaction catches,
     * rolls back and rethrows — the PDOException then arrives as the
     * cause rather than as the throwable itself.
     */
    public function testTheCauseIsFoundThroughARepositorysRethrow(): void
    {
        $response = $this->handleThrowing(new \RuntimeException(
            'replaceRange failed',
            0,
            $this->pdoException('23000', 1452)
        ));

        $this->assertSame(409, $response->getStatusCode());
    }

    /**
     * A response the controller returned normally must be untouched —
     * the try/catch wraps the call, it does not change what comes back.
     */
    public function testAControllerThatDoesNotThrowIsUnaffected(): void
    {
        ThrowingStubController::$toThrow = null;

        $router = new Router();
        $router->addRoute('POST', '/throws', ThrowingStubController::class, 'act', 'public');

        $fc = new FrontController($router, $this->twig, $this->config);
        $fc->registerController(ThrowingStubController::class, new ThrowingStubController($this->twig));

        $response = $fc->handle(new Request('POST', '/throws', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getBody());
    }

    /**
     * The duty grid and the gallery uploader post JSON and read JSON
     * back. Handing them an HTML document produces a parse error in the
     * browser rather than the sentence the visitor was meant to see.
     */
    public function testAScriptThatPostedJsonGetsJsonBack(): void
    {
        $response = $this->handleThrowing(
            $this->pdoException('23000', 1452),
            ['CONTENT_TYPE' => 'application/json']
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);

        $payload = json_decode($response->getBody(), true);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('Rechargez', $payload['error']);
    }

    public function testAnAcceptHeaderAskingOnlyForJsonIsEnough(): void
    {
        $response = $this->handleThrowing(
            $this->pdoException('22003', 1264),
            ['HTTP_ACCEPT' => 'application/json, text/plain, */*']
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);
    }

    /**
     * A browser navigating to a page sends an Accept that mentions JSON
     * somewhere down its list. That is a page request, and it gets the
     * page.
     */
    public function testABrowserNavigationStillGetsThePage(): void
    {
        $response = $this->handleThrowing(
            $this->pdoException('23000', 1452),
            ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8']
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('Action impossible', $response->getBody());
    }
}

class ThrowingStubController extends AbstractController
{
    public static ?\Throwable $toThrow = null;

    /**
     * @param array<string, string> $params
     */
    public function act(Request $request, array $params): Response
    {
        if (self::$toThrow !== null) {
            throw self::$toThrow;
        }

        return new Response('ok', 200);
    }
}
