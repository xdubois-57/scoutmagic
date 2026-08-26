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
use Core\Security\PendingMagicLink;
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
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
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
        // The shared person avatar (Core\View\PersonAvatar), registered here
        // the way Core\View\TwigFactory does with no photo service: same
        // markup as production for an account that has set no photo.
        $this->twig->addFunction(new \Twig\TwigFunction('person_avatar', function (string $name, array $options = []): string {
            return \Core\View\PersonAvatar::render($name, null, (int) ($options['size'] ?? 40));
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

        $request = new Request('POST', '/login/magic-link', [], ['email' => '', '_csrf_token' => $token, 'rgpd_consent' => '1'], [], []);
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

        $request = new Request('POST', '/login/magic-link', [], ['email' => 'user@test.com', '_csrf_token' => $token, 'rgpd_consent' => '1'], [], []);
        $response = $this->controller->requestMagicLink($request, []);

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(42, $body['poll_id']);
        // The id handed to the client is also bound to this session, so
        // only this session can later collect the resulting login.
        $this->assertTrue(PendingMagicLink::matches(42));
    }

    public function testRequestMagicLinkWithoutRgpdConsentReturnsError(): void
    {
        $this->startTestSession();
        $token = CsrfGuard::generateToken();

        $request = new Request('POST', '/login/magic-link', [], ['email' => 'user@test.com', '_csrf_token' => $token], [], []);
        $response = $this->controller->requestMagicLink($request, []);

        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('protection des données', $body['error']);
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

        // This session is the one that asked for link #1.
        PendingMagicLink::remember(1);

        $request = new Request('GET', '/auth/poll/1', [], [], [], []);
        $response = $this->controller->pollMagicLink($request, ['id' => '1']);

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['confirmed']);
        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame(5, AuthSession::getUserAccountId());
        $this->assertSame('superadmin', AuthSession::getRole());
        // Single-use: the pending id is spent once collected.
        $this->assertFalse(PendingMagicLink::matches(1));
    }

    /**
     * The account-takeover this binding exists to stop: magic_links.id is a
     * sequential AUTO_INCREMENT integer, never the emailed secret, so a
     * stranger who simply guesses the id of a link somebody else has just
     * confirmed must not be handed that person's session.
     */
    public function testPollMagicLinkRefusesAnIdThisSessionNeverRequested(): void
    {
        $this->startTestSession();

        $victim = new \Core\Security\UserAccount(
            id: 5,
            email: 'victim@test.com',
            firstName: null,
            lastName: null,
            passwordHash: null,
            isSuperAdmin: true,
            lastLoginAt: null
        );

        // The link really is confirmed — it just isn't this session's.
        $this->authService->method('isMagicLinkConfirmed')->willReturn(true);
        $this->authService->expects($this->never())->method('getUserForConfirmedLink');

        $request = new Request('GET', '/auth/poll/1', [], [], [], []);
        $response = $this->controller->pollMagicLink($request, ['id' => '1']);

        $body = json_decode($response->getBody(), true);
        // Reported exactly like "not confirmed yet", so the endpoint is not
        // an oracle for which ids exist or have been clicked either.
        $this->assertFalse($body['confirmed']);
        $this->assertFalse(AuthSession::isAuthenticated());
        $this->assertNull(AuthSession::getUserAccountId());
        unset($victim);
    }

    /**
     * Having requested *a* link doesn't licence polling every other id —
     * an attacker with a session of their own must not be able to walk the
     * id space from it.
     */
    public function testPollMagicLinkRefusesAnIdOtherThanTheOneThisSessionRequested(): void
    {
        $this->startTestSession();

        $this->authService->method('isMagicLinkConfirmed')->willReturn(true);
        $this->authService->expects($this->never())->method('getUserForConfirmedLink');

        PendingMagicLink::remember(41);

        $request = new Request('GET', '/auth/poll/42', [], [], [], []);
        $response = $this->controller->pollMagicLink($request, ['id' => '42']);

        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['confirmed']);
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testLogoutClearsThePendingMagicLink(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'user@test.com', 'identified');
        PendingMagicLink::remember(7);
        $token = CsrfGuard::generateToken();

        $request = new Request('POST', '/logout', [], ['_csrf_token' => $token], [], []);
        $this->controller->logout($request, []);

        $this->assertFalse(PendingMagicLink::matches(7));
    }

    public function testLogoutClearsTheTemporaryMember(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'user@test.com', 'admin');
        \Core\Member\TemporaryMemberSession::set(42);
        $token = CsrfGuard::generateToken();

        $request = new Request('POST', '/logout', [], ['_csrf_token' => $token], [], []);
        $this->controller->logout($request, []);

        $this->assertNull(\Core\Member\TemporaryMemberSession::get());
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
