<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Security\WebAuthnCredentialRepository;
use Core\Security\WebAuthnService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class WebAuthnServiceTest extends TestCase
{
    private WebAuthnService $service;
    private WebAuthnCredentialRepository $credentialRepo;
    private UserAccountRepository $userRepo;
    private \PDO $pdo;
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
        $encryption = new EncryptionService(random_bytes(32), random_bytes(32));

        $this->credentialRepo = new WebAuthnCredentialRepository($this->pdo);
        $this->userRepo = new UserAccountRepository($this->pdo, $encryption);

        $this->service = new WebAuthnService(
            $this->credentialRepo,
            $this->userRepo,
            'localhost',
            'Test Scout',
            'https://localhost'
        );

        // Create a user
        $account = $this->userRepo->create('test@example.com');
        $this->userId = $account->id;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testGenerateRegistrationOptionsReturnsValidStructure(): void
    {
        $options = $this->service->generateRegistrationOptions($this->userId, 'test@example.com');

        $this->assertArrayHasKey('rp', $options);
        $this->assertArrayHasKey('user', $options);
        $this->assertArrayHasKey('challenge', $options);
        $this->assertArrayHasKey('pubKeyCredParams', $options);
        $this->assertArrayHasKey('timeout', $options);
        $this->assertArrayHasKey('attestation', $options);
        $this->assertArrayHasKey('authenticatorSelection', $options);
        $this->assertArrayHasKey('excludeCredentials', $options);

        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame('Test Scout', $options['rp']['name']);
        $this->assertSame('test@example.com', $options['user']['name']);
        $this->assertNotEmpty($options['challenge']);
    }

    public function testGenerateRegistrationOptionsStoresChallengeInSession(): void
    {
        $this->service->generateRegistrationOptions($this->userId, 'test@example.com');

        $this->assertArrayHasKey('webauthn_challenge', $_SESSION);
        $this->assertArrayHasKey('webauthn_user_id', $_SESSION);
        $this->assertSame($this->userId, $_SESSION['webauthn_user_id']);
    }

    public function testGenerateRegistrationOptionsExcludesExistingCredentials(): void
    {
        // Register a credential first
        $this->credentialRepo->create($this->userId, random_bytes(32), random_bytes(64), 'Existing Key');

        $options = $this->service->generateRegistrationOptions($this->userId, 'test@example.com');

        $this->assertCount(1, $options['excludeCredentials']);
        $this->assertSame('public-key', $options['excludeCredentials'][0]['type']);
    }

    public function testGenerateAuthenticationOptionsReturnsValidStructure(): void
    {
        $options = $this->service->generateAuthenticationOptions();

        $this->assertArrayHasKey('challenge', $options);
        $this->assertArrayHasKey('rpId', $options);
        $this->assertArrayHasKey('timeout', $options);
        $this->assertArrayHasKey('userVerification', $options);
        $this->assertArrayHasKey('allowCredentials', $options);

        $this->assertSame('localhost', $options['rpId']);
        $this->assertNotEmpty($options['challenge']);
    }

    public function testGenerateAuthenticationOptionsStoresChallengeInSession(): void
    {
        $this->service->generateAuthenticationOptions();

        $this->assertArrayHasKey('webauthn_auth_challenge', $_SESSION);
    }

    public function testVerifyAuthenticationReturnsNullWithoutChallenge(): void
    {
        // No challenge stored
        $result = $this->service->verifyAuthentication(['rawId' => 'dGVzdA', 'response' => ['clientDataJSON' => 'e30', 'authenticatorData' => 'dGVzdA', 'signature' => 'dGVzdA']]);
        $this->assertNull($result);
    }

    public function testVerifyAuthenticationReturnsNullForUnknownCredential(): void
    {
        // Store a challenge
        $_SESSION['webauthn_auth_challenge'] = base64_encode(random_bytes(32));

        $result = $this->service->verifyAuthentication([
            'rawId' => base64_encode(random_bytes(32)),
            'response' => [
                'clientDataJSON' => base64_encode('{}'),
                'authenticatorData' => base64_encode(random_bytes(37)),
                'signature' => base64_encode(random_bytes(64)),
            ]
        ]);

        $this->assertNull($result);
    }

    public function testVerifyRegistrationFailsWithoutChallenge(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No pending registration challenge.');

        $this->service->verifyRegistration($this->userId, [
            'response' => ['clientDataJSON' => 'dGVzdA', 'attestationObject' => 'dGVzdA']
        ], 'Test Key');
    }
}
