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
    private const CLIENT_DATA = '{"type":"webauthn.get","challenge":"x","origin":"https://localhost"}';

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

    /** Readable, but answering no challenge: the ordinary failure, unchanged. */
    public function testPasskeyVerifyWithAnUnverifiableAssertionReturnsTheUsualFailure(): void
    {
        $request = new RequestWithInput('POST', '/login/passkey/verify', [], [], [], [], (string) json_encode([
            'rawId' => self::b64url(random_bytes(16)),
            'response' => [
                'clientDataJSON' => self::b64url(self::CLIENT_DATA),
                'authenticatorData' => self::b64url(hash('sha256', 'localhost', true) . "\x05\x00\x00\x00\x01"),
                'signature' => self::b64url(random_bytes(64)),
            ],
            'rgpd_consent' => true,
        ]));
        $_SESSION['webauthn_auth_challenge'] = ['challenge' => base64_encode(random_bytes(32)), 'issued_at' => time()];

        $response = $this->controller->passkeyVerify($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'error' => 'L\'authentification a échoué.'],
            json_decode($response->getBody(), true)
        );
    }

    /**
     * Issue #1131: a body that cannot be read is a 400 with a generic
     * French message — never a 500, never a word about what was wrong.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        $valid = [
            'rawId' => self::b64url(random_bytes(16)),
            'response' => [
                'clientDataJSON' => self::b64url(self::CLIENT_DATA),
                'authenticatorData' => self::b64url(hash('sha256', 'localhost', true) . "\x05\x00\x00\x00\x01"),
                'signature' => self::b64url(random_bytes(64)),
            ],
            'rgpd_consent' => true,
        ];
        $with = static function (array $changes) use ($valid): string {
            return (string) json_encode(array_replace_recursive($valid, $changes));
        };

        yield 'invalid JSON' => ['not json{'];
        yield 'a JSON string' => ['"rawId"'];
        yield 'a JSON number' => ['42'];
        yield 'response is a string' => [$with(['response' => 'abc'])];
        yield 'rawId is a number' => [$with(['rawId' => 7])];
        yield 'rawId is not base64url' => [$with(['rawId' => '!!'])];
        yield 'signature is an object' => [$with(['response' => ['signature' => ['a' => 1]]])];
        yield 'authenticatorData is truncated' => [$with(['response' => ['authenticatorData' => 'dGVzdA']])];
        yield 'clientDataJSON is not JSON' => [$with(['response' => ['clientDataJSON' => 'e30x']])];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedBodies')]
    public function testAMalformedPasskeyLoginIsA400(string $body): void
    {
        $_SESSION['webauthn_auth_challenge'] = ['challenge' => base64_encode(random_bytes(32)), 'issued_at' => time()];
        $request = new RequestWithInput('POST', '/login/passkey/verify', [], [], [], [], $body);

        $response = $this->controller->passkeyVerify($request, []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'error' => 'Requête invalide.'],
            json_decode($response->getBody(), true)
        );
        $this->assertNull(AuthSession::getUserAccountId());
    }

    public function testAPasskeyLoginAnsweringAnExpiredChallengeIsAskedToRetry(): void
    {
        $_SESSION['webauthn_auth_challenge'] = [
            'challenge' => base64_encode(random_bytes(32)),
            'issued_at' => time() - WebAuthnService::CHALLENGE_TTL_SECONDS - 1,
        ];
        $request = new RequestWithInput('POST', '/login/passkey/verify', [], [], [], [], (string) json_encode([
            'rawId' => self::b64url(random_bytes(16)),
            'response' => [
                'clientDataJSON' => self::b64url(self::CLIENT_DATA),
                'authenticatorData' => self::b64url(hash('sha256', 'localhost', true) . "\x05\x00\x00\x00\x01"),
                'signature' => self::b64url(random_bytes(64)),
            ],
            'rgpd_consent' => true,
        ]));

        $response = $this->controller->passkeyVerify($request, []);

        $this->assertSame(
            ['success' => false, 'error' => 'Le délai est dépassé. Veuillez réessayer.'],
            json_decode($response->getBody(), true)
        );
        $this->assertArrayNotHasKey('webauthn_auth_challenge', $_SESSION);
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
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

        // clientDataJSON `{}` names no type, challenge nor origin: unreadable (issue #1131).
        $this->assertSame(400, $response->getStatusCode());
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

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertSame('Requête invalide.', $data['error']);
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

    /** Issue #1115: a passkey login is a fresh proof of identity. */
    public function testAPasskeyLoginCountsAsAFreshAuthentication(): void
    {
        $account = (new UserAccountRepository($this->pdo, new EncryptionService(random_bytes(32), random_bytes(32))))
            ->create('passkey-fresh@example.com');
        $webAuthn = $this->createStub(WebAuthnService::class);
        $webAuthn->method('verifyAuthentication')->willReturn($account);
        $controller = new AuthController(
            $this->createStub(Environment::class),
            $this->createStub(AuthService::class),
            \Tests\SessionGenerationFixture::forPdo($this->pdo)
        );
        $controller->setWebAuthnService($webAuthn);
        AuthSession::login($account->id, 'passkey-fresh@example.com', 'identified');
        $_SESSION['_auth']['authenticated_at'] = time() - 3600;

        $request = new RequestWithInput('POST', '/login/passkey/verify', [], [], [], [], (string) json_encode([
            'id' => 'x',
            'rgpd_consent' => true,
        ]));
        $data = json_decode($controller->passkeyVerify($request, [])->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertTrue(\Core\Security\RecentAuthentication::isRecent());
    }
}
