<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Config\AppClock;

class WebAuthnService
{
    /** authData flag bits (WebAuthn §6.1). */
    private const FLAG_USER_PRESENT = 0x01;
    private const FLAG_USER_VERIFIED = 0x04;
    private const FLAG_ATTESTED_CREDENTIAL_DATA = 0x40;

    /** COSE key labels and values (RFC 8152) used by WebAuthn. */
    private const COSE_KTY = 1;
    private const COSE_ALG = 3;
    private const COSE_KTY_EC2 = 2;
    private const COSE_KTY_RSA = 3;
    private const COSE_ALG_ES256 = -7;
    private const COSE_ALG_RS256 = -257;
    private const COSE_CRV_P256 = 1;
    private const COSE_EC2_CRV = -1;
    private const COSE_EC2_X = -2;
    private const COSE_EC2_Y = -3;
    private const COSE_RSA_N = -1;
    private const COSE_RSA_E = -2;

    /**
     * How long a challenge stays answerable, in seconds (issue #1131). The
     * browser's own `timeout` (60 s, below) is a request to the client; this
     * is the server's bound, generous enough for a slow authenticator or a
     * PIN typed twice, short enough that a challenge left in a session is
     * not a standing invitation.
     */
    public const CHALLENGE_TTL_SECONDS = 300;

    /** Session keys of the two pending challenges. */
    private const SESSION_REGISTRATION_CHALLENGE = 'webauthn_challenge';
    private const SESSION_REGISTRATION_USER = 'webauthn_user_id';
    private const SESSION_AUTHENTICATION_CHALLENGE = 'webauthn_auth_challenge';

    /** Size of the opaque user handle, in bytes (the WebAuthn spec allows up to 64). */
    private const USER_HANDLE_BYTES = 32;

    public function __construct(
        private WebAuthnCredentialRepository $credentialRepo,
        private UserAccountRepository $userAccountRepo,
        private string $rpId,
        private string $rpName,
        private string $rpOrigin
    ) {
    }

