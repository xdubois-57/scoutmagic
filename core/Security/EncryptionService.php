<?php

declare(strict_types=1);

namespace Core\Security;

class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public function __construct(
        private string $encryptionKey,
        private string $blindIndexKey
    ) {
    }

    /**
     * Encrypt a plaintext string. Returns raw bytes (IV || ciphertext || tag).
     * Suitable for storage in a BLOB column.
     */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return $iv . $ciphertext . $tag;
    }

    /**
     * Decrypt raw bytes back to plaintext.
     * Throws DecryptionException if the data is corrupted or the key is wrong.
     */
    public function decrypt(string $encrypted): string
    {
        $minLength = self::IV_LENGTH + self::TAG_LENGTH;

        if (strlen($encrypted) < $minLength) {
            throw new DecryptionException('Encrypted data is too short to be valid.');
        }

        $iv = substr($encrypted, 0, self::IV_LENGTH);
        $tag = substr($encrypted, -self::TAG_LENGTH);
        $ciphertext = substr($encrypted, self::IV_LENGTH, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new DecryptionException('Decryption failed. Key may be wrong or data corrupted.');
        }

        return $plaintext;
    }

    /**
     * Compute a blind index (HMAC-SHA256) for exact-match searching
     * on encrypted fields (e.g. email lookup).
     * Returns hex string (64 chars) suitable for a CHAR(64) column.
     */
    public function blindIndex(string $value): string
    {
        return hash_hmac('sha256', $value, $this->blindIndexKey);
    }
}
