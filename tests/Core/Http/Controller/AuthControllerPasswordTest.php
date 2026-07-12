<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Database\Connection;
use Core\Http\Controller\AuthController;
use Core\Http\Request;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\LoginThrottler;
use Core\Security\PasswordAuthMethod;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

class AuthControllerPasswordTest extends TestCase
{
    private AuthController $controller;
    private UserAccountRepository $userRepo;
    private \PDO $pdo;
    private EncryptionService $encryption;
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

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $throttler = new LoginThrottler($connection);
        $passwordAuth = new PasswordAuthMethod($this->userRepo, $this->encryption, $throttler);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $authService = $this->createMock(AuthService::class);

        $this->controller = new AuthController($twig, $authService);
        $this->controller->setPasswordAuth($passwordAuth);

        $this->csrfToken = CsrfGuard::generateToken();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testLoginWithValidCredentialsReturnsSuccess(): void
    {
        $account = $this->userRepo->create('valid@example.com');
        $hash = password_hash('CorrectPassword', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($account->id, $hash);

        $request = $this->createJsonRequest('/login/password', [
            'email' => 'valid@example.com',
            'password' => 'CorrectPassword',
            '_csrf_token' => $this->csrfToken,
        ]);

        $response = $this->controller->loginWithPassword($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
        $this->assertTrue(AuthSession::isAuthenticated());
    }

    public function testLoginWithWrongPasswordReturnsFailure(): void
    {
        $account = $this->userRepo->create('wrong@example.com');
        $hash = password_hash('CorrectPassword', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($account->id, $hash);

        $request = $this->createJsonRequest('/login/password', [
            'email' => 'wrong@example.com',
            'password' => 'WrongPassword',
            '_csrf_token' => $this->csrfToken,
        ]);

        $response = $this->controller->loginWithPassword($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertSame('Identifiants invalides.', $data['error']);
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testLoginWithUnknownEmailReturnsSameErrorMessage(): void
    {
        $request = $this->createJsonRequest('/login/password', [
            'email' => 'unknown@example.com',
            'password' => 'SomePassword',
            '_csrf_token' => $this->csrfToken,
        ]);

        $response = $this->controller->loginWithPassword($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertFalse($data['success']);
        $this->assertSame('Identifiants invalides.', $data['error']);
    }

    public function testLockoutResponseWhenRateLimited(): void
    {
        $account = $this->userRepo->create('locked@example.com');
        $hash = password_hash('Password', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($account->id, $hash);

        // Insert 5 failures to trigger lockout
        $blindIndex = $this->encryption->blindIndex('locked@example.com');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->pdo->prepare('INSERT INTO login_attempts (email_blind_index, attempted_at) VALUES (?, ?)');
            $stmt->execute([$blindIndex, $now]);
        }

        $request = $this->createJsonRequest('/login/password', [
            'email' => 'locked@example.com',
            'password' => 'Password',
            '_csrf_token' => $this->csrfToken,
        ]);

        $response = $this->controller->loginWithPassword($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('locked_seconds', $data);
        $this->assertGreaterThan(0, $data['locked_seconds']);
    }

    public function testInvalidCsrfReturns403(): void
    {
        $request = $this->createJsonRequest('/login/password', [
            'email' => 'test@example.com',
            'password' => 'test',
            '_csrf_token' => 'invalid_csrf',
        ]);

        $response = $this->controller->loginWithPassword($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Create a mock Request with JSON body content.
     *
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(string $path, array $data): Request
    {
        // We can't easily mock getRawBody, so create a real request and override
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
