<?php

declare(strict_types=1);

namespace Core\Mail;

class DkimManager
{
    private const KEY_DIR = 'dkim';
    private const PRIVATE_KEY_FILE = 'private.pem';

    public function __construct(private string $storagePath)
    {
    }

    /**
     * Check if a DKIM private key exists.
     */
    public function hasKey(): bool
    {
        return file_exists($this->getPrivateKeyPath());
    }

    /**
     * Generate a new RSA 2048-bit key pair. Writes private key to disk.
     * Returns the public key string (for DNS record).
     * Throws if key already exists (call deleteKey first to regenerate).
     */
    public function generateKey(): string
    {
        if ($this->hasKey()) {
            throw new \RuntimeException('DKIM key already exists. Delete it first to regenerate.');
        }

        $keyDir = $this->storagePath . '/' . self::KEY_DIR;
        if (!is_dir($keyDir)) {
            mkdir($keyDir, 0700, true);
        }

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $keyResource = openssl_pkey_new($config);

        if ($keyResource === false) {
            throw new \RuntimeException('Failed to generate DKIM key pair: ' . openssl_error_string());
        }

        $privateKey = '';
        openssl_pkey_export($keyResource, $privateKey);

        $path = $this->getPrivateKeyPath();
        file_put_contents($path, $privateKey);

        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0600);
        }

        return $this->extractPublicKey($keyResource);
    }

    /**
     * Delete the existing key (for regeneration).
     */
    public function deleteKey(): void
    {
        $path = $this->getPrivateKeyPath();
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Get the public key string from the existing private key.
     * Used to display the DKIM DNS record value.
     */
    public function getPublicKey(): string
    {
        $path = $this->getPrivateKeyPath();

        if (!file_exists($path)) {
            throw new \RuntimeException('No DKIM private key found.');
        }

        $privateKey = file_get_contents($path);

        if ($privateKey === false) {
            throw new \RuntimeException('Cannot read DKIM private key.');
        }

        $keyResource = openssl_pkey_get_private($privateKey);

        if ($keyResource === false) {
            throw new \RuntimeException('Invalid DKIM private key.');
        }

        return $this->extractPublicKey($keyResource);
    }

    /**
     * Get the private key path (for PHPMailer DKIM signing).
     */
    public function getPrivateKeyPath(): string
    {
        return $this->storagePath . '/' . self::KEY_DIR . '/' . self::PRIVATE_KEY_FILE;
    }

    /**
     * Extract the public key from an OpenSSL key resource.
     *
     * @param \OpenSSLAsymmetricKey $key
     */
    private function extractPublicKey(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new \RuntimeException('Failed to extract public key details.');
        }

        // Remove PEM headers and newlines to get the raw base64 public key
        $publicKeyPem = $details['key'];
        $publicKey = str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"],
            '',
            $publicKeyPem
        );

        return trim($publicKey);
    }
}
