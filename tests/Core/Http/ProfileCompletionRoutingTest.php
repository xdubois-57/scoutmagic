<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Config\AppConfig;
use Core\Http\Controller\AbstractController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Security\AuthSession;
use Core\Security\ProfileCompletionGate;
use Core\Security\UserAccount;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The gate seen from where it actually runs: inside
 * Core\Http\FrontController, after the RBAC guard and before any
 * controller.
 *
 * Tests\Core\Security\ProfileCompletionGateTest states the rules; this
 * states that the front controller applies them in the right ORDER, which
 * is the half no unit test of the gate can see. Two orderings are wrong in
 * different ways: before the guard, a route this session may not reach at
 * all answers "complete your profile", which promises a page that is not
 * theirs; and after the controller, nothing is blocked at all.
 */
final class ProfileCompletionRoutingTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html></html>');
        $this->twig = $twig;

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function frontController(Router $router, ?UserAccount $account): FrontController
    {
        $frontController = new FrontController(
            $router,
            $this->twig,
            $this->config,
            null,
            null,
            null,
            null,
            ProfileCompletionGate::forAccount($account)
        );
        $frontController->registerController(
            ProfileCompletionStubController::class,
            new ProfileCompletionStubController($this->twig)
        );

        return $frontController;
    }

    private static function namelessAccount(): UserAccount
    {
        return new UserAccount(3, 'parent@example.be', null, null, null, false, null);
    }

    private static function namedAccount(): UserAccount
    {
        return new UserAccount(3, 'parent@example.be', 'Camille', 'Renard', null, false, null);
    }

    public function testANamelessSessionIsSentToTheScreenInsteadOfThePageItAskedFor(): void
    {
        AuthSession::login(3, 'parent@example.be', 'identified');

        $router = new Router();
        $router->addRoute('GET', '/members/3', ProfileCompletionStubController::class, 'index', 'identified');

        $response = $this->frontController($router, self::namelessAccount())
            ->handle(new Request('GET', '/members/3', [], [], [], []));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(ProfileCompletionGate::PATH, $response->getHeaders()['Location']);
    }

    public function testTheSameSessionGoesThroughOnceBothNamesAreThere(): void
    {
        AuthSession::login(3, 'parent@example.be', 'identified');

        $router = new Router();
        $router->addRoute('GET', '/members/3', ProfileCompletionStubController::class, 'index', 'identified');

        $response = $this->frontController($router, self::namedAccount())
            ->handle(new Request('GET', '/members/3', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('stub-ok', $response->getBody());
    }

    /**
     * The block is a session's, never a visitor's: a public page is served
     * unchanged, which is also what lets the screen load its own stylesheet,
     * manifest and icons.
     */
    public function testAPublicPageIsServedToABlockedSession(): void
    {
        AuthSession::login(3, 'parent@example.be', 'identified');

        $router = new Router();
        $router->addRoute('GET', '/rgpd', ProfileCompletionStubController::class, 'index', 'public');

        $response = $this->frontController($router, self::namelessAccount())
            ->handle(new Request('GET', '/rgpd', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testLoggingOutIsStillPossibleWhileBlocked(): void
    {
        AuthSession::login(3, 'parent@example.be', 'identified');

        $router = new Router();
        $router->addRoute('POST', '/logout', ProfileCompletionStubController::class, 'index', 'identified');

        $response = $this->frontController($router, self::namelessAccount())
            ->handle(new Request('POST', '/logout', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTheScreenItselfIsReachableWhileBlocked(): void
    {
        AuthSession::login(3, 'parent@example.be', 'identified');

        $router = new Router();
        $router->addRoute(
            'GET',
            ProfileCompletionGate::PATH,
            ProfileCompletionStubController::class,
            'index',
            'identified'
        );

        $response = $this->frontController($router, self::namelessAccount())
            ->handle(new Request('GET', ProfileCompletionGate::PATH, [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The guard runs first: a route above this session's role answers the
     * refusal it deserves, not an invitation to fill in a name for a page
     * it will never see.
     */
    public function testARouteAboveTheSessionsRoleStillAnswers403(): void
    {
        AuthSession::login(3, 'parent@example.be', 'identified');

        $router = new Router();
        $router->addRoute('GET', '/admin/journal', ProfileCompletionStubController::class, 'index', 'admin');

        $response = $this->frontController($router, self::namelessAccount())
            ->handle(new Request('GET', '/admin/journal', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The RBAC boundary of the screen's own route: `identified` reaches it,
     * one level below does not.
     */
    public function testTheScreenIsRefusedToAnAnonymousVisitor(): void
    {
        $router = new Router();
        $router->addRoute(
            'GET',
            ProfileCompletionGate::PATH,
            ProfileCompletionStubController::class,
            'index',
            'identified'
        );

        $response = $this->frontController($router, null)
            ->handle(new Request('GET', ProfileCompletionGate::PATH, [], [], [], []));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    /**
     * Every other FrontController call site in this suite builds one with
     * no gate at all; none of them may start being intercepted.
     */
    public function testNoGateMeansNothingIsEverIntercepted(): void
    {
        AuthSession::login(3, 'parent@example.be', 'identified');

        $router = new Router();
        $router->addRoute('GET', '/members/3', ProfileCompletionStubController::class, 'index', 'identified');

        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(
            ProfileCompletionStubController::class,
            new ProfileCompletionStubController($this->twig)
        );

        $response = $frontController->handle(new Request('GET', '/members/3', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }
}

class ProfileCompletionStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return new Response('stub-ok', 200);
    }
}
