<?php

declare(strict_types=1);

namespace Core\Security;

class SecretManager
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const KEY_LENGTH = 32;

    public function __construct(
        private string $masterKeyPath,
        private string $secretsPath
    ) {
    }

    /**
     * Check whether the site has been initialized (secrets file exists and is readable).
     */
    public function isInitialized(): bool
    {
        return file_exists($this->secretsPath) && is_readable($this->secretsPath)
            && file_exists($this->masterKeyPath) && is_readable($this->masterKeyPath);
    }

    /**
     * Generate a new master key file. Only called during installation.
     * Creates parent directories if needed. Sets file permissions to 0600.
     * Throws if master.key already exists (safety: never overwrite).
     */
    public function generateMasterKey(): void
    {
        if (file_exists($this->masterKeyPath)) {
            throw new \RuntimeException('Master key already exists. Refusing to overwrite for safety.');
        }

        $dir = dirname($this->masterKeyPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $key = random_bytes(self::KEY_LENGTH);
        file_put_contents($this->masterKeyPath, $key);

        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($this->masterKeyPath, 0600);
        }
    }

    /**
     * Read and decrypt all secrets. Returns associative array.
     * Throws if master key or secrets file is missing.
     *
     * @return array<string, mixed>
     */
    public function readSecrets(): array
    {
        if (!file_exists($this->masterKeyPath)) {
            throw new \RuntimeException('Master key file not found: ' . $this->masterKeyPath);
        }

        if (!file_exists($this->secretsPath)) {
            throw new \RuntimeException('Secrets file not found: ' . $this->secretsPath);
        }

        $key = $this->getMasterKey();
        $encoded = file_get_contents($this->secretsPath);

        if ($encoded === false) {
            throw new \RuntimeException('Cannot read secrets file.');
        }

        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new DecryptionException('Secrets file is corrupted or invalid.');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, -self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new DecryptionException('Failed to decrypt secrets. Key may be wrong or data corrupted.');
        }

        $decoded = json_decode($plaintext, true);

        if (!is_array($decoded)) {
            throw new DecryptionException('Decrypted secrets are not valid JSON.');
        }

        return $decoded;
    }

    /**
     * Encrypt and write secrets to disk. Creates file if it doesn't exist.
     *
     * @param array<string, mixed> $secrets
     */
    public function writeSecrets(array $secrets): void
    {
        if (!file_exists($this->masterKeyPath)) {
            throw new \RuntimeException('Master key file not found. Generate it first.');
        }

        $key = $this->getMasterKey();
        $plaintext = json_encode($secrets, JSON_THROW_ON_ERROR);

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt secrets.');
        }

        $raw = $iv . $ciphertext . $tag;
        $encoded = base64_encode($raw);

        $dir = dirname($this->secretsPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents($this->secretsPath, $encoded);
    }

    /**
     * Read the master key bytes from disk.
     */
    private function getMasterKey(): string
    {
        $key = file_get_contents($this->masterKeyPath);

        if ($key === false || strlen($key) !== self::KEY_LENGTH) {
            throw new \RuntimeException('Master key is invalid (must be exactly 32 bytes).');
        }

        return $key;
    }
}
