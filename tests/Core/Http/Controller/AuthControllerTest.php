<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Database\Connection;
use Core\Http\Controller\AuthController;
use Core\Http\Request;
use Core\Mail\MailService;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class AuthControllerTest extends TestCase
{
    private AuthController $controller;
    private AuthService $authService;
    private Environment $twig;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addGlobal('site_name', 'Test Unit');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('cookie_consent_given', true);

        // Register csrf_field function
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf_token" value="test">';
        }, ['is_safe' => ['html']]));

        // Register get_flash function
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', function (): ?array {
            return null;
        }));

        // Register csrf_token function
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', function (): string {
            return 'test-csrf-token';
        }));

        // Register editable functions (for base template)
        $this->twig->addFunction(new \Twig\TwigFunction('editable', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));

        $this->authService = $this->createMock(AuthService::class);
        $this->controller = new AuthController($this->twig, $this->authService);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testLoginRendersLoginPage(): void
    {
        $this->startTestSession();

        $request = new Request('GET', '/login', [], [], [], []);
        $response = $this->controller->login($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Se connecter', $response->getBody());
        $this->assertStringContainsString('Lien magique', $response->getBody());
        $this->assertStringContainsString('Mot de passe', $response->getBody());
    }

    public function testLoginRedirectsWhenAuthenticated(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'user@test.com', 'identified');

        $request = new Request('GET', '/login', [], [], [], []);
        $response = $this->controller->login($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertSame('/', $headers['Location']);
    }

    public function testRequestMagicLinkWithoutCsrfReturns403(): void
    {
        $this->startTestSession();

        $request = new Request('POST', '/login/magic-link', [], ['email' => 'test@test.com', '_csrf_token' => 'invalid'], [], []);
        $response = $this->controller->requestMagicLink($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRequestMagicLinkWithEmptyEmailReturnsError(): void
    {
        $this->startTestSession();
        $token = CsrfGuard::generateToken();

        $request = new Request('POST', '/login/magic-link', [], ['email' => '', '_csrf_token' => $token], [], []);
        $response = $this->controller->requestMagicLink($request, []);

        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('email valide', $body['error']);
    }

    public function testRequestMagicLinkWithValidEmailReturnsSuccess(): void
    {
        $this->startTestSession();
        $token = CsrfGuard::generateToken();

        $this->authService->method('requestMagicLink')
            ->willReturn(new \Core\Security\MagicLinkResult(true, 42, null));

        $request = new Request('POST', '/login/magic-link', [], ['email' => 'user@test.com', '_csrf_token' => $token], [], []);
        $response = $this->controller->requestMagicLink($request, []);

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(42, $body['poll_id']);
    }

    public function testLogoutClearsSessionAndRedirects(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'user@test.com', 'identified');
        $token = CsrfGuard::generateToken();

        $this->assertTrue(AuthSession::isAuthenticated());

        $request = new Request('POST', '/logout', [], ['_csrf_token' => $token], [], []);
        $response = $this->controller->logout($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testVerifyMagicLinkWithInvalidTokenShowsError(): void
    {
        $this->startTestSession();

        $this->authService->method('verifyMagicLink')->willReturn(null);

        $request = new Request('GET', '/auth/verify', ['token' => 'bad', 'id' => '1'], [], [], []);
        $response = $this->controller->verifyMagicLink($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('invalide', $response->getBody());
    }

    public function testPollMagicLinkNotConfirmed(): void
    {
        $this->startTestSession();

        $this->authService->method('isMagicLinkConfirmed')->willReturn(false);

        $request = new Request('GET', '/auth/poll/1', [], [], [], []);
        $response = $this->controller->pollMagicLink($request, ['id' => '1']);

        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['confirmed']);
    }

    public function testPollMagicLinkConfirmedCreatesSession(): void
    {
        $this->startTestSession();

        $superAdmin = new \Core\Security\UserAccount(
            id: 5,
            email: 'poll@test.com',
            firstName: null,
            lastName: null,
            passwordHash: null,
            isSuperAdmin: true,
            lastLoginAt: null
        );

        $this->authService->method('isMagicLinkConfirmed')->willReturn(true);
        $this->authService->method('getUserForConfirmedLink')->willReturn($superAdmin);
        $this->authService->method('getUserById')->willReturn($superAdmin);

        $request = new Request('GET', '/auth/poll/1', [], [], [], []);
        $response = $this->controller->pollMagicLink($request, ['id' => '1']);

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['confirmed']);
        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame(5, AuthSession::getUserAccountId());
        $this->assertSame('admin', AuthSession::getRole());
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
