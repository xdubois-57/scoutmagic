<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
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
 * The role boundary of the two routes « Le saviez-vous ? » writes through
 * (ARCHITECTURE.md §8.95): both are `identified`, allowed at that floor
 * and refused one level below.
 *
 * `public` is the level below `identified`, and it is the one that
 * matters here: the whole feature is about what an account has already
 * been shown, so a visitor with no account has nothing to record and no
 * state to reset. The guard is the Router's, never the controller's, so a
 * stub is what proves it.
 */
final class HelpDiscoveryRbacTest extends TestCase
{
    private Environment $twig;
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addExtension(new \Core\View\CompactHtmlExtension());
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn (): string => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'test'));

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function routes(): array
    {
        return [
            'le retour du dialogue' => ['/api/aide/decouverte'],
            'la remise à zéro' => ['/account/discovery/reset'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAnIdentifiedAccountIsAllowed(string $path): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'parent@test.be', 'identified');

        $this->assertSame(200, $this->handle($path)->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAChiefIsAllowedToo(string $path): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chef@test.be', 'chief');

        $this->assertSame(200, $this->handle($path)->getStatusCode());
    }

    /**
     * One level below the floor, and the level that matters: `public` on
     * this site means nobody is signed in, so there is no account to
     * record against and no state to reset.
     *
     * The refusal is the guard's own — a redirect to the login form
     * rather than a 403, which is what Core\Http\FrontController answers
     * to an UNAUTHENTICATED request whatever its method. What the test
     * pins is that the request never reaches the controller.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAPublicVisitorIsRefused(string $path): void
    {
        $this->startTestSession();

        $response = $this->handle($path);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? null);
        $this->assertStringNotContainsString('success', $response->getBody());
    }

    /**
     * And the other half of the same boundary: signed in, but at a role
     * this site does not have below `identified` — so the refusal is
     * checked against a session that exists rather than against no
     * session at all.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testASessionWhoseRoleIsPublicIsRefusedToo(string $path): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'visiteur@test.be', 'identified');
        AuthSession::setRole('public');

        $response = $this->handle($path);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('success', $response->getBody());
    }

    private function handle(string $path): Response
    {
        $router = new Router();
        $router->addRoute('POST', '/api/aide/decouverte', HelpDiscoveryStubController::class, 'record', 'identified');
        $router->addRoute('POST', '/account/discovery/reset', HelpDiscoveryStubController::class, 'reset', 'identified');

        $frontController = new FrontController($router, $this->twig, $this->config);
        $frontController->registerController(
            HelpDiscoveryStubController::class,
            new HelpDiscoveryStubController($this->twig)
        );

        return $frontController->handle(new Request('POST', $path, [], [], [], []));
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }
}

class HelpDiscoveryStubController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function record(Request $request, array $params): Response
    {
        return new Response('{"success":true}', 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function reset(Request $request, array $params): Response
    {
        return new Response('ok', 200);
    }
}
