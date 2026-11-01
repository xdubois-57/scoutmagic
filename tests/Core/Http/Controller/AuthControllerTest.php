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
use Tests\TestTwig;
use Twig\Environment;

// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
#[\PHPUnit\Framework\Attributes\Group('database')]
class AuthControllerTest extends TestCase
{
    private AuthController $controller;
    private AuthService $authService;
    private Environment $twig;
    private \Core\Security\SessionGeneration $generation;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->twig = TestTwig::create();
        $this->twig->addGlobal('site_name', 'Test Unit');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('cookie_consent_given', true);

        $this->authService = $this->createMock(AuthService::class);
        $this->generation = \Tests\SessionGenerationFixture::forPdo();
        $this->controller = new AuthController($this->twig, $this->authService, $this->generation);
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
        PendingMagicLink::remember(1, $this->generation->current());

        $request = new Request('GET', '/auth/poll/1', [], [], [], []);
        $response = $this->controller->pollMagicLink($request, ['id' => '1']);

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['confirmed']);
        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame(5, AuthSession::getUserAccountId());
        $this->assertSame($this->generation->current(), AuthSession::getGeneration());
        $this->assertSame('superadmin', AuthSession::getRole());
        // Single-use: the pending id is spent once collected.
        $this->assertFalse(PendingMagicLink::matches(1));
    }

    public function testAnOldPendingLoginCannotCollectAnIdentityAfterRotation(): void
    {
        $this->startTestSession();
        PendingMagicLink::remember(1, $this->generation->current());
        $account = new \Core\Security\UserAccount(5, 'restored@example.com', null, null, null, true, null);
        $this->authService->method('isMagicLinkConfirmed')->willReturn(true);
        $this->authService->method('getUserForConfirmedLink')->willReturn($account);
        $this->authService->method('getUserById')->willReturn($account);

        $this->generation->rotate();
        $controller = new AuthController($this->twig, $this->authService, $this->generation);
        $response = $controller->pollMagicLink(new Request('GET', '/auth/poll/1', [], [], [], []), ['id' => '1']);
        $body = json_decode($response->getBody(), true);

        $this->assertSame([false, false], [$body['confirmed'], AuthSession::isAuthenticated()]);
    }

    public function testARotationDuringAuthenticationCannotStampAnOldLoginWithTheNewGeneration(): void
    {
        $this->startTestSession();
        $before = $this->generation->current();
        PendingMagicLink::remember(1, $before);
        $account = new \Core\Security\UserAccount(5, 'current@example.com', null, null, null, true, null);
        $this->authService->method('isMagicLinkConfirmed')->willReturnCallback(function (): bool {
            $this->generation->rotate();
            return true;
        });
        $this->authService->method('getUserForConfirmedLink')->willReturn($account);
        $this->authService->method('getUserById')->willReturn($account);

        $response = $this->controller->pollMagicLink(new Request('GET', '/auth/poll/1', [], [], [], []), ['id' => '1']);

        $this->assertTrue(json_decode($response->getBody(), true)['confirmed']);
        $this->assertSame($before, AuthSession::getGeneration());
        $this->assertNotSame($this->generation->current(), AuthSession::getGeneration());
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

        PendingMagicLink::remember(41, $this->generation->current());

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
        PendingMagicLink::remember(7, $this->generation->current());
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

    /**
     * Issue #833: a page that needs a session sends its visitor to
     * /login?return=<path>, and the login goes back there.
     */
    public function testAMagicLinkCollectedByTheAskingWindowGoesBackToTheReturnPath(): void
    {
        $this->startTestSession();
        $this->controller->login(new Request('GET', '/login', ['return' => '/r/abc123'], [], [], []), []);

        $member = new \Core\Security\UserAccount(
            id: 5,
            email: 'poll@test.com',
            firstName: null,
            lastName: null,
            passwordHash: null,
            isSuperAdmin: true,
            lastLoginAt: null
        );
        $this->authService->method('isMagicLinkConfirmed')->willReturn(true);
        $this->authService->method('getUserForConfirmedLink')->willReturn($member);
        $this->authService->method('getUserById')->willReturn($member);
        PendingMagicLink::remember(1, $this->generation->current());

        $response = $this->controller->pollMagicLink(new Request('GET', '/auth/poll/1', [], [], [], []), ['id' => '1']);

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['confirmed']);
        $this->assertSame('/r/abc123', $body['redirect']);
        $this->assertSame('/', \Core\Security\LoginReturn::target(), 'Spent by the login that used it.');
    }

    public function testALoginWithoutAReturnPathGoesHome(): void
    {
        $this->startTestSession();
        $this->controller->login(new Request('GET', '/login', [], [], [], []), []);

        $member = new \Core\Security\UserAccount(5, 'poll@test.com', null, null, null, true, null);
        $this->authService->method('isMagicLinkConfirmed')->willReturn(true);
        $this->authService->method('getUserForConfirmedLink')->willReturn($member);
        $this->authService->method('getUserById')->willReturn($member);
        PendingMagicLink::remember(1, $this->generation->current());

        $response = $this->controller->pollMagicLink(new Request('GET', '/auth/poll/1', [], [], [], []), ['id' => '1']);

        $this->assertSame('/', json_decode($response->getBody(), true)['redirect']);
    }

    public function testAnExternalReturnAddressIsNeverFollowed(): void
    {
        $this->startTestSession();
        $this->controller->login(new Request('GET', '/login', ['return' => 'https://evil.example/'], [], [], []), []);

        $this->assertSame('/', \Core\Security\LoginReturn::target());
    }

    public function testAVisitorAlreadyLoggedInGoesStraightToTheReturnPath(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'user@test.com', 'identified');

        $response = $this->controller->login(new Request('GET', '/login', ['return' => '/r/abc123'], [], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/r/abc123', $response->getHeaders()['Location']);
    }

    public function testAVisitorAlreadyLoggedInIsNotSentOffSite(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'user@test.com', 'identified');

        $response = $this->controller->login(new Request('GET', '/login', ['return' => '//evil.example'], [], [], []), []);

        $this->assertSame('/', $response->getHeaders()['Location']);
    }

    public function testTheConfirmationPageOpenedInTheAskingSessionContinuesToTheReturnPath(): void
    {
        $this->startTestSession();
        $this->controller->login(new Request('GET', '/login', ['return' => '/r/abc123'], [], [], []), []);
        PendingMagicLink::remember(7, $this->generation->current());
        $this->authService->method('verifyMagicLink')->willReturn(
            new \Core\Security\VerifiedMagicLink('poll@test.com', 5)
        );
        $this->authService->method('getUserById')->willReturn(
            new \Core\Security\UserAccount(5, 'poll@test.com', null, null, null, true, null)
        );

        $response = $this->controller->verifyMagicLink(
            new Request('GET', '/auth/verify', ['token' => 'tok', 'id' => '7'], [], [], []),
            []
        );

        $this->assertStringContainsString('href="/r/abc123"', $response->getBody());
        // Not spent: the tab polling for this link collects it.
        $this->assertSame('/r/abc123', \Core\Security\LoginReturn::target());
    }

    public function testLogoutForgetsTheReturnPath(): void
    {
        $this->startTestSession();
        \Core\Security\LoginReturn::remember('/r/abc123');
        $token = \Core\Security\CsrfGuard::generateToken();

        $this->controller->logout(new Request('POST', '/logout', [], ['_csrf_token' => $token], [], []), []);

        $this->assertSame('/', \Core\Security\LoginReturn::target());
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