    /**
     * Generate registration options for a user.
     * Returns a JSON-serializable array to pass to navigator.credentials.create().
     * Stores the challenge, with its issue time, in the session for verification.
     *
     * @return array<string, mixed>
     */
    public function generateRegistrationOptions(int $userAccountId, string $userEmail): array
    {
        $challenge = random_bytes(32);

        $this->storeChallenge(self::SESSION_REGISTRATION_CHALLENGE, $challenge);
        SessionStore::set(self::SESSION_REGISTRATION_USER, $userAccountId);

        // Get existing credentials to exclude
        $existingCredentials = $this->credentialRepo->findByUserAccountId($userAccountId);
        $excludeCredentials = array_map(function (array $cred) {
            return [
                'type' => 'public-key',
                'id' => $this->base64UrlEncode($cred['credential_id']),
            ];
        }, $existingCredentials);

        return [
            'rp' => [
                'name' => $this->rpName,
                'id' => $this->rpId,
            ],
            'user' => [
                'id' => $this->base64UrlEncode($this->userHandle($userAccountId)),
                'name' => $userEmail,
                'displayName' => $userEmail,
            ],
            'challenge' => $this->base64UrlEncode($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257], // RS256
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'residentKey' => 'preferred',
                // 'required', not 'preferred': a passkey that only proves
                // someone touched the device lets anyone holding it
                // unlocked authenticate as its owner. Enforced server-side
                // too — see hasUserVerification().
                'userVerification' => 'required',
            ],
            'excludeCredentials' => $excludeCredentials,
        ];
    }

    /**
     * Verify and store a new credential after the user completes registration.
     *
     * @param array<string, mixed> $clientResponse The response from navigator.credentials.create()
     * @return int Credential ID in the database
     * @throws WebAuthnMalformedRequest if the response cannot be read at all
     * @throws WebAuthnChallengeExpired if the challenge lapsed (or predates issue times)
     * @throws \RuntimeException if verification fails
     */
    public function verifyRegistration(int $userAccountId, array $clientResponse, string $deviceLabel): int
    {
        // Spent before anything is read: one challenge answers one attempt,
        // whatever that attempt turns out to be.
        $storedChallenge = SessionStore::get(self::SESSION_REGISTRATION_CHALLENGE);
        $storedUserId = SessionStore::get(self::SESSION_REGISTRATION_USER);
        SessionStore::remove(self::SESSION_REGISTRATION_CHALLENGE, self::SESSION_REGISTRATION_USER);

        $response = $this->responseFields($clientResponse, ['clientDataJSON', 'attestationObject']);
        $clientData = $this->decodeClientData($response['clientDataJSON']);
        $authData = $this->parseAttestationAuthData($response['attestationObject']);

        if ($storedChallenge === null || $storedUserId !== $userAccountId) {
            throw new \RuntimeException('No pending registration challenge.');
        }
        $challenge = $this->freshChallenge($storedChallenge);

        if ($clientData['type'] !== 'webauthn.create') {
            throw new \RuntimeException('Invalid response type.');
        }
        if (!hash_equals($this->base64UrlEncode($challenge), $clientData['challenge'])) {
            throw new \RuntimeException('Challenge mismatch.');
        }
        if (!$this->isOriginValid($clientData['origin'])) {
            throw new \RuntimeException('Origin mismatch.');
        }

        // The credential must have been created for THIS relying party.
        if (!hash_equals(hash('sha256', $this->rpId, true), $authData['rpIdHash'])) {
            throw new \RuntimeException('Relying party mismatch.');
        }
        if ($authData['credentialId'] === null || $authData['publicKey'] === null) {
            throw new \RuntimeException('No attested credential data.');
        }

        // The authenticator must report it actually verified the human,
        // matching the 'required' asked for in the options above.
        if (($authData['flags'] & self::FLAG_USER_PRESENT) === 0
            || ($authData['flags'] & self::FLAG_USER_VERIFIED) === 0
        ) {
            throw new \RuntimeException('User verification required.');
        }

        return $this->credentialRepo->create(
            $userAccountId,
            $authData['credentialId'],
            $authData['publicKey'],
            $deviceLabel
        );
    }

    /**
     * Generate authentication options.
     * Uses discoverable credentials (no username needed).
     * Stores the challenge, with its issue time, in the session.
     *
     * @return array<string, mixed>
     */
    public function generateAuthenticationOptions(): array
    {
        $challenge = random_bytes(32);

        $this->storeChallenge(self::SESSION_AUTHENTICATION_CHALLENGE, $challenge);

        return [
            'challenge' => $this->base64UrlEncode($challenge),
            'rpId' => $this->rpId,
            'timeout' => 60000,
            'userVerification' => 'required',
            'allowCredentials' => [], // empty for discoverable credentials
        ];
    }

    /**
     * Verify an authentication response.
     *
     * The credential is found by its rawId; the userHandle the browser
     * also returns is never read. That is what lets the opaque handle of
     * issue #1131 replace the old id-derived one without touching a
     * single stored passkey: a key registered under the old handle still
     * signs in.
     *
     * @param array<string, mixed> $clientResponse The response from navigator.credentials.get()
     * @return UserAccount|null The authenticated user, or null if invalid
     * @throws WebAuthnMalformedRequest if the response cannot be read at all
     * @throws WebAuthnChallengeExpired if the challenge lapsed (or predates issue times)
     */
    public function verifyAuthentication(array $clientResponse): ?UserAccount
    {
        $storedChallenge = SessionStore::get(self::SESSION_AUTHENTICATION_CHALLENGE);
        SessionStore::remove(self::SESSION_AUTHENTICATION_CHALLENGE);

        $credentialIdRaw = $this->decodeBase64UrlField($clientResponse['rawId'] ?? null, 'rawId');
        $response = $this->responseFields($clientResponse, ['clientDataJSON', 'authenticatorData', 'signature']);
        $clientData = $this->decodeClientData($response['clientDataJSON']);
        $authenticatorData = $response['authenticatorData'];
        // 32 bytes rpIdHash | 1 byte flags | 4 bytes signCount, as in authData.
        if (strlen($authenticatorData) < 37) {
            throw new WebAuthnMalformedRequest('authenticatorData is truncated.');
        }

        if ($storedChallenge === null) {
            return null;
        }
        $challenge = $this->freshChallenge($storedChallenge);

        $credential = $this->credentialRepo->findByCredentialId($credentialIdRaw);
        if ($credential === null) {
            return null;
        }

        if ($clientData['type'] !== 'webauthn.get') {
            return null;
        }
        if (!hash_equals($this->base64UrlEncode($challenge), $clientData['challenge'])) {
            return null;
        }
        if (!$this->isOriginValid($clientData['origin'])) {
            return null;
        }

        // The assertion must be for this relying party, and must carry
        // real user verification — never presence alone.
        if (!hash_equals(hash('sha256', $this->rpId, true), substr($authenticatorData, 0, 32))) {
            return null;
        }
        if (!$this->hasUserVerification($authenticatorData)) {
            return null;
        }

        $clientDataHash = hash('sha256', $clientData['json'], true);
        $signedData = $authenticatorData . $clientDataHash;

        $publicKey = $credential['public_key'];
        $verified = $this->verifySignature($signedData, $response['signature'], $publicKey);

        if (!$verified) {
            return null;
        }

        // Check and update sign count
        $newSignCount = $this->extractSignCount($authenticatorData);
        $storedSignCount = (int) $credential['sign_count'];

        if ($newSignCount > 0 && $newSignCount <= $storedSignCount) {
            // Possible cloned key — reject
            return null;
        }

        // Update credential
        $this->credentialRepo->updateSignCount((int) $credential['id'], $newSignCount);
        $this->credentialRepo->updateLastUsed((int) $credential['id']);

        // Return the associated user
        return $this->userAccountRepo->findById((int) $credential['user_account_id']);
    }

    /**
     * The account's WebAuthn user handle (`user.id`, issue #1131): 32
     * random bytes, minted the first time the account registers a passkey
     * and reused for every later one, so an authenticator keeps one entry
     * per account. It used to be the sequential account id, zero-padded —
     * which put the account's position in the table, and the size of the
     * unit, on every authenticator and in every synced passkey export.
     *
     * Two option requests racing for an account without one both end up
     * with whichever handle was stored first.
     */
    private function userHandle(int $userAccountId): string
    {
        $handle = $this->userAccountRepo->findWebAuthnUserHandle($userAccountId)
            ?? $this->userAccountRepo->claimWebAuthnUserHandle($userAccountId, random_bytes(self::USER_HANDLE_BYTES));
        if ($handle === null) {
            throw new \RuntimeException('No such account.');
        }

        return $handle;
    }

    private function storeChallenge(string $key, string $challenge): void
    {
        SessionStore::set($key, [
            'challenge' => base64_encode($challenge),
            'issued_at' => AppClock::now()->getTimestamp(),
        ]);
    }

    /**
     * The raw bytes of a stored challenge that is still answerable.
     *
     * A challenge without its issue time — stored before issue #1131, as a
     * bare string — cannot be dated, so it is treated as lapsed rather
     * than trusted for the rest of the session.
     *
     * @throws WebAuthnChallengeExpired
     */
    private function freshChallenge(mixed $stored): string
    {
        if (!is_array($stored) || !is_string($stored['challenge'] ?? null) || !is_int($stored['issued_at'] ?? null)) {
            throw new WebAuthnChallengeExpired();
        }
        if (AppClock::now()->getTimestamp() - $stored['issued_at'] > self::CHALLENGE_TTL_SECONDS) {
            throw new WebAuthnChallengeExpired();
        }
        $challenge = base64_decode($stored['challenge'], true);
        if ($challenge === false || $challenge === '') {
            throw new WebAuthnChallengeExpired();
        }

        return $challenge;
    }

    /**
     * The decoded bytes of `response.<field>` for each named field.
     *
     * @param array<string, mixed> $clientResponse
     * @param list<string> $fields
     * @return array<string, string>
     * @throws WebAuthnMalformedRequest
     */
    private function responseFields(array $clientResponse, array $fields): array
    {
        $response = $clientResponse['response'] ?? null;
        if (!is_array($response)) {
            throw new WebAuthnMalformedRequest('response is not an object.');
        }

        $decoded = [];
        foreach ($fields as $field) {
            $decoded[$field] = $this->decodeBase64UrlField($response[$field] ?? null, 'response.' . $field);
        }

        return $decoded;
    }

    /**
     * clientDataJSON, decoded: a JSON object whose type, challenge and
     * origin are strings, plus the exact bytes the signature covers.
     *
     * @return array{type: string, challenge: string, origin: string, json: string}
     * @throws WebAuthnMalformedRequest
     */
    private function decodeClientData(string $clientDataJSON): array
    {
        $clientData = json_decode($clientDataJSON, true);
        if (!is_array($clientData)) {
            throw new WebAuthnMalformedRequest('clientDataJSON is not a JSON object.');
        }
        foreach (['type', 'challenge', 'origin'] as $key) {
            if (!is_string($clientData[$key] ?? null)) {
                throw new WebAuthnMalformedRequest('clientDataJSON.' . $key . ' is not a string.');
            }
        }

        return [
            'type' => $clientData['type'],
            'challenge' => $clientData['challenge'],
            'origin' => $clientData['origin'],
            'json' => $clientDataJSON,
        ];
    }

    /**
     * Pull the authenticator data out of a CBOR attestation object and
     * split out the credential it attests to. Only the structure is
     * checked here; what it says (which relying party, which flags) is
     * the caller's to judge. credentialId and publicKey are null when the
     * AT flag is not set — there is then nothing to read.
     *
     * @return array{rpIdHash: string, flags: int, credentialId: ?string, publicKey: ?string}
     * @throws WebAuthnMalformedRequest
     */
    private function parseAttestationAuthData(string $attestationObject): array
    {
        try {
            [$decoded] = CborDecoder::decodeFirst($attestationObject);
        } catch (\RuntimeException) {
            throw new WebAuthnMalformedRequest('attestationObject is not valid CBOR.');
        }

        if (!is_array($decoded) || !isset($decoded['authData']) || !is_string($decoded['authData'])) {
            throw new WebAuthnMalformedRequest('attestationObject carries no authData.');
        }

        $authData = $decoded['authData'];

        // authData layout (WebAuthn §6.1):
        //   32 bytes rpIdHash | 1 byte flags | 4 bytes signCount
        //   then, when the AT flag is set, attested credential data:
        //   16 bytes AAGUID | 2 bytes credentialIdLength | credentialId | COSE key
        if (strlen($authData) < 37) {
            throw new WebAuthnMalformedRequest('authData is truncated.');
        }

        $rpIdHash = substr($authData, 0, 32);
        $flags = ord($authData[32]);
        if (($flags & self::FLAG_ATTESTED_CREDENTIAL_DATA) === 0) {
            return ['rpIdHash' => $rpIdHash, 'flags' => $flags, 'credentialId' => null, 'publicKey' => null];
        }

        $offset = 37 + 16; // skip AAGUID
        if (strlen($authData) < $offset + 2) {
            throw new WebAuthnMalformedRequest('Attested credential data is truncated.');
        }

        $credIdLen = (ord($authData[$offset]) << 8) | ord($authData[$offset + 1]);
        $offset += 2;

        if ($credIdLen === 0 || strlen($authData) < $offset + $credIdLen) {
            throw new WebAuthnMalformedRequest('Credential id is empty or truncated.');
        }

        $credentialId = substr($authData, $offset, $credIdLen);
        $offset += $credIdLen;

        // The COSE key is re-encoded from its decoded form so what gets
        // stored is exactly one key and nothing else — the remainder of
        // authData can also carry an extensions map.
        $publicKey = substr($authData, $offset);
        if ($publicKey === '') {
            throw new WebAuthnMalformedRequest('Credential public key is missing.');
        }

        try {
            [$coseKey, $consumed] = CborDecoder::decodeFirst($publicKey);
        } catch (\RuntimeException) {
            throw new WebAuthnMalformedRequest('Credential public key is not valid CBOR.');
        }
        if (!is_array($coseKey)) {
            throw new WebAuthnMalformedRequest('Credential public key is not a COSE map.');
        }

        return [
            'rpIdHash' => $rpIdHash,
            'flags' => $flags,
            'credentialId' => $credentialId,
            'publicKey' => substr($publicKey, 0, $consumed),
        ];
    }

    /**
     * Verify a signature against a COSE public key.
     *
     * The COSE key names its own algorithm, and only the two this service
     * advertises in pubKeyCredParams are accepted — ES256 and RS256. RS256
     * used to be advertised but never actually verifiable, so an
     * authenticator that chose it registered fine and then failed every
     * subsequent login.
     */
    private function verifySignature(string $data, string $signature, string $publicKeyBytes): bool
    {
        try {
            [$coseKey] = CborDecoder::decodeFirst($publicKeyBytes);
        } catch (\RuntimeException) {
            return false;
        }

        if (!is_array($coseKey)) {
            return false;
        }

        $algorithm = $coseKey[self::COSE_ALG] ?? null;
        $digest = match ($algorithm) {
            self::COSE_ALG_ES256, self::COSE_ALG_RS256 => OPENSSL_ALGO_SHA256,
            default => null,
        };
        if ($digest === null) {
            return false;
        }

        $pem = $this->coseKeyToPem($coseKey);
        if ($pem === null) {
            return false;
        }

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }

        return openssl_verify($data, $signature, $key, $digest) === 1;
    }

    /**
     * Convert a decoded COSE public key to PEM. Handles ES256 (EC P-256)
     * and RS256 (RSA).
     *
     * @param array<int|string, mixed> $coseKey
     */
    private function coseKeyToPem(array $coseKey): ?string
    {
        $keyType = $coseKey[self::COSE_KTY] ?? null;

        if ($keyType === self::COSE_KTY_EC2) {
            // Only P-256 pairs with ES256, and the coordinates are fixed-width.
            if (($coseKey[self::COSE_EC2_CRV] ?? null) !== self::COSE_CRV_P256) {
                return null;
            }
            $x = $coseKey[self::COSE_EC2_X] ?? null;
            $y = $coseKey[self::COSE_EC2_Y] ?? null;
            if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                return null;
            }

            return self::toPem($this->buildEcDer("\x04" . $x . $y));
        }

        if ($keyType === self::COSE_KTY_RSA) {
            $modulus = $coseKey[self::COSE_RSA_N] ?? null;
            $exponent = $coseKey[self::COSE_RSA_E] ?? null;
            if (!is_string($modulus) || !is_string($exponent) || $modulus === '' || $exponent === '') {
                return null;
            }

            return self::toPem($this->buildRsaDer($modulus, $exponent));
        }

        return null;
    }

    /**
     * Build ASN.1 DER (SubjectPublicKeyInfo) for an EC P-256 public key.
     */
    private function buildEcDer(string $ecPoint): string
    {
        // SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 }, BIT STRING }
        $header = (string) hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');

        return $header . $ecPoint;
    }

    /**
     * Build ASN.1 DER (SubjectPublicKeyInfo) for an RSA public key from its
     * raw modulus and exponent.
     */
    private function buildRsaDer(string $modulus, string $exponent): string
    {
        $rsaPublicKey = self::derSequence(
            self::derInteger($modulus) . self::derInteger($exponent)
        );

        // AlgorithmIdentifier { rsaEncryption, NULL }
        $algorithm = (string) hex2bin('300d06092a864886f70d0101010500');

        return self::derSequence(
            $algorithm . self::derBitString($rsaPublicKey)
        );
    }

    /**
     * DER INTEGER from a big-endian unsigned byte string. Leading zero
     * bytes are dropped, and one is re-added when the high bit is set so
     * the value is never read back as negative.
     */
    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $contents): string
    {
        return "\x30" . self::derLength(strlen($contents)) . $contents;
    }

    private static function derBitString(string $contents): string
    {
        // Leading 0x00 = "no unused bits in the final byte".
        $contents = "\x00" . $contents;

        return "\x03" . self::derLength(strlen($contents)) . $contents;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function toPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Extract the sign count from authenticator data (bytes 33-36, big-endian uint32).
     */
    private function extractSignCount(string $authData): int
    {
        if (strlen($authData) < 37) {
            return 0;
        }
        return (ord($authData[33]) << 24) |
               (ord($authData[34]) << 16) |
               (ord($authData[35]) << 8) |
               ord($authData[36]);
    }

    /**
     * Whether the authenticator reported that it actually verified the
     * human in front of it (PIN, biometric, …) rather than merely
     * detecting a touch.
     *
     * Both options objects ask for userVerification 'required', but that
     * is a request to the client, not a guarantee — the assertion has to
     * be checked server-side or the requirement is decorative. Without
     * this, a passkey satisfied user-PRESENCE alone, so anyone holding an
     * unlocked device could authenticate as its owner.
     */
    private function hasUserVerification(string $authData): bool
    {
        if (strlen($authData) < 33) {
            return false;
        }

        $flags = ord($authData[32]);

        return ($flags & self::FLAG_USER_PRESENT) !== 0
            && ($flags & self::FLAG_USER_VERIFIED) !== 0;
    }

    /**
     * Validate the client-supplied origin (issue #1131).
     *
     * Both the origin and the configured base_url (rpOrigin) are reduced
     * to scheme://host[:port] — scheme and host lower-cased, path, query
     * and trailing slash dropped, the port omitted when it is the scheme's
     * default — and must then be equal. Two exceptions, and only two:
     *
     *  - a base_url typed with http:// also accepts https://<rpId> on the
     *    default port, since browsers only offer passkeys over https and a
     *    base_url missing its "s" must not lock the site out of them;
     *  - when rpId is localhost or 127.0.0.1 (development), that same host
     *    is accepted on any port, over http or https.
     *
     * Any other port, scheme or host is refused, and so is an origin that
     * does not parse as one. It used to accept any https origin whose host
     * matched, on any port — another service on the same host, on another
     * port, could then collect assertions for this site.
     */
    private function isOriginValid(string $origin): bool
    {
        $presented = self::normalizeOrigin($origin);
        if ($presented === null) {
            return false;
        }

        $expected = self::normalizeOrigin($this->rpOrigin);
        if ($expected !== null && $presented['origin'] === $expected['origin']) {
            return true;
        }

        $rpHost = strtolower($this->rpId);
        if ($expected !== null && $expected['scheme'] === 'http'
            && $presented['origin'] === 'https://' . $rpHost
        ) {
            return true;
        }

        return in_array($rpHost, ['localhost', '127.0.0.1'], true) && $presented['host'] === $rpHost;
    }

    /**
     * scheme://host[:port] of an http(s) URL, or null when it is not one.
     *
     * @return array{origin: string, scheme: string, host: string}|null
     */
    private static function normalizeOrigin(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $defaultPort = match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };
        if ($defaultPort === null || $host === '') {
            return null;
        }

        $port = $parts['port'] ?? $defaultPort;
        if ($port < 1) {
            return null;
        }

        return [
            'origin' => $scheme . '://' . $host . ($port === $defaultPort ? '' : ':' . $port),
            'scheme' => $scheme,
            'host' => $host,
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode a base64url field of the client's response — the plain
     * base64 alphabet and padding are tolerated, nothing else is. An
     * absent, non-string, empty or undecodable value is a malformed
     * request.
     *
     * @throws WebAuthnMalformedRequest
     */
    private function decodeBase64UrlField(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_\-+\/]+={0,2}$/', $value) !== 1) {
            throw new WebAuthnMalformedRequest($field . ' is not a base64url string.');
        }

        $data = rtrim(strtr($value, '-_', '+/'), '=');
        $decoded = strlen($data) % 4 === 1
            ? false
            : base64_decode(str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '='), true);
        if ($decoded === false || $decoded === '') {
            throw new WebAuthnMalformedRequest($field . ' is not valid base64url.');
        }

        return $decoded;
    }
}
