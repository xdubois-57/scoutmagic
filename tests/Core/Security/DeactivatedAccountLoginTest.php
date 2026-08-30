<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Http\Controller\AuthController;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Database\Connection;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\LoginThrottler;
use Core\Security\PasswordAuthMethod;
use Core\Security\PendingMagicLink;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Core\Security\VerifiedMagicLink;
use Core\Security\WebAuthnService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * `is_active = false` has to close every door, not most of them.
 *
 * The four ways in all reach the same gate —
 * RoleResolver::isEmailAuthorizedToLogin(), through
 * AuthController::isMemberAuthorized() — but "they all go through one
 * method" is a claim about the code as it is today, and the point of
 * these tests is that it stays true. So each path is driven end to end
 * against a real RoleResolver rather than asserted structurally.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeactivatedAccountLoginTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private UserAccountRepository $userRepo;
    private RoleResolver $roleResolver;
    private ScoutYearResolver $scoutYearResolver;
    private int $scoutYearId;
    private string $csrfToken;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(random_bytes(32), random_bytes(32));
        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);
        $this->roleResolver = new RoleResolver(
            new MemberYearRepository($this->pdo),
            $this->encryption,
            $this->pdo
        );

        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->scoutYearResolver = $this->createStub(ScoutYearResolver::class);
        $this->scoutYearResolver->method('getCurrentPublicYear')->willReturn([
            'id' => $this->scoutYearId,
            'label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-08-31',
        ]);

        $this->csrfToken = CsrfGuard::generateToken();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ---------------------------------------------------------------
    // The gate itself
    // ---------------------------------------------------------------

    public function testAnActiveSuperAdminIsAuthorized(): void
    {
        $this->userRepo->create('admin@example.com', true);

        $this->assertTrue(
            $this->roleResolver->isEmailAuthorizedToLogin('admin@example.com', $this->scoutYearId)
        );
    }

    /**
     * The order trap: `is_super_admin === true → return true` sitting
     * before the is_active check would leave exactly the account an
     * operator most wants shut out still able to log in.
     */
    public function testADeactivatedSuperAdminIsRefused(): void
    {
        $admin = $this->userRepo->create('admin@example.com', true);
        $this->userRepo->deactivate($admin->id);

        $this->assertFalse(
            $this->roleResolver->isEmailAuthorizedToLogin('admin@example.com', $this->scoutYearId)
        );
    }

    public function testReactivatingRestoresTheAuthorization(): void
    {
        $admin = $this->userRepo->create('admin@example.com', true);
        $this->userRepo->deactivate($admin->id);
        $this->userRepo->reactivate($admin->id);

        $this->assertTrue(
            $this->roleResolver->isEmailAuthorizedToLogin('admin@example.com', $this->scoutYearId)
        );
    }

    public function testAnAccountWithNoRowAtAllIsUnaffected(): void
    {
        // No user_accounts row: the answer comes from the member match
        // alone, exactly as before this column existed.
        $this->assertFalse(
            $this->roleResolver->isEmailAuthorizedToLogin('nobody@example.com', $this->scoutYearId)
        );
    }

    // ---------------------------------------------------------------
    // Path 1 — magic link, GET /auth/verify
    // ---------------------------------------------------------------

    public function testMagicLinkVerifyRefusesADeactivatedAccount(): void
    {
        $account = $this->deactivatedSuperAdmin();

        $authService = $this->createStub(AuthService::class);
        $authService->method('verifyMagicLink')
            ->willReturn(new VerifiedMagicLink($account->email, $account->id));

        $controller = $this->controllerWith($authService);
        PendingMagicLink::remember(1);

        $controller->verifyMagicLink($this->getRequest('/auth/verify', ['token' => 'tok', 'id' => '1']), []);

        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testMagicLinkVerifyStillAdmitsAnActiveAccount(): void
    {
        $account = $this->userRepo->create('admin@example.com', true);

        $authService = $this->createStub(AuthService::class);
        $authService->method('verifyMagicLink')
            ->willReturn(new VerifiedMagicLink($account->email, $account->id));

        $controller = $this->controllerWith($authService);
        PendingMagicLink::remember(1);

        $controller->verifyMagicLink($this->getRequest('/auth/verify', ['token' => 'tok', 'id' => '1']), []);

        $this->assertTrue(AuthSession::isAuthenticated());
    }

    // ---------------------------------------------------------------
    // Path 2 — magic link polling, GET /auth/poll/{id}
    // ---------------------------------------------------------------

    public function testMagicLinkPollRefusesADeactivatedAccount(): void
    {
        $account = $this->deactivatedSuperAdmin();

        $authService = $this->createStub(AuthService::class);
        $authService->method('isMagicLinkConfirmed')->willReturn(true);
        $authService->method('getUserForConfirmedLink')
            ->willReturn($this->userRepo->findById($account->id));

        $controller = $this->controllerWith($authService);
        PendingMagicLink::remember(7);

        $controller->pollMagicLink($this->getRequest('/auth/poll/7', []), ['id' => '7']);

        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testMagicLinkPollStillAdmitsAnActiveAccount(): void
    {
        $account = $this->userRepo->create('admin@example.com', true);

        $authService = $this->createStub(AuthService::class);
        $authService->method('isMagicLinkConfirmed')->willReturn(true);
        $authService->method('getUserForConfirmedLink')
            ->willReturn($this->userRepo->findById($account->id));

        $controller = $this->controllerWith($authService);
        PendingMagicLink::remember(7);

        $controller->pollMagicLink($this->getRequest('/auth/poll/7', []), ['id' => '7']);

        $this->assertTrue(AuthSession::isAuthenticated());
    }

    // ---------------------------------------------------------------
    // Path 3 — password, POST /login/password
    // ---------------------------------------------------------------

    public function testPasswordLoginRefusesADeactivatedAccount(): void
    {
        $account = $this->deactivatedSuperAdmin();
        $this->userRepo->updatePasswordHash($account->id, password_hash('CorrectPassword', PASSWORD_DEFAULT));

        $controller = $this->controllerWith($this->createStub(AuthService::class));
        $controller->setPasswordAuth($this->passwordAuth());

        $response = $controller->loginWithPassword($this->jsonRequest('/login/password', [
            'email' => $account->email,
            'password' => 'CorrectPassword',
            '_csrf_token' => $this->csrfToken,
            'rgpd_consent' => true,
        ]), []);

        $data = json_decode($response->getBody(), true);
        $this->assertFalse($data['success']);
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testPasswordLoginStillAdmitsAnActiveAccount(): void
    {
        $account = $this->userRepo->create('admin@example.com', true);
        $this->userRepo->updatePasswordHash($account->id, password_hash('CorrectPassword', PASSWORD_DEFAULT));

        $controller = $this->controllerWith($this->createStub(AuthService::class));
        $controller->setPasswordAuth($this->passwordAuth());

        $response = $controller->loginWithPassword($this->jsonRequest('/login/password', [
            'email' => $account->email,
            'password' => 'CorrectPassword',
            '_csrf_token' => $this->csrfToken,
            'rgpd_consent' => true,
        ]), []);

        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue(AuthSession::isAuthenticated());
    }

    // ---------------------------------------------------------------
    // Path 4 — passkey, POST /login/passkey/verify
    // ---------------------------------------------------------------

    public function testPasskeyLoginRefusesADeactivatedAccount(): void
    {
        $account = $this->deactivatedSuperAdmin();

        $webAuthn = $this->createStub(WebAuthnService::class);
        $webAuthn->method('verifyAuthentication')->willReturn($this->userRepo->findById($account->id));

        $controller = $this->controllerWith($this->createStub(AuthService::class));
        $controller->setWebAuthnService($webAuthn);

        $response = $controller->passkeyVerify($this->jsonRequest('/login/passkey/verify', [
            'rgpd_consent' => true,
        ]), []);

        $data = json_decode($response->getBody(), true);
        $this->assertFalse($data['success']);
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testPasskeyLoginStillAdmitsAnActiveAccount(): void
    {
        $account = $this->userRepo->create('admin@example.com', true);

        $webAuthn = $this->createStub(WebAuthnService::class);
        $webAuthn->method('verifyAuthentication')->willReturn($this->userRepo->findById($account->id));

        $controller = $this->controllerWith($this->createStub(AuthService::class));
        $controller->setWebAuthnService($webAuthn);

        $response = $controller->passkeyVerify($this->jsonRequest('/login/passkey/verify', [
            'rgpd_consent' => true,
        ]), []);

        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue(AuthSession::isAuthenticated());
    }

    // ---------------------------------------------------------------

    private function deactivatedSuperAdmin(): \Core\Security\UserAccount
    {
        $account = $this->userRepo->create('admin@example.com', true);
        $this->userRepo->deactivate($account->id);

        return $account;
    }

    private function controllerWith(AuthService $authService): AuthController
    {
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        return new AuthController($twig, $authService, $this->roleResolver, $this->scoutYearResolver);
    }

    private function passwordAuth(): PasswordAuthMethod
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        return new PasswordAuthMethod($this->userRepo, $this->encryption, new LoginThrottler($connection));
    }

    /**
     * @param array<string, string> $query
     */
    private function getRequest(string $path, array $query): Request
    {
        return new Request('GET', $path, $query, [], [], []);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(string $path, array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
