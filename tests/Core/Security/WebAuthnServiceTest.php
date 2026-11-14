<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Security\WebAuthnChallengeExpired;
use Core\Security\WebAuthnCredentialRepository;
use Core\Security\WebAuthnMalformedRequest;
use Core\Security\WebAuthnService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

#[\PHPUnit\Framework\Attributes\Group('database')]
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
        $result = $this->service->verifyAuthentication(self::wellFormedAssertion());
        $this->assertNull($result);
    }

    public function testVerifyAuthenticationReturnsNullForUnknownCredential(): void
    {
        // Store a challenge
        $this->storeChallenge('webauthn_auth_challenge', random_bytes(32));

        // Plain base64 (with padding) is tolerated alongside base64url.
        $result = $this->service->verifyAuthentication([
            'rawId' => base64_encode(random_bytes(32)),
            'response' => [
                'clientDataJSON' => base64_encode(
                    '{"type":"webauthn.get","challenge":"x","origin":"https://localhost"}'
                ),
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

        $this->service->verifyRegistration(
            $this->userId,
            self::buildRegistrationResponse(random_bytes(32), 'https://localhost', 'localhost'),
            'Test Key'
        );
    }

    public function testVerifyRegistrationStoresCredentialForValidNoneAttestation(): void
    {
        $challenge = random_bytes(32);
        $this->storeChallenge('webauthn_challenge', $challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $response = self::buildRegistrationResponse($challenge, 'https://localhost', 'localhost');
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
        $this->storeChallenge('webauthn_challenge', $challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $response = self::buildRegistrationResponse($challenge, 'http://localhost:8000', 'localhost');
        $id = $this->service->verifyRegistration($this->userId, $response, 'Dev Key');

        $this->assertGreaterThan(0, $id);
    }

    public function testVerifyRegistrationRejectsForeignOrigin(): void
    {
        $challenge = random_bytes(32);
        $this->storeChallenge('webauthn_challenge', $challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $response = self::buildRegistrationResponse($challenge, 'https://evil.example.com', 'localhost');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Origin mismatch.');
        $this->service->verifyRegistration($this->userId, $response, 'Bad Key');
    }

    public function testVerifyRegistrationRejectsChallengeMismatch(): void
    {
        $this->storeChallenge('webauthn_challenge', random_bytes(32));
        $_SESSION['webauthn_user_id'] = $this->userId;

        // clientData carries a different challenge than the one stored.
        $response = self::buildRegistrationResponse(random_bytes(32), 'https://localhost', 'localhost');

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
        $this->storeChallenge('webauthn_challenge', $challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        // UP | AT, but no UV.
        $response = self::buildRegistrationResponse($challenge, 'https://localhost', 'localhost', "\x41");

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
        $this->storeChallenge('webauthn_challenge', $challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $response = self::buildRegistrationResponse($challenge, 'https://localhost', 'evil.example.com');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Relying party mismatch.');
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

        $cose = self::rsaCoseKey($details['rsa']['n'], $details['rsa']['e']);

        // --- register it ---
        $challenge = random_bytes(32);
        $this->storeChallenge('webauthn_challenge', $challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $credId = str_repeat("\x33", 16);
        $attestation = self::buildAttestationObject('localhost', "\x45", $cose, $credId);
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => self::b64url($challenge),
            'origin' => 'https://localhost',
        ]);
        $id = $this->service->verifyRegistration($this->userId, [
            'response' => [
                'clientDataJSON' => self::b64url((string) $clientData),
                'attestationObject' => self::b64url($attestation),
            ],
        ], 'RSA key');
        $this->assertGreaterThan(0, $id);

        // --- then authenticate with it ---
        $authChallenge = random_bytes(32);
        $this->storeChallenge('webauthn_auth_challenge', $authChallenge);

        $authClientData = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => self::b64url($authChallenge),
            'origin' => 'https://localhost',
        ]);
        // rpIdHash | flags (UP|UV) | signCount = 1
        $authenticatorData = hash('sha256', 'localhost', true) . "\x05" . "\x00\x00\x00\x01";

        $signature = '';
        openssl_sign($authenticatorData . hash('sha256', $authClientData, true), $signature, $rsa, OPENSSL_ALGO_SHA256);

        $account = $this->service->verifyAuthentication([
            'rawId' => self::b64url($credId),
            'response' => [
                'clientDataJSON' => self::b64url($authClientData),
                'authenticatorData' => self::b64url($authenticatorData),
                'signature' => self::b64url($signature),
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
        $this->storeChallenge('webauthn_challenge', $challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;
        $credId = str_repeat("\x44", 16);
        $this->service->verifyRegistration($this->userId, [
            'response' => [
                'clientDataJSON' => self::b64url((string) json_encode([
                    'type' => 'webauthn.create',
                    'challenge' => self::b64url($challenge),
                    'origin' => 'https://localhost',
                ])),
                'attestationObject' => self::b64url(self::buildAttestationObject(
                    'localhost', "\x45", self::rsaCoseKey($details['rsa']['n'], $details['rsa']['e']), $credId
                )),
            ],
        ], 'RSA key');

        $authChallenge = random_bytes(32);
        $this->storeChallenge('webauthn_auth_challenge', $authChallenge);
        $authClientData = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => self::b64url($authChallenge),
            'origin' => 'https://localhost',
        ]);
        // UP only (0x01) — the human was never verified.
        $authenticatorData = hash('sha256', 'localhost', true) . "\x01" . "\x00\x00\x00\x01";
        $signature = '';
        openssl_sign($authenticatorData . hash('sha256', $authClientData, true), $signature, $rsa, OPENSSL_ALGO_SHA256);

        $account = $this->service->verifyAuthentication([
            'rawId' => self::b64url($credId),
            'response' => [
                'clientDataJSON' => self::b64url($authClientData),
                'authenticatorData' => self::b64url($authenticatorData),
                'signature' => self::b64url($signature),
            ],
        ]);

        $this->assertNull($account);
    }

    // ---- Origin (issue #1131) ------------------------------------------

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function origins(): iterable
    {
        $site = 'https://scouts.example.be';
        yield 'exact origin' => [$site, $site, true];
        yield 'base_url with a trailing slash' => [$site . '/', $site, true];
        yield 'base_url with a path and query' => [$site . '/portail/?x=1', $site, true];
        yield 'case of scheme and host' => ['https://Scouts.Example.BE', 'HTTPS://scouts.example.be', true];
        yield 'default port written out in the origin' => [$site, $site . ':443', true];
        yield 'default port written out in base_url' => [$site . ':443', $site, true];
        yield 'another port in production' => [$site, $site . ':8443', false];
        yield 'the configured non-default port' => [$site . ':8443', $site . ':8443', true];
        yield 'default port when base_url names another' => [$site . ':8443', $site, false];
        yield 'http base_url, https origin' => ['http://scouts.example.be', $site, true];
        yield 'http base_url, https origin on 443' => ['http://scouts.example.be', $site . ':443', true];
        yield 'http base_url, http origin' => ['http://scouts.example.be', 'http://scouts.example.be', true];
        yield 'http base_url, https origin on another port' => ['http://scouts.example.be', $site . ':8443', false];
        yield 'http origin when base_url is https' => [$site, 'http://scouts.example.be', false];
        yield 'another host' => [$site, 'https://evil.example.com', false];
        yield 'a subdomain' => [$site, 'https://sub.scouts.example.be', false];
        yield 'localhost on any port' => ['https://localhost', 'http://localhost:8000', true];
        yield 'localhost over https on any port' => ['http://localhost', 'https://localhost:3000', true];
        yield '127.0.0.1 on any port' => ['http://127.0.0.1:8000', 'http://127.0.0.1:9999', true];
        yield 'localhost tolerance is for localhost only' => ['http://localhost:8000', 'http://127.0.0.1:8000', false];
        yield 'no dev tolerance for a real host' => [
            'http://scouts.example.be',
            'http://scouts.example.be:8000',
            false,
        ];
        yield 'malformed: no scheme' => [$site, 'scouts.example.be', false];
        yield 'malformed: no host' => [$site, 'https://', false];
        yield 'malformed: null origin' => [$site, 'null', false];
        yield 'malformed: empty' => [$site, '', false];
        yield 'malformed: other scheme' => [$site, 'ftp://scouts.example.be', false];
        yield 'malformed: credentials' => [$site, 'https://user@scouts.example.be', false];
        yield 'malformed: port out of range' => [$site, 'https://scouts.example.be:99999', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('origins')]
    public function testTheOriginIsComparedNormalisedWithTheBaseUrl(
        string $baseUrl,
        string $origin,
        bool $accepted
    ): void
    {
        $rpId = (string) parse_url($baseUrl, PHP_URL_HOST);
        $service = new WebAuthnService($this->credentialRepo, $this->userRepo, $rpId, 'Test Scout', $baseUrl);
        $challenge = random_bytes(32);
        $this->storeChallenge('webauthn_challenge', $challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        try {
            $service->verifyRegistration(
                $this->userId,
                self::buildRegistrationResponse($challenge, $origin, $rpId),
                'Key'
            );
            $this->assertTrue($accepted, "$origin was accepted for $baseUrl");
        } catch (WebAuthnMalformedRequest|WebAuthnChallengeExpired $e) {
            $this->fail('Unexpected ' . $e::class . ': ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->assertSame('Origin mismatch.', $e->getMessage());
            $this->assertFalse($accepted, "$origin was refused for $baseUrl");
        }
    }

    public function testAnAssertionFromAnotherPortIsRefusedInProduction(): void
    {
        $service = new WebAuthnService(
            $this->credentialRepo,
            $this->userRepo,
            'scouts.example.be',
            'Test Scout',
            'https://scouts.example.be'
        );
        $key = $this->storeEs256Credential("\x51");

        $this->storeChallenge('webauthn_auth_challenge', $challenge = random_bytes(32));
        $this->assertNull($service->verifyAuthentication(
            self::signAssertion($key, $challenge, 'https://scouts.example.be:8443', 'scouts.example.be', 1)
        ));

        $this->storeChallenge('webauthn_auth_challenge', $challenge = random_bytes(32));
        $account = $service->verifyAuthentication(
            self::signAssertion($key, $challenge, 'https://scouts.example.be', 'scouts.example.be', 2)
        );
        $this->assertSame($this->userId, $account?->id);
    }

    // ---- Challenge expiry (issue #1131) ----------------------------------

    public function testAFreshRegistrationChallengeIsAccepted(): void
    {
        $challenge = random_bytes(32);
        $this->storeChallenge('webauthn_challenge', $challenge, time() - WebAuthnService::CHALLENGE_TTL_SECONDS + 5);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $id = $this->service->verifyRegistration(
            $this->userId,
            self::buildRegistrationResponse($challenge, 'https://localhost', 'localhost'),
            'Key'
        );

        $this->assertGreaterThan(0, $id);
    }

    public function testARegistrationChallengeOlderThanFiveMinutesIsRefusedAndSpent(): void
    {
        $this->assertSame(300, WebAuthnService::CHALLENGE_TTL_SECONDS);
        $challenge = random_bytes(32);
        $this->storeChallenge('webauthn_challenge', $challenge, time() - WebAuthnService::CHALLENGE_TTL_SECONDS - 1);
        $_SESSION['webauthn_user_id'] = $this->userId;

        try {
            $this->service->verifyRegistration(
                $this->userId,
                self::buildRegistrationResponse($challenge, 'https://localhost', 'localhost'),
                'Key'
            );
            $this->fail('An expired challenge was accepted.');
        } catch (WebAuthnChallengeExpired $e) {
            $this->assertSame('Le délai est dépassé. Veuillez réessayer.', $e::USER_MESSAGE);
        }

        $this->assertArrayNotHasKey('webauthn_challenge', $_SESSION);
        $this->assertArrayNotHasKey('webauthn_user_id', $_SESSION);
        $this->assertSame([], $this->credentialRepo->findByUserAccountId($this->userId));
    }

    /** A challenge stored before issue #1131 carries no issue time: it cannot be dated. */
    public function testARegistrationChallengeWithoutATimestampIsTreatedAsExpired(): void
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;

        $this->expectException(WebAuthnChallengeExpired::class);
        $this->service->verifyRegistration(
            $this->userId,
            self::buildRegistrationResponse($challenge, 'https://localhost', 'localhost'),
            'Key'
        );
    }

    public function testAnAuthenticationChallengeOlderThanFiveMinutesIsRefusedAndSpent(): void
    {
        $key = $this->storeEs256Credential("\x52");
        $challenge = random_bytes(32);
        $issuedAt = time() - WebAuthnService::CHALLENGE_TTL_SECONDS - 1;
        $this->storeChallenge('webauthn_auth_challenge', $challenge, $issuedAt);

        try {
            $this->service->verifyAuthentication(
                self::signAssertion($key, $challenge, 'https://localhost', 'localhost', 1)
            );
            $this->fail('An expired challenge was accepted.');
        } catch (WebAuthnChallengeExpired) {
            // expected
        }

        $this->assertArrayNotHasKey('webauthn_auth_challenge', $_SESSION);
    }

    public function testAnAuthenticationChallengeWithoutATimestampIsTreatedAsExpired(): void
    {
        $key = $this->storeEs256Credential("\x53");
        $challenge = random_bytes(32);
        $_SESSION['webauthn_auth_challenge'] = base64_encode($challenge);

        $this->expectException(WebAuthnChallengeExpired::class);
        $this->service->verifyAuthentication(
            self::signAssertion($key, $challenge, 'https://localhost', 'localhost', 1)
        );
    }

    public function testAFreshAuthenticationChallengeIsAcceptedOnce(): void
    {
        $key = $this->storeEs256Credential("\x54");
        $challenge = random_bytes(32);
        $issuedAt = time() - WebAuthnService::CHALLENGE_TTL_SECONDS + 5;
        $this->storeChallenge('webauthn_auth_challenge', $challenge, $issuedAt);
        $assertion = self::signAssertion($key, $challenge, 'https://localhost', 'localhost', 1);

        $this->assertSame($this->userId, $this->service->verifyAuthentication($assertion)?->id);
        // Single use: the same answer a second time finds no challenge.
        $this->assertNull($this->service->verifyAuthentication($assertion));
    }

    public function testTheOptionsStoreTheChallengeWithItsIssueTime(): void
    {
        $this->service->generateRegistrationOptions($this->userId, 'test@example.com');
        $this->service->generateAuthenticationOptions();

        foreach (['webauthn_challenge', 'webauthn_auth_challenge'] as $key) {
            $this->assertIsArray($_SESSION[$key]);
            $this->assertIsString($_SESSION[$key]['challenge']);
            $this->assertEqualsWithDelta(time(), $_SESSION[$key]['issued_at'], 2);
        }
    }

    // ---- Opaque user handle (issue #1131) --------------------------------

    public function testTheUserHandleIsRandomStableAndPerAccount(): void
    {
        $first = $this->userHandleFromOptions($this->userId);
        $again = $this->userHandleFromOptions($this->userId);
        $other = $this->userRepo->create('other@example.com');
        $otherHandle = $this->userHandleFromOptions($other->id);

        $this->assertSame(32, strlen($first));
        $this->assertSame($first, $again);
        $this->assertSame($first, $this->userRepo->findWebAuthnUserHandle($this->userId));
        $this->assertNotSame($first, $otherHandle);
        $this->assertSame(32, strlen($otherHandle));
        // Never the sequential id it used to be.
        $this->assertNotSame(str_pad((string) $this->userId, 64, '0', STR_PAD_LEFT), $first);
        $this->assertStringNotContainsString(str_pad((string) $this->userId, 8, '0', STR_PAD_LEFT), $first);
    }

    public function testAnAccountWithoutAHandleGetsOneOnItsFirstOptionsRequest(): void
    {
        $this->assertNull($this->userRepo->findWebAuthnUserHandle($this->userId));

        $handle = $this->userHandleFromOptions($this->userId);

        $this->assertSame($handle, $this->userRepo->findWebAuthnUserHandle($this->userId));
    }

    /**
     * Passkeys registered before issue #1131 carry the old id-derived
     * handle on their authenticator. A login finds the key by its rawId
     * and never reads the handle, so they keep working with no migration.
     */
    public function testAPasskeyRegisteredUnderTheOldHandleStillAuthenticates(): void
    {
        $key = $this->storeEs256Credential("\x55");
        $this->userHandleFromOptions($this->userId); // the account now has a new, different handle
        $challenge = random_bytes(32);
        $this->storeChallenge('webauthn_auth_challenge', $challenge);
        $assertion = self::signAssertion($key, $challenge, 'https://localhost', 'localhost', 1);
        $assertion['response']['userHandle'] = self::b64url(str_pad((string) $this->userId, 64, '0', STR_PAD_LEFT));

        $account = $this->service->verifyAuthentication($assertion);

        $this->assertSame($this->userId, $account?->id);
    }

    // ---- Malformed bodies (issue #1131) ----------------------------------

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>}>
     */
    public static function malformedRegistrations(): iterable
    {
        $clientData = static fn(string $json): \Closure => static function (array $r) use ($json): array {
            $r['response']['clientDataJSON'] = self::b64url($json);
            return $r;
        };
        $attestation = static fn(string $bytes): \Closure => static function (array $r) use ($bytes): array {
            $r['response']['attestationObject'] = self::b64url($bytes);
            return $r;
        };
        $authData = static fn(string $bytes): string => "\xA1\x68authData" . self::cborByteString($bytes);
        $rpIdHash = hash('sha256', 'localhost', true);

        yield 'no response' => [static fn(array $r): array => ['id' => 'x']];
        yield 'response is a string' => [static fn(array $r): array => ['response' => 'abc']];
        yield 'response is a list of nothing' => [static fn(array $r): array => ['response' => []]];
        yield 'clientDataJSON missing' => [static function (array $r): array {
            unset($r['response']['clientDataJSON']);
            return $r;
        }];
        yield 'clientDataJSON is a number' => [static function (array $r): array {
            $r['response']['clientDataJSON'] = 123;
            return $r;
        }];
        yield 'clientDataJSON is an array' => [static function (array $r): array {
            $r['response']['clientDataJSON'] = ['x'];
            return $r;
        }];
        yield 'clientDataJSON is not base64url' => [static function (array $r): array {
            $r['response']['clientDataJSON'] = 'pas du base64 !';
            return $r;
        }];
        yield 'clientDataJSON has an impossible length' => [static function (array $r): array {
            $r['response']['clientDataJSON'] = 'abcde';
            return $r;
        }];
        yield 'clientDataJSON is empty' => [static function (array $r): array {
            $r['response']['clientDataJSON'] = '';
            return $r;
        }];
        yield 'clientDataJSON is not JSON' => [$clientData('not json')];
        yield 'clientDataJSON is a JSON string' => [$clientData('"webauthn.create"')];
        yield 'clientDataJSON type is not a string' => [
            $clientData('{"type":1,"challenge":"x","origin":"https://localhost"}'),
        ];
        yield 'clientDataJSON has no origin' => [$clientData('{"type":"webauthn.create","challenge":"x"}')];
        yield 'clientDataJSON challenge is an object' => [
            $clientData('{"type":"webauthn.create","challenge":{},"origin":"https://localhost"}'),
        ];
        yield 'attestationObject missing' => [static function (array $r): array {
            unset($r['response']['attestationObject']);
            return $r;
        }];
        yield 'attestationObject is not a string' => [static function (array $r): array {
            $r['response']['attestationObject'] = ['fmt' => 'none'];
            return $r;
        }];
        yield 'attestationObject is truncated CBOR' => [static function (array $r): array {
            $bytes = self::buildAttestationObject('localhost');
            $r['response']['attestationObject'] = self::b64url(substr($bytes, 0, intdiv(strlen($bytes), 2)));
            return $r;
        }];
        yield 'attestationObject is not a map' => [$attestation("\x01")];
        yield 'attestationObject has no authData' => [$attestation("\xA1\x63fmt\x64none")];
        yield 'authData is too short' => [$attestation($authData($rpIdHash . "\x45"))];
        yield 'attested credential data is truncated' => [$attestation($authData($rpIdHash . "\x45\x00\x00\x00\x00"))];
        yield 'credential id runs past the end' => [
            $attestation($authData($rpIdHash . "\x45\x00\x00\x00\x00" . str_repeat("\x00", 16) . "\x00\x20abc")),
        ];
        yield 'COSE key is truncated' => [
            $attestation($authData(
                $rpIdHash . "\x45\x00\x00\x00\x00" . str_repeat("\x00", 16) . "\x00\x01\x11" . "\xA5\x01\x02"
            )),
        ];
        yield 'COSE key is missing' => [
            $attestation($authData($rpIdHash . "\x45\x00\x00\x00\x00" . str_repeat("\x00", 16) . "\x00\x01\x11")),
        ];
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $corrupt
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedRegistrations')]
    public function testAMalformedRegistrationIsReportedAsSuch(\Closure $corrupt): void
    {
        $challenge = random_bytes(32);
        $this->storeChallenge('webauthn_challenge', $challenge);
        $_SESSION['webauthn_user_id'] = $this->userId;
        $body = $corrupt(self::buildRegistrationResponse($challenge, 'https://localhost', 'localhost'));

        try {
            $this->service->verifyRegistration($this->userId, $body, 'Key');
            $this->fail('A malformed registration was accepted.');
        } catch (WebAuthnMalformedRequest) {
            // expected — and the challenge it was answering is spent
        }

        $this->assertArrayNotHasKey('webauthn_challenge', $_SESSION);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>}>
     */
    public static function malformedAssertions(): iterable
    {
        $set = static fn(string $field, mixed $value): \Closure =>
            static function (array $r) use ($field, $value): array {
                $r['response'][$field] = $value;
                return $r;
            };
        $unset = static fn(string $field): \Closure => static function (array $r) use ($field): array {
            unset($r['response'][$field]);
            return $r;
        };

        yield 'rawId missing' => [static function (array $r): array {
            unset($r['rawId']);
            return $r;
        }];
        yield 'rawId is a number' => [static function (array $r): array {
            $r['rawId'] = 42;
            return $r;
        }];
        yield 'rawId is not base64url' => [static function (array $r): array {
            $r['rawId'] = '***';
            return $r;
        }];
        yield 'response is a string' => [static function (array $r): array {
            $r['response'] = 'abc';
            return $r;
        }];
        yield 'response missing' => [static function (array $r): array {
            unset($r['response']);
            return $r;
        }];
        yield 'clientDataJSON missing' => [$unset('clientDataJSON')];
        yield 'clientDataJSON is not JSON' => [$set('clientDataJSON', self::b64url('{oops'))];
        yield 'clientDataJSON is a list' => [$set('clientDataJSON', self::b64url('[1,2]'))];
        yield 'authenticatorData missing' => [$unset('authenticatorData')];
        yield 'authenticatorData is a number' => [$set('authenticatorData', 37)];
        yield 'authenticatorData is truncated' => [$set('authenticatorData', self::b64url(random_bytes(36)))];
        yield 'signature missing' => [$unset('signature')];
        yield 'signature is an array' => [$set('signature', ['a'])];
        yield 'signature is empty' => [$set('signature', '')];
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $corrupt
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedAssertions')]
    public function testAMalformedAssertionIsReportedAsSuch(\Closure $corrupt): void
    {
        $this->storeChallenge('webauthn_auth_challenge', random_bytes(32));

        try {
            $this->service->verifyAuthentication($corrupt(self::wellFormedAssertion()));
            $this->fail('A malformed assertion was accepted.');
        } catch (WebAuthnMalformedRequest) {
            // expected
        }

        $this->assertArrayNotHasKey('webauthn_auth_challenge', $_SESSION);
    }

    /**
     * The user.id of a fresh set of registration options, decoded.
     */
    private function userHandleFromOptions(int $userAccountId): string
    {
        $options = $this->service->generateRegistrationOptions($userAccountId, 'test@example.com');

        return (string) base64_decode(strtr($options['user']['id'], '-_', '+/'), true);
    }

    private function storeChallenge(string $key, string $challenge, ?int $issuedAt = null): void
    {
        $_SESSION[$key] = ['challenge' => base64_encode($challenge), 'issued_at' => $issuedAt ?? time()];
    }

    /**
     * An ES256 passkey stored for the test account the way a past
     * registration left it, and the private key that signs for it.
     *
     * @return array{key: \OpenSSLAsymmetricKey, credentialId: string}
     */
    private function storeEs256Credential(string $idByte): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $cose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $x . "\x22\x58\x20" . $y;
        $credentialId = str_repeat($idByte, 16);
        $this->credentialRepo->create($this->userId, $credentialId, $cose, 'Clé existante');

        return ['key' => $key, 'credentialId' => $credentialId];
    }

    /**
     * A signed navigator.credentials.get() answer, flags UP|UV.
     *
     * @param array{key: \OpenSSLAsymmetricKey, credentialId: string} $key
     * @return array<string, mixed>
     */
    private static function signAssertion(
        array $key,
        string $challenge,
        string $origin,
        string $rpId,
        int $count
    ): array
    {
        $clientData = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => self::b64url($challenge),
            'origin' => $origin,
        ]);
        $authenticatorData = hash('sha256', $rpId, true) . "\x05" . pack('N', $count);
        $signature = '';
        $signed = $authenticatorData . hash('sha256', $clientData, true);
        openssl_sign($signed, $signature, $key['key'], OPENSSL_ALGO_SHA256);

        return [
            'id' => self::b64url($key['credentialId']),
            'rawId' => self::b64url($key['credentialId']),
            'response' => [
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authenticatorData),
                'signature' => self::b64url($signature),
                'userHandle' => null,
            ],
            'type' => 'public-key',
        ];
    }

    /**
     * Readable in every field, signed by nobody this site knows.
     *
     * @return array<string, mixed>
     */
    private static function wellFormedAssertion(): array
    {
        return [
            'rawId' => self::b64url(random_bytes(16)),
            'response' => [
                'clientDataJSON' => self::b64url(
                    '{"type":"webauthn.get","challenge":"x","origin":"https://localhost"}'
                ),
                'authenticatorData' => self::b64url(hash('sha256', 'localhost', true) . "\x05\x00\x00\x00\x01"),
                'signature' => self::b64url(random_bytes(64)),
            ],
        ];
    }

    /**
     * COSE map for an RSA key: {1:3 (RSA), 3:-257 (RS256), -1:n, -2:e}.
     */
    private static function rsaCoseKey(string $modulus, string $exponent): string
    {
        return "\xa4"
            . "\x01\x03"
            . "\x03\x39\x01\x00"
            . "\x20" . self::cborByteString($modulus)
            . "\x21" . self::cborByteString($exponent);
    }

    /**
     * Build a fake but structurally valid registration response (WebAuthn "none"
     * attestation) for the given challenge, browser origin, and rpId.
     *
     * @return array<string, mixed>
     */
    private static function buildRegistrationResponse(
        string $challenge,
        string $origin,
        string $rpId,
        string $flags = "\x45"
    ): array
    {
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => self::b64url($challenge),
            'origin' => $origin,
            'crossOrigin' => false,
        ]);

        return [
            'response' => [
                'clientDataJSON' => self::b64url((string) $clientData),
                'attestationObject' => self::b64url(self::buildAttestationObject($rpId, $flags)),
            ],
        ];
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * $flags defaults to UP (0x01) | UV (0x04) | AT (0x40) — a real
     * platform authenticator that verified the human, which is what the
     * service now insists on. Pass "\x41" to simulate presence-only.
     */
    private static function buildAttestationObject(
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
            . "\x68" . 'authData' . self::cborByteString($authData);
    }

    private static function cborByteString(string $bytes): string
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
