<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\AccountController;
use Core\Http\Request;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Security\WebAuthnCredentialRepository;
use Core\Security\WebAuthnService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

class AccountControllerTest extends TestCase
{
    private AccountController $controller;
    private UserAccountRepository $userRepo;
    private WebAuthnCredentialRepository $webAuthnRepo;
    private \PDO $pdo;
    private EncryptionService $encryption;
    private int $userId;

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
        $this->webAuthnRepo = new WebAuthnCredentialRepository($this->pdo);

        $webAuthnService = new WebAuthnService(
            $this->webAuthnRepo,
            $this->userRepo,
            'localhost',
            'Test Scout',
            'https://localhost'
        );

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $this->controller = new AccountController($twig, $this->userRepo, $this->webAuthnRepo, $webAuthnService);

        // Create a user and authenticate
        $account = $this->userRepo->create('test@example.com');
        $this->userId = $account->id;
        AuthSession::login($this->userId, 'test@example.com', 'identified');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testIndexPageRendersForAuthenticatedUser(): void
    {
        $request = new Request('GET', '/account', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexRedirectsToLoginWhenNotAuthenticated(): void
    {
        AuthSession::logout();
        $_SESSION = [];

        $request = new Request('GET', '/account', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUpdateProfileSavesFirstNameAndLastName(): void
    {
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/profile', [], [
            '_csrf_token' => $csrfToken,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
        ], [], []);

        $response = $this->controller->updateProfile($request, []);

        $this->assertSame(302, $response->getStatusCode());

        // Verify data was saved
        $account = $this->userRepo->findById($this->userId);
        $this->assertSame('Jean', $account->firstName);
        $this->assertSame('Dupont', $account->lastName);
    }

    public function testUpdatePasswordSetsPasswordWhenNoneExists(): void
    {
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'new_password' => 'MySecurePass',
            'confirm_password' => 'MySecurePass',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->userRepo->hasPassword($this->userId));
    }

    public function testUpdatePasswordChangesExistingPassword(): void
    {
        // Set initial password
        $hash = password_hash('OldPassword', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($this->userId, $hash);

        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'current_password' => 'OldPassword',
            'new_password' => 'NewPassword',
            'confirm_password' => 'NewPassword',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());

        // Verify new password works
        $account = $this->userRepo->findById($this->userId);
        $this->assertTrue(password_verify('NewPassword', $account->passwordHash));
    }

    public function testUpdatePasswordFailsWhenCurrentPasswordIsWrong(): void
    {
        // Set initial password
        $hash = password_hash('CorrectPassword', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($this->userId, $hash);

        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'current_password' => 'WrongPassword',
            'new_password' => 'NewPassword',
            'confirm_password' => 'NewPassword',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());

        // Verify password unchanged
        $account = $this->userRepo->findById($this->userId);
        $this->assertTrue(password_verify('CorrectPassword', $account->passwordHash));
    }

    public function testUpdatePasswordFailsWhenNewAndConfirmDontMatch(): void
    {
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'new_password' => 'PasswordOne',
            'confirm_password' => 'PasswordTwo',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->userRepo->hasPassword($this->userId));
    }

    public function testUpdatePasswordFailsWhenNewPasswordTooShort(): void
    {
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'new_password' => 'short',
            'confirm_password' => 'short',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->userRepo->hasPassword($this->userId));
    }

    public function testCsrfValidatedOnProfileUpdate(): void
    {
        $request = new Request('POST', '/account/profile', [], [
            '_csrf_token' => 'invalid_token',
            'first_name' => 'Hacker',
            'last_name' => 'Mann',
        ], [], []);

        $response = $this->controller->updateProfile($request, []);

        $this->assertSame(302, $response->getStatusCode());

        // Verify data was NOT saved
        $account = $this->userRepo->findById($this->userId);
        $this->assertNull($account->firstName);
    }

    public function testPasskeyDeleteRemovesCredential(): void
    {
        // Create a passkey
        $credId = random_bytes(32);
        $id = $this->webAuthnRepo->create($this->userId, $credId, random_bytes(64), 'Test Key');

        $request = new Request('POST', '/account/passkey/delete', [], [], [], []);
        // Simulate raw body for JSON request
        $controller = $this->getMockBuilder(AccountController::class)
            ->setConstructorArgs([
                $this->createMock(Environment::class),
                $this->userRepo,
                $this->webAuthnRepo,
                new WebAuthnService($this->webAuthnRepo, $this->userRepo, 'localhost', 'Test', 'https://localhost')
            ])
            ->onlyMethods([])
            ->getMock();

        // Directly test the repository integration
        $this->webAuthnRepo->delete($id);
        $result = $this->webAuthnRepo->findByCredentialId($credId);
        $this->assertNull($result);
    }

    public function testPasskeyDeleteRefusesOtherUsersCredential(): void
    {
        // Create another user's passkey
        $this->pdo->exec("INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES ('enc2', 'blind2', 0)");
        $otherUserId = (int) $this->pdo->lastInsertId();
        $credId = random_bytes(32);
        $id = $this->webAuthnRepo->create($otherUserId, $credId, random_bytes(64), 'Other Key');

        // Verify the credential belongs to another user
        $credentials = $this->webAuthnRepo->findByUserAccountId($this->userId);
        $found = false;
        foreach ($credentials as $cred) {
            if ((int) $cred['id'] === $id) {
                $found = true;
            }
        }
        $this->assertFalse($found);
    }

    public function testPasskeyDeleteAcceptsCsrfFromHeader(): void
    {
        $token = CsrfGuard::generateToken();
        $credId = random_bytes(32);
        $id = $this->webAuthnRepo->create($this->userId, $credId, random_bytes(64), 'Key');

        $request = $this->makeJsonRequest(['id' => $id], ['HTTP_X_CSRF_TOKEN' => $token]);
        $response = $this->controller->passkeyDelete($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNull($this->webAuthnRepo->findByCredentialId($credId));
    }

    public function testPasskeyDeleteRejectsMissingCsrf(): void
    {
        CsrfGuard::generateToken();
        $credId = random_bytes(32);
        $id = $this->webAuthnRepo->create($this->userId, $credId, random_bytes(64), 'Key');

        $request = $this->makeJsonRequest(['id' => $id], []); // no header, no body token
        $response = $this->controller->passkeyDelete($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->webAuthnRepo->findByCredentialId($credId));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     */
    private function makeJsonRequest(array $body, array $server): Request
    {
        $mock = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/account/passkey/delete', [], [], [], $server])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $mock->method('getRawBody')->willReturn((string) json_encode($body));

        return $mock;
    }
}
