<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
     * Generate the FIRST key pair. Returns the public half, for the DNS
     * record.
     *
     * Refuses when a key is already there, which is what makes it the
     * first-install door: replacing one is {@see replaceKey()}, and the
     * difference matters because only one of the two can leave the site
     * unsigned.
     */
    public function generateKey(): string
    {
        if ($this->hasKey()) {
            throw new \RuntimeException('DKIM key already exists. Delete it first to regenerate.');
        }

        return $this->writeNewKey();
    }

    /**
     * Replace the key pair **without ever leaving the site without one**.
     *
     * The rotation used to be `deleteKey()` then `generateKey()`, in both
     * of its two callers. Between those two lines the installation has no
     * key at all, and `generateKey()` throws for two ordinary reasons —
     * OpenSSL absent or disabled, `storage/keys` no longer writable after
     * a deployment or a full disk. A failure there left the site signing
     * nothing, on a host whose recipients often treat a missing signature
     * as a reason to refuse. Issue #545 made that state SAYABLE; this makes
     * it impossible.
     *
     * What buys that is one system call: the pair is written under a
     * temporary name **in the same directory**, therefore on the same
     * filesystem, so `rename()` over the live path is atomic. Every reader
     * sees either the whole old key or the whole new one, never a partial
     * file and never none.
     *
     * **The written file is read back and parsed before it replaces
     * anything**, and the public half returned comes from that reload
     * rather than from the in-memory resource. A truncated write — the
     * full-disk case this method exists for — produces a file OpenSSL
     * cannot load, and the reload is the only thing that would notice.
     * Returning the in-memory public key would describe a key that is not
     * the one on disk.
     */
    public function replaceKey(): string
    {
        return $this->writeNewKey();
    }

    /**
     * @return string the public half, read back from the file now in place
     */
    private function writeNewKey(): string
    {
        $keyDir = $this->storagePath . '/' . self::KEY_DIR;
        if (!is_dir($keyDir) && !mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
            throw new \RuntimeException('Cannot create the DKIM key directory: ' . $keyDir);
        }

        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyResource === false) {
            throw new \RuntimeException('Failed to generate DKIM key pair: ' . openssl_error_string());
        }

        $privateKey = '';
        if (!openssl_pkey_export($keyResource, $privateKey)) {
            throw new \RuntimeException('Failed to export the DKIM private key: ' . openssl_error_string());
        }

        // Beside the live file, so `rename()` below stays within one
        // filesystem — across two it is a copy and a delete, and loses the
        // atomicity this whole method is for.
        $temporary = $keyDir . '/' . self::PRIVATE_KEY_FILE . '.' . bin2hex(random_bytes(8)) . '.new';

        try {
            $written = file_put_contents($temporary, $privateKey);
            if ($written !== strlen($privateKey)) {
                throw new \RuntimeException('Cannot write the DKIM private key to ' . $keyDir . '.');
            }

            if (PHP_OS_FAMILY !== 'Windows' && !chmod($temporary, 0600)) {
                throw new \RuntimeException('Cannot restrict the DKIM private key to its owner.');
            }

            $public = $this->publicHalfOf($temporary);

            if (!rename($temporary, $this->getPrivateKeyPath())) {
                throw new \RuntimeException('Cannot put the new DKIM key in place.');
            }

            return $public;
        } finally {
            // Nothing half-written is left behind, on the failing path or
            // on the one where `rename()` already consumed it.
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * The public half of the key stored at `$path`, read from the file.
     *
     * **One implementation for two callers, and that is the point.** This
     * began as a copy of `getPublicKey()`'s tail, written for
     * `writeNewKey()`'s read-back — and a second copy of « read the file,
     * parse it, extract the public half » is a second place for the two to
     * drift. It was also the part nothing could test: from the write path
     * the reload only ever runs on a file this class has just written, so
     * neither refusal below was reachable. Through `getPublicKey()` both
     * are, because there the file is whatever is on disk: a key path that
     * has become a directory, and a key file whose contents are not a key.
     *
     * The messages name the path rather than saying « just written », which
     * they can no longer promise — and which the caller that *has* just
     * written it says for itself, one line later.
     *
     * The `false` from `file_get_contents()` stays uncovered on purpose: a
     * directory in the key's place does not produce it — the call raises a
     * notice and returns an EMPTY string, which the parse below refuses instead
     * (`DkimManagerTest::testGetPublicKeyRefusesAKeyPathThatHasBecomeADirectory()`
     * pins exactly that). It is kept as the guard on a contract the function
     * still has, not as a branch anything reaches.
     */
    private function publicHalfOf(string $path): string
    {
        $stored = file_get_contents($path);
        if ($stored === false) {
            throw new \RuntimeException('Cannot read the DKIM private key at ' . $path . '.');
        }

        $reloaded = openssl_pkey_get_private($stored);
        if ($reloaded === false) {
            throw new \RuntimeException('The DKIM private key at ' . $path . ' cannot be parsed.');
        }

        return $this->extractPublicKey($reloaded);
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

        return $this->publicHalfOf($path);
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
