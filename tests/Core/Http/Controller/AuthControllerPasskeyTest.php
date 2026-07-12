<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\AuthController;
use Core\Http\Request;
use Core\Security\AuthService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Security\WebAuthnCredentialRepository;
use Core\Security\WebAuthnService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

class AuthControllerPasskeyTest extends TestCase
{
    private AuthController $controller;
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(random_bytes(32), random_bytes(32));

        $userRepo = new UserAccountRepository($this->pdo, $encryption);
        $credentialRepo = new WebAuthnCredentialRepository($this->pdo);

        $webAuthnService = new WebAuthnService(
            $credentialRepo,
            $userRepo,
            'localhost',
            'Test Scout',
            'https://localhost'
        );

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $authService = $this->createMock(AuthService::class);

        $this->controller = new AuthController($twig, $authService);
        $this->controller->setWebAuthnService($webAuthnService);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testPasskeyOptionsReturnsValidJsonWithChallenge(): void
    {
        $request = new Request('GET', '/login/passkey/options', [], [], [], []);
        $response = $this->controller->passkeyOptions($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('challenge', $data);
        $this->assertArrayHasKey('rpId', $data);
        $this->assertSame('localhost', $data['rpId']);
        $this->assertNotEmpty($data['challenge']);
    }

    public function testPasskeyVerifyWithInvalidDataReturnsError(): void
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/login/passkey/verify', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode([
            'rawId' => 'dGVzdA',
            'response' => [
                'clientDataJSON' => 'e30',
                'authenticatorData' => 'dGVzdA',
                'signature' => 'dGVzdA',
            ]
        ]));

        $response = $this->controller->passkeyVerify($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testPasskeyVerifyWithNullBodyReturnsError(): void
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/login/passkey/verify', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn('not json{');

        $response = $this->controller->passkeyVerify($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertFalse($data['success']);
    }
}
