<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
     * Build from the base64-encoded key strings held in secrets.enc. The
     * secret store is JSON, which can't carry raw bytes, so setup
     * base64-encodes the two 32-byte keys — and they MUST be decoded back to
     * raw bytes here before use. Passing the 44-character base64 string
     * straight to OpenSSL silently truncated it to the first 32 characters =
     * 24 raw bytes, weakening AES-256-GCM to a 192-bit effective key (audit
     * M1). The constructor still takes raw bytes, so tests (and any caller
     * that already holds raw keys) are unaffected.
     */
    public static function fromEncodedKeys(string $encodedEncryptionKey, string $encodedBlindIndexKey): self
    {
        return new self(
            base64_decode($encodedEncryptionKey, true) ?: '',
            base64_decode($encodedBlindIndexKey, true) ?: ''
        );
    }

    /**
     * Encrypt a plaintext string. Returns raw bytes (IV || ciphertext || tag).
     * Suitable for storage in a BLOB column.
     *
     * $context binds the ciphertext to where it lives (AES-GCM additional
     * authenticated data): decrypt() must be called with the exact same
     * string, so a ciphertext copied into a different column or table by
     * an attacker with DB write access fails authentication instead of
     * decrypting somewhere it can be read back. Convention: the storage
     * location as "table.column" (e.g. 'user_accounts.email'), or a
     * purpose label for non-DB uses (e.g. 'backup_password'). Every call
     * site is expected to pass one — the '' default exists only so a
     * missing context fails at decrypt time (tag mismatch) rather than
     * silently binding to a wrong guess.
     */
    public function encrypt(string $plaintext, string $context = ''): string
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
            $context,
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return $iv . $ciphertext . $tag;
    }

    /**
     * Decrypt raw bytes back to plaintext. $context must be the exact
     * string the data was encrypted with (see encrypt()).
     * Throws DecryptionException if the data is corrupted, the key is
     * wrong, or the context doesn't match the one used to encrypt.
     */
    public function decrypt(string $encrypted, string $context = ''): string
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
            $tag,
            $context
        );

        if ($plaintext === false) {
            throw new DecryptionException('Decryption failed. Key may be wrong or data corrupted.');
        }

        return $plaintext;
    }

    /**
     * The one form an email address takes before it is stored, encrypted
     * or blind-indexed: trimmed, lowercased.
     *
     * A blind index is an exact-match index and nothing else — there is no
     * `LIKE`, no case-insensitive collation, no second chance. Two call
     * sites normalising "the same way" by hand is therefore two call sites
     * one edit away from indexing the same person twice: `Jean@Ex.be`
     * hashes to something entirely unrelated to `jean@ex.be`, and the
     * lookup that misses simply reports "not found" rather than failing.
     *
     * `mb_strtolower` rather than `strtolower`, because a local part can
     * carry non-ASCII (SMTPUTF8) and the byte-wise version would leave it
     * alone in one place and not in another. The domain half is
     * case-insensitive by RFC 1035; the local part is not, in theory —
     * lowercasing it is the pragmatic choice every mail provider in
     * practice makes, and the one this site has always made.
     *
     * Static: normalising an address needs no key, and a caller holding
     * only a plain address must not have to obtain an EncryptionService to
     * spell it the same way as everybody else.
     */
    public static function normalizeEmailForIndex(?string $email): string
    {
        return $email === null ? '' : mb_strtolower(trim($email), 'UTF-8');
    }

    /**
     * Compute a blind index (HMAC-SHA256) for exact-match searching
     * on encrypted fields (e.g. email lookup).
     * Returns hex string (64 chars) suitable for a CHAR(64) column.
     *
     * $purpose domain-separates indexes of different kinds: the same
     * plaintext under two purposes yields unrelated indexes, so an
     * attacker with DB read access cannot link a value across unrelated
     * columns, and a value of one kind can never be confused for another.
     * Indexes that are deliberately compared across tables (e.g. the
     * email identity shared by user_accounts / member_years /
     * member_emails) must share ONE purpose. The '' default keeps the
     * legacy unlabelled form for call sites not yet migrated.
     */
    public function blindIndex(string $value, string $purpose = ''): string
    {
        // "\x00" separates purpose from value unambiguously — no
        // purpose/value pair can collide with a different split of the
        // same concatenation (purposes never contain NUL).
        $input = $purpose === '' ? $value : $purpose . "\x00" . $value;

        return hash_hmac('sha256', $input, $this->blindIndexKey);
    }
}
