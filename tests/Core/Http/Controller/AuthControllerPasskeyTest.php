<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\AuthController;
use Core\Http\Request;
use Tests\RequestWithInput;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Security\WebAuthnCredentialRepository;
use Core\Security\WebAuthnService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

#[\PHPUnit\Framework\Attributes\Group('database')]
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

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $authService = $this->createStub(AuthService::class);

        $this->controller = new AuthController($twig, $authService, \Tests\SessionGenerationFixture::forPdo($this->pdo));
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
        $request = new RequestWithInput('POST', '/login/passkey/verify', [], [], [], [], json_encode([
            'rawId' => 'dGVzdA',
            'response' => [
                'clientDataJSON' => 'e30',
                'authenticatorData' => 'dGVzdA',
                'signature' => 'dGVzdA',
            ],
            'rgpd_consent' => true,
        ]));

        $response = $this->controller->passkeyVerify($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testPasskeyVerifyWithoutRgpdConsentReturnsError(): void
    {
        $request = new RequestWithInput('POST', '/login/passkey/verify', [], [], [], [], json_encode(['id' => 'x', 'type' => 'public-key', 'rawId' => 'x', 'response' => []]));

        $response = $this->controller->passkeyVerify($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('protection des données', $data['error']);
    }

    public function testPasskeyVerifyWithNullBodyReturnsError(): void
    {
        $request = new RequestWithInput('POST', '/login/passkey/verify', [], [], [], [], 'not json{');

        $response = $this->controller->passkeyVerify($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertFalse($data['success']);
    }

    /**
     * Issue #833: a passkey login goes back to the page that asked for it.
     * WebAuthn itself is stubbed — its verification has its own tests; this
     * is about what the controller answers once it has succeeded.
     */
    public function testASuccessfulPasskeyLoginGoesBackToThePageThatAskedForIt(): void
    {
        $account = (new UserAccountRepository($this->pdo, new EncryptionService(random_bytes(32), random_bytes(32))))
            ->create('passkey-return@example.com');
        $webAuthn = $this->createStub(WebAuthnService::class);
        $webAuthn->method('verifyAuthentication')->willReturn($account);
        $twig = $this->createStub(Environment::class);
        $controller = new AuthController($twig, $this->createStub(AuthService::class), \Tests\SessionGenerationFixture::forPdo($this->pdo));
        $controller->setWebAuthnService($webAuthn);
        \Core\Security\LoginReturn::remember('/r/abc123');

        $request = new RequestWithInput('POST', '/login/passkey/verify', [], [], [], [], json_encode([
            'id' => 'x',
            'rgpd_consent' => true,
        ]));
        $data = json_decode($controller->passkeyVerify($request, [])->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertSame(\Tests\SessionGenerationFixture::forPdo($this->pdo)->current(), AuthSession::getGeneration());
        $this->assertSame('/r/abc123', $data['redirect']);
    }
}
