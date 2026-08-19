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

    public function testVerifyRegistrationStoresCredentialForValidNoneAttestation(): void
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $response = $this->buildRegistrationResponse($challenge, 'https://localhost', 'localhost');
        $id = $this->service->verifyRegistration($this->userId, $response, 'My Key');

        $this->assertGreaterThan(0, $id);
        $credentials = $this->credentialRepo->findByUserAccountId($this->userId);
        $this->assertCount(1, $credentials);
    }

    public function testVerifyRegistrationAcceptsLocalhostOriginWithDifferentSchemeAndPort(): void
    {
        // rpOrigin is https://localhost, but the browser serves the page from
        // http://localhost:8000 — the host still matches rpId, so it is accepted.
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $response = $this->buildRegistrationResponse($challenge, 'http://localhost:8000', 'localhost');
        $id = $this->service->verifyRegistration($this->userId, $response, 'Dev Key');

        $this->assertGreaterThan(0, $id);
    }

    public function testVerifyRegistrationRejectsForeignOrigin(): void
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $response = $this->buildRegistrationResponse($challenge, 'https://evil.example.com', 'localhost');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Origin mismatch.');
        $this->service->verifyRegistration($this->userId, $response, 'Bad Key');
    }

    public function testVerifyRegistrationRejectsChallengeMismatch(): void
    {
        $_SESSION['webauthn_challenge'] = base64_encode(random_bytes(32));
        $_SESSION['webauthn_user_id'] = $this->userId;

        // clientData carries a different challenge than the one stored.
        $response = $this->buildRegistrationResponse(random_bytes(32), 'https://localhost', 'localhost');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Challenge mismatch.');
        $this->service->verifyRegistration($this->userId, $response, 'Key');
    }

    /**
     * userVerification is requested as 'required', but a request to the
     * client is not a guarantee — the assertion has to be checked. Without
     * this, a passkey satisfied user PRESENCE alone, so anyone holding an
     * already-unlocked device could register (and then authenticate) as
     * its owner.
     */
    public function testVerifyRegistrationRejectsAnAuthenticatorThatOnlyProvedPresence(): void
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        // UP | AT, but no UV.
        $response = $this->buildRegistrationResponse($challenge, 'https://localhost', 'localhost', "\x41");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User verification required.');
        $this->service->verifyRegistration($this->userId, $response, 'Presence-only key');
    }

    public function testBothOptionsObjectsRequireUserVerification(): void
    {
        $registration = $this->service->generateRegistrationOptions($this->userId, 'user@test.com');
        $authentication = $this->service->generateAuthenticationOptions();

        $this->assertSame('required', $registration['authenticatorSelection']['userVerification']);
        $this->assertSame('required', $authentication['userVerification']);
    }

    /**
     * A credential registered for a DIFFERENT relying party must not be
     * accepted just because the rest of the response is well-formed.
     */
    public function testVerifyRegistrationRejectsAttestationForAnotherRelyingParty(): void
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $response = $this->buildRegistrationResponse($challenge, 'https://localhost', 'evil.example.com');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse attestation data.');
        $this->service->verifyRegistration($this->userId, $response, 'Wrong RP');
    }

    /**
     * RS256 was advertised in pubKeyCredParams but only ES256 could ever
     * be verified, so an authenticator that picked RSA registered happily
     * and then failed every single login afterwards.
     */
    public function testAnRs256CredentialCanBeRegisteredAndAuthenticated(): void
    {
        $rsa = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($rsa);
        $details = openssl_pkey_get_details($rsa);
        $this->assertIsArray($details);

        $cose = $this->rsaCoseKey($details['rsa']['n'], $details['rsa']['e']);

        // --- register it ---
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $credId = str_repeat("\x33", 16);
        $attestation = $this->buildAttestationObject('localhost', "\x45", $cose, $credId);
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $this->b64url($challenge),
            'origin' => 'https://localhost',
        ]);
        $id = $this->service->verifyRegistration($this->userId, [
            'response' => [
                'clientDataJSON' => $this->b64url((string) $clientData),
                'attestationObject' => $this->b64url($attestation),
            ],
        ], 'RSA key');
        $this->assertGreaterThan(0, $id);

        // --- then authenticate with it ---
        $authChallenge = random_bytes(32);
        $_SESSION['webauthn_auth_challenge'] = base64_encode($authChallenge);

        $authClientData = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => $this->b64url($authChallenge),
            'origin' => 'https://localhost',
        ]);
        // rpIdHash | flags (UP|UV) | signCount = 1
        $authenticatorData = hash('sha256', 'localhost', true) . "\x05" . "\x00\x00\x00\x01";

        $signature = '';
        openssl_sign($authenticatorData . hash('sha256', $authClientData, true), $signature, $rsa, OPENSSL_ALGO_SHA256);

        $account = $this->service->verifyAuthentication([
            'rawId' => $this->b64url($credId),
            'response' => [
                'clientDataJSON' => $this->b64url($authClientData),
                'authenticatorData' => $this->b64url($authenticatorData),
                'signature' => $this->b64url($signature),
            ],
        ]);

        $this->assertNotNull($account);
        $this->assertSame($this->userId, $account->id);
    }

    /**
     * Authentication-side counterpart of the registration check above.
     */
    public function testVerifyAuthenticationRejectsAnAssertionWithoutUserVerification(): void
    {
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($rsa);
        $details = openssl_pkey_get_details($rsa);
        $this->assertIsArray($details);

        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;
        $credId = str_repeat("\x44", 16);
        $this->service->verifyRegistration($this->userId, [
            'response' => [
                'clientDataJSON' => $this->b64url((string) json_encode([
                    'type' => 'webauthn.create',
                    'challenge' => $this->b64url($challenge),
                    'origin' => 'https://localhost',
                ])),
                'attestationObject' => $this->b64url($this->buildAttestationObject(
                    'localhost', "\x45", $this->rsaCoseKey($details['rsa']['n'], $details['rsa']['e']), $credId
                )),
            ],
        ], 'RSA key');

        $authChallenge = random_bytes(32);
        $_SESSION['webauthn_auth_challenge'] = base64_encode($authChallenge);
        $authClientData = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => $this->b64url($authChallenge),
            'origin' => 'https://localhost',
        ]);
        // UP only (0x01) — the human was never verified.
        $authenticatorData = hash('sha256', 'localhost', true) . "\x01" . "\x00\x00\x00\x01";
        $signature = '';
        openssl_sign($authenticatorData . hash('sha256', $authClientData, true), $signature, $rsa, OPENSSL_ALGO_SHA256);

        $account = $this->service->verifyAuthentication([
            'rawId' => $this->b64url($credId),
            'response' => [
                'clientDataJSON' => $this->b64url($authClientData),
                'authenticatorData' => $this->b64url($authenticatorData),
                'signature' => $this->b64url($signature),
            ],
        ]);

        $this->assertNull($account);
    }

    /**
     * COSE map for an RSA key: {1:3 (RSA), 3:-257 (RS256), -1:n, -2:e}.
     */
    private function rsaCoseKey(string $modulus, string $exponent): string
    {
        return "\xa4"
            . "\x01\x03"
            . "\x03\x39\x01\x00"
            . "\x20" . $this->cborByteString($modulus)
            . "\x21" . $this->cborByteString($exponent);
    }

    /**
     * Build a fake but structurally valid registration response (WebAuthn "none"
     * attestation) for the given challenge, browser origin, and rpId.
     *
     * @return array<string, mixed>
     */
    private function buildRegistrationResponse(string $challenge, string $origin, string $rpId, string $flags = "\x45"): array
    {
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $this->b64url($challenge),
            'origin' => $origin,
            'crossOrigin' => false,
        ]);

        return [
            'response' => [
                'clientDataJSON' => $this->b64url((string) $clientData),
                'attestationObject' => $this->b64url($this->buildAttestationObject($rpId, $flags)),
            ],
        ];
    }

    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * $flags defaults to UP (0x01) | UV (0x04) | AT (0x40) — a real
     * platform authenticator that verified the human, which is what the
     * service now insists on. Pass "\x41" to simulate presence-only.
     */
    private function buildAttestationObject(
        string $rpId,
        string $flags = "\x45",
        ?string $cose = null,
        ?string $credId = null
    ): string {
        $rpIdHash = hash('sha256', $rpId, true);      // 32 bytes
        $signCount = "\x00\x00\x00\x00";
        $aaguid = str_repeat("\x00", 16);
        $credId ??= str_repeat("\x11", 16);
        $credIdLen = pack('n', strlen($credId));       // 2 bytes, big-endian

        // COSE EC P-256 public key: {1:2, 3:-7, -1:1, -2:x(32), -3:y(32)}
        $x = str_repeat("\x01", 32);
        $y = str_repeat("\x02", 32);
        $cose ??= "\xA5"
            . "\x01\x02"
            . "\x03\x26"
            . "\x20\x01"
            . "\x21\x58\x20" . $x
            . "\x22\x58\x20" . $y;

        $authData = $rpIdHash . $flags . $signCount . $aaguid . $credIdLen . $credId . $cose;

        // CBOR map(3): { "fmt":"none", "attStmt":{}, "authData": bstr(authData) }
        return "\xA3"
            . "\x63" . 'fmt' . "\x64" . 'none'
            . "\x67" . 'attStmt' . "\xA0"
            . "\x68" . 'authData' . $this->cborByteString($authData);
    }

    private function cborByteString(string $bytes): string
    {
        $len = strlen($bytes);
        if ($len < 24) {
            return chr(0x40 | $len) . $bytes;
        }
        if ($len < 256) {
            return "\x58" . chr($len) . $bytes;
        }
        return "\x59" . pack('n', $len) . $bytes;
    }
}
