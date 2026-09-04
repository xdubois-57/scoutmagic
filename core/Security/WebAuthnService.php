<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

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
     * Stores the challenge in the session for verification.
     *
     * @return array<string, mixed>
     */
    public function generateRegistrationOptions(int $userAccountId, string $userEmail): array
    {
        $challenge = random_bytes(32);

        // Store challenge in session for later verification
        SessionStore::set('webauthn_challenge', base64_encode($challenge));
        SessionStore::set('webauthn_user_id', $userAccountId);

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
                'id' => $this->base64UrlEncode(str_pad((string) $userAccountId, 64, '0', STR_PAD_LEFT)),
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
     * @throws \RuntimeException if verification fails
     */
    public function verifyRegistration(int $userAccountId, array $clientResponse, string $deviceLabel): int
    {
        // Retrieve stored challenge
        $storedChallenge = SessionStore::get('webauthn_challenge');
        $storedUserId = SessionStore::get('webauthn_user_id');

        if ($storedChallenge === null || $storedUserId !== $userAccountId) {
            throw new \RuntimeException('No pending registration challenge.');
        }

        // Clear used challenge
        SessionStore::remove('webauthn_challenge', 'webauthn_user_id');

        // Decode client response
        $clientDataJSON = $this->base64UrlDecode($clientResponse['response']['clientDataJSON']);
        $clientData = json_decode($clientDataJSON, true);

        if ($clientData === null) {
            throw new \RuntimeException('Invalid clientDataJSON.');
        }

        // Verify type
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new \RuntimeException('Invalid response type.');
        }

        // Verify challenge
        $expectedChallenge = $this->base64UrlEncode(base64_decode($storedChallenge));
        if (($clientData['challenge'] ?? '') !== $expectedChallenge) {
            throw new \RuntimeException('Challenge mismatch.');
        }

        // Verify origin
        if (!$this->isOriginValid((string) ($clientData['origin'] ?? ''))) {
            throw new \RuntimeException('Origin mismatch.');
        }

        // Decode attestation object
        $attestationObject = $this->base64UrlDecode($clientResponse['response']['attestationObject']);
        $authData = $this->parseAttestationAuthData($attestationObject);

        if ($authData === null) {
            throw new \RuntimeException('Failed to parse attestation data.');
        }

        // The authenticator must report it actually verified the human,
        // matching the 'required' asked for in the options above.
        if (($authData['flags'] & self::FLAG_USER_PRESENT) === 0
            || ($authData['flags'] & self::FLAG_USER_VERIFIED) === 0
        ) {
            throw new \RuntimeException('User verification required.');
        }

        // Extract credential ID and public key from authData
        $credentialId = $authData['credentialId'];
        $publicKey = $authData['publicKey'];

        // Store credential
        return $this->credentialRepo->create(
            $userAccountId,
            $credentialId,
            $publicKey,
            $deviceLabel
        );
    }

    /**
     * Generate authentication options.
     * Uses discoverable credentials (no username needed).
     * Stores the challenge in the session.
     *
     * @return array<string, mixed>
     */
    public function generateAuthenticationOptions(): array
    {
        $challenge = random_bytes(32);

        SessionStore::set('webauthn_auth_challenge', base64_encode($challenge));

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
     * @param array<string, mixed> $clientResponse The response from navigator.credentials.get()
     * @return UserAccount|null The authenticated user, or null if invalid
     */
    public function verifyAuthentication(array $clientResponse): ?UserAccount
    {
        $storedChallenge = SessionStore::get('webauthn_auth_challenge');

        if ($storedChallenge === null) {
            return null;
        }

        SessionStore::remove('webauthn_auth_challenge');

        // Find credential by ID
        $credentialIdRaw = $this->base64UrlDecode($clientResponse['rawId'] ?? '');
        $credential = $this->credentialRepo->findByCredentialId($credentialIdRaw);

        if ($credential === null) {
            return null;
        }

        // Decode client response
        $clientDataJSON = $this->base64UrlDecode($clientResponse['response']['clientDataJSON'] ?? '');
        $clientData = json_decode($clientDataJSON, true);

        if ($clientData === null) {
            return null;
        }

        // Verify type
        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            return null;
        }

        // Verify challenge
        $expectedChallenge = $this->base64UrlEncode(base64_decode($storedChallenge));
        if (($clientData['challenge'] ?? '') !== $expectedChallenge) {
            return null;
        }

        // Verify origin
        if (!$this->isOriginValid((string) ($clientData['origin'] ?? ''))) {
            return null;
        }

        // Verify signature using public key
        $authenticatorData = $this->base64UrlDecode($clientResponse['response']['authenticatorData'] ?? '');
        $signature = $this->base64UrlDecode($clientResponse['response']['signature'] ?? '');

        // The assertion must be for this relying party, and must carry
        // real user verification — never presence alone.
        if (strlen($authenticatorData) < 37
            || !hash_equals(hash('sha256', $this->rpId, true), substr($authenticatorData, 0, 32))
        ) {
            return null;
        }
        if (!$this->hasUserVerification($authenticatorData)) {
            return null;
        }

        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $signedData = $authenticatorData . $clientDataHash;

        $publicKey = $credential['public_key'];
        $verified = $this->verifySignature($signedData, $signature, $publicKey);

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
     * Pull the authenticator data out of a CBOR attestation object and
     * split out the credential it attests to.
     *
     * @return array{credentialId: string, publicKey: string, flags: int}|null
     */
    private function parseAttestationAuthData(string $attestationObject): ?array
    {
        try {
            [$decoded] = CborDecoder::decodeFirst($attestationObject);
        } catch (\RuntimeException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['authData']) || !is_string($decoded['authData'])) {
            return null;
        }

        $authData = $decoded['authData'];

        // authData layout (WebAuthn §6.1):
        //   32 bytes rpIdHash | 1 byte flags | 4 bytes signCount
        //   then, when the AT flag is set, attested credential data:
        //   16 bytes AAGUID | 2 bytes credentialIdLength | credentialId | COSE key
        if (strlen($authData) < 37) {
            return null;
        }

        // The credential must have been created for THIS relying party.
        if (!hash_equals(hash('sha256', $this->rpId, true), substr($authData, 0, 32))) {
            return null;
        }

        $flags = ord($authData[32]);
        if (($flags & self::FLAG_ATTESTED_CREDENTIAL_DATA) === 0) {
            return null;
        }

        $offset = 37 + 16; // skip AAGUID
        if (strlen($authData) < $offset + 2) {
            return null;
        }

        $credIdLen = (ord($authData[$offset]) << 8) | ord($authData[$offset + 1]);
        $offset += 2;

        if ($credIdLen === 0 || strlen($authData) < $offset + $credIdLen) {
            return null;
        }

        $credentialId = substr($authData, $offset, $credIdLen);
        $offset += $credIdLen;

        // The COSE key is re-encoded from its decoded form so what gets
        // stored is exactly one key and nothing else — the remainder of
        // authData can also carry an extensions map.
        $publicKey = substr($authData, $offset);
        if ($publicKey === '') {
            return null;
        }

        try {
            [$coseKey, $consumed] = CborDecoder::decodeFirst($publicKey);
        } catch (\RuntimeException) {
            return null;
        }
        if (!is_array($coseKey)) {
            return null;
        }
        $publicKey = substr($publicKey, 0, $consumed);

        return [
            'credentialId' => $credentialId,
            'publicKey' => $publicKey,
            'flags' => $flags,
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
     * Validate the client-supplied origin.
     *
     * Accepts an exact match with the configured rpOrigin, or — more robustly —
     * any origin whose host equals the rpId (case-insensitive). This tolerates
     * scheme/port differences between the configured base_url and the actual
     * host the site is served from (e.g. http://localhost:8000 in development
     * versus an https base_url). For non-local hosts, https is still required so
     * a real domain cannot be downgraded.
     */
    private function isOriginValid(string $origin): bool
    {
        if ($origin === '') {
            return false;
        }
        if ($origin === $this->rpOrigin) {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        $scheme = parse_url($origin, PHP_URL_SCHEME);
        if (!is_string($host) || $host === '') {
            return false;
        }
        if (strcasecmp($host, $this->rpId) !== 0) {
            return false;
        }

        $isLocal = in_array(strtolower($host), ['localhost', '127.0.0.1'], true);

        return $isLocal || $scheme === 'https';
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'),
            strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
        return base64_decode($padded, true) ?: '';
    }
}
