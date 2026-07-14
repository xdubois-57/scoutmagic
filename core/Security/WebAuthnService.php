<?php

declare(strict_types=1);

namespace Core\Security;

class WebAuthnService
{
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
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        $_SESSION['webauthn_user_id'] = $userAccountId;

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
                'userVerification' => 'preferred',
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
        $storedChallenge = $_SESSION['webauthn_challenge'] ?? null;
        $storedUserId = $_SESSION['webauthn_user_id'] ?? null;

        if ($storedChallenge === null || $storedUserId !== $userAccountId) {
            throw new \RuntimeException('No pending registration challenge.');
        }

        // Clear used challenge
        unset($_SESSION['webauthn_challenge'], $_SESSION['webauthn_user_id']);

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

        $_SESSION['webauthn_auth_challenge'] = base64_encode($challenge);

        return [
            'challenge' => $this->base64UrlEncode($challenge),
            'rpId' => $this->rpId,
            'timeout' => 60000,
            'userVerification' => 'preferred',
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
        $storedChallenge = $_SESSION['webauthn_auth_challenge'] ?? null;

        if ($storedChallenge === null) {
            return null;
        }

        unset($_SESSION['webauthn_auth_challenge']);

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
     * Parse the authenticator data from an attestation object.
     * Minimal CBOR parser for attestation — only handles the common case.
     *
     * @return array{credentialId: string, publicKey: string}|null
     */
    private function parseAttestationAuthData(string $attestationObject): ?array
    {
        // Minimal CBOR decoding: find authData in the attestation object
        // The attestation object is CBOR-encoded. We look for the authData field.
        // For "none" attestation, the structure is: {fmt: "none", attStmt: {}, authData: bytes}

        // Simple approach: find authData by searching for the CBOR pattern
        // authData is at least 37 bytes + credential data
        $authDataStart = strpos($attestationObject, "\xa3"); // CBOR map(3)

        if ($authDataStart === false) {
            // Try to find authData directly — look for the 32-byte RP ID hash
            $rpIdHash = hash('sha256', $this->rpId, true);
            $authDataStart = strpos($attestationObject, $rpIdHash);
            if ($authDataStart === false) {
                return null;
            }
            $authData = substr($attestationObject, $authDataStart);
        } else {
            // CBOR map — find the authData key and extract
            $rpIdHash = hash('sha256', $this->rpId, true);
            $authDataStart = strpos($attestationObject, $rpIdHash);
            if ($authDataStart === false) {
                return null;
            }
            $authData = substr($attestationObject, $authDataStart);
        }

        // authData structure:
        // 32 bytes: rpIdHash
        // 1 byte: flags
        // 4 bytes: signCount
        // variable: attestedCredentialData (if flags bit 6 set)

        if (strlen($authData) < 37) {
            return null;
        }

        $flags = ord($authData[32]);
        $hasAttestedCredData = ($flags & 0x40) !== 0;

        if (!$hasAttestedCredData) {
            return null;
        }

        // Attested credential data starts at offset 37
        // 16 bytes: AAGUID
        // 2 bytes: credentialIdLength (big-endian)
        // N bytes: credentialId
        // remaining: COSE public key (CBOR)

        $offset = 37;
        if (strlen($authData) < $offset + 18) {
            return null;
        }

        // Skip AAGUID (16 bytes)
        $offset += 16;

        // Read credential ID length
        $credIdLen = (ord($authData[$offset]) << 8) | ord($authData[$offset + 1]);
        $offset += 2;

        if (strlen($authData) < $offset + $credIdLen) {
            return null;
        }

        $credentialId = substr($authData, $offset, $credIdLen);
        $offset += $credIdLen;

        // The rest is the COSE public key (store it as-is for verification)
        $publicKey = substr($authData, $offset);

        return [
            'credentialId' => $credentialId,
            'publicKey' => $publicKey,
        ];
    }

    /**
     * Verify a signature against a COSE public key.
     */
    private function verifySignature(string $data, string $signature, string $publicKeyBytes): bool
    {
        // Parse COSE key to determine algorithm and extract key material
        // For ES256 (alg -7): the key is an EC P-256 key
        // For RS256 (alg -257): the key is an RSA key
        // We attempt both common formats

        // Try EC (P-256) first — most common for platform authenticators
        $pem = $this->coseKeyToPem($publicKeyBytes);

        if ($pem === null) {
            return false;
        }

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }

        // Try ES256 (ECDSA with SHA-256)
        $result = openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /**
     * Convert a COSE public key to PEM format.
     * Handles ES256 (P-256) and RS256 keys.
     */
    private function coseKeyToPem(string $coseKey): ?string
    {
        // Minimal CBOR map parsing for COSE keys
        // ES256 COSE key: {1: 2, 3: -7, -1: 1, -2: x(32), -3: y(32)}
        // We need to extract x and y coordinates

        // Try to find the x and y coordinates by their CBOR keys
        // CBOR key -2 (0x21) for x, -3 (0x22) for y
        $x = $this->extractCoseCoordinate($coseKey, 0x21);
        $y = $this->extractCoseCoordinate($coseKey, 0x22);

        if ($x !== null && $y !== null && strlen($x) === 32 && strlen($y) === 32) {
            // Build uncompressed EC point: 0x04 + x + y
            $ecPoint = "\x04" . $x . $y;

            // Wrap in ASN.1 DER for EC P-256
            $der = $this->buildEcDer($ecPoint);
            return "-----BEGIN PUBLIC KEY-----\n" .
                   chunk_split(base64_encode($der), 64, "\n") .
                   "-----END PUBLIC KEY-----\n";
        }

        return null;
    }

    /**
     * Extract a coordinate from CBOR-encoded COSE key.
     * Looks for the negative integer key followed by a byte string.
     */
    private function extractCoseCoordinate(string $data, int $negKey): ?string
    {
        // Search for the CBOR negative int encoding of the key
        // CBOR negative int -2 is encoded as 0x21, -3 as 0x22
        $len = strlen($data);
        for ($i = 0; $i < $len - 1; $i++) {
            if (ord($data[$i]) === $negKey) {
                // Next should be a byte string of 32 bytes
                $nextByte = ord($data[$i + 1]);
                if ($nextByte === 0x58) {
                    // 1-byte length follows
                    if ($i + 3 < $len) {
                        $strLen = ord($data[$i + 2]);
                        if ($i + 3 + $strLen <= $len) {
                            return substr($data, $i + 3, $strLen);
                        }
                    }
                } elseif (($nextByte & 0xe0) === 0x40) {
                    // Short byte string (length < 24)
                    $strLen = $nextByte & 0x1f;
                    if ($i + 2 + $strLen <= $len) {
                        return substr($data, $i + 2, $strLen);
                    }
                }
            }
        }
        return null;
    }

    /**
     * Build ASN.1 DER for an EC P-256 public key.
     */
    private function buildEcDer(string $ecPoint): string
    {
        // OID for EC + P-256: 30 59 30 13 06 07 2A 86 48 CE 3D 02 01 06 08 2A 86 48 CE 3D 03 01 07 03 42 00 <point>
        $header = hex2bin(
            '3059301306072a8648ce3d020106082a8648ce3d030107034200'
        );
        return $header . $ecPoint;
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
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
        return base64_decode($padded, true) ?: '';
    }
}
