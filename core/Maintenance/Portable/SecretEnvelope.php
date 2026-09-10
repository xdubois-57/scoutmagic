<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Portable;

use Core\Maintenance\BackupException;

/**
 * The second lock on the two files a portable backup carries and no other
 * archive ever does — `storage/keys/master.key` and
 * `storage/config/secrets.enc`.
 *
 * **Why a second lock at all, when the zip is already AES-256.** A zip's
 * AES encryption derives its key with PBKDF2-HMAC-SHA1 at **1000
 * iterations**, a figure fixed by the file format in 2003 and not
 * revisable. Against a passphrase a human chose and can retype, that is a
 * few hours of one graphics card.
 *
 * Today that costs nothing: every archive this site writes contains a
 * database dump whose personal columns are already encrypted BLOBs, and
 * the key to them stays on the server. Break the zip and you get
 * ciphertext. The portable backup is the one archive that puts that key
 * *inside*, because an archive restored on a new host with no `master.key`
 * is an archive nobody can read — so for this one archive the zip layer
 * becomes the only thing standing between a lost file and every address,
 * date of birth and telephone number the unit holds, in clear. And this is
 * precisely the archive that ends up on a laptop, a USB stick, or somebody
 * else's cloud storage.
 *
 * So the two secret files get their own envelope, keyed by a **slow**
 * derivation from the same passphrase (D4: one zip, one password — nothing
 * extra to remember, because a second passphrase is a second thing to lose).
 *
 * **Argon2id when libsodium is there, PBKDF2-SHA256 when it is not.**
 * Not a preference: shared hosting is what this whole feature exists for,
 * and libsodium is common but not universal. The fallback is chosen with
 * an iteration count in the range OWASP recommends rather than a number
 * that merely looks large, and which of the two was used is recorded in
 * the manifest — a reader must never have to guess how a key was derived.
 *
 * **`open()` ships with `seal()` deliberately**, before anything restores
 * a portable archive (IT-07). A cipher whose output nothing can decrypt is
 * not a verified cipher, it is a hope; the round trip is the only test of
 * this class that proves anything at all.
 */
final class SecretEnvelope
{
    public const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const KEY_LENGTH = 32;

    /** Recorded in the manifest as `kdf`, so a reader never has to guess. */
    public const KDF_ARGON2ID = 'argon2id';
    public const KDF_PBKDF2_SHA256 = 'pbkdf2-sha256';

    /**
     * Iterations for the fallback derivation.
     *
     * 600 000 is the OWASP figure for PBKDF2-HMAC-SHA256, and the point of
     * quoting a published number rather than picking one is that this cost
     * is paid **once per backup** on the server and once per restore — so
     * the only pressure on it is what a shared host will tolerate in a
     * single request, which this comfortably is. Six hundred times what
     * the zip format allows itself.
     */
    public const PBKDF2_ITERATIONS = 600000;

    /** Salt length, shared by both derivations (libsodium fixes its own). */
    private const PBKDF2_SALT_LENGTH = 16;

    /**
     * A fresh derivation for one archive: a new salt, and the parameters
     * this server can honour.
     *
     * **Separate from {@see seal()} on purpose, and the separation is the
     * mechanism rather than a style.** An earlier shape had `seal()` mint
     * its own salt and hand it back with the ciphertext, leaving the caller
     * to record it — and a caller sealing TWO files recorded the first
     * derivation and sealed the second under another one. The archive was
     * produced, looked right, and its second secret could never be opened
     * by anything reading the manifest. An integration test caught it; the
     * shape that allowed it is gone. One archive derives once, and the
     * parameters it records are the only ones any of its envelopes can
     * have been sealed with.
     *
     * It is also the cheaper shape: the derivation is deliberately slow,
     * and doing it once per archive rather than once per file is the
     * difference between paying that cost once and paying it twice.
     *
     * @return array<string, mixed> to be passed to every `seal()` of this
     *         archive and written to the manifest unchanged
     */
    public static function newDerivation(): array
    {
        return self::derivationParameters(random_bytes(self::saltLength()));
    }

    /**
     * Seals $plaintext under $passphrase, using the derivation $params
     * describes.
     *
     * @param array<string, mixed> $params from {@see newDerivation()}
     * @return string what goes in the archive — `iv || tag || ciphertext`,
     *         in that order, with no header of its own. What is needed to
     *         open it lives in the manifest instead, once for the archive.
     * @throws BackupException
     */
    public static function seal(string $plaintext, string $passphrase, array $params): string
    {
        $salt = base64_decode((string) ($params['salt'] ?? ''), true);
        if ($salt === false || $salt === '') {
            throw new BackupException('Le chiffrement des secrets de la sauvegarde a échoué (sel manquant).');
        }

        $key = self::deriveKey($passphrase, $salt, $params);

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
            throw new BackupException('Le chiffrement des secrets de la sauvegarde a échoué.');
        }

        return $iv . $tag . $ciphertext;
    }

    /**
     * Opens what {@see seal()} produced, or fails.
     *
     * A wrong passphrase, a truncated file and a tampered byte all arrive
     * here as the same answer — the GCM tag does not verify — and all
     * three get the same exception. Distinguishing them would mean telling
     * whoever is trying which half of their guess was right.
     *
     * @param array<string, mixed> $params the manifest's derivation block
     * @throws BackupException
     */
    public static function open(string $bytes, string $passphrase, array $params): string
    {
        if (strlen($bytes) <= self::IV_LENGTH + self::TAG_LENGTH) {
            throw new BackupException('Le fichier de secrets de l\'archive est tronqué.');
        }

        $salt = base64_decode((string) ($params['salt'] ?? ''), true);
        if ($salt === false || $salt === '') {
            throw new BackupException('Le manifeste de l\'archive ne porte pas de sel de dérivation.');
        }

        $key = self::deriveKey($passphrase, $salt, $params);

        $iv = substr($bytes, 0, self::IV_LENGTH);
        $tag = substr($bytes, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($bytes, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new BackupException(
                'Les secrets de l\'archive n\'ont pas pu être déchiffrés — phrase de passe incorrecte, ou '
                . 'archive endommagée.'
            );
        }

        return $plaintext;
    }

    /** Whether the strong derivation is available on this server. */
    public static function hasSodium(): bool
    {
        return function_exists('sodium_crypto_pwhash')
            && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
    }

    private static function saltLength(): int
    {
        return self::hasSodium() ? SODIUM_CRYPTO_PWHASH_SALTBYTES : self::PBKDF2_SALT_LENGTH;
    }

    /**
     * What was used, recorded so the reader does not have to infer it.
     *
     * The libsodium limits are the INTERACTIVE pair rather than the
     * MODERATE one, and that is a shared-hosting decision rather than a
     * security compromise: MODERATE asks libsodium for 256 MiB of working
     * memory in a single request on a host that often caps the process
     * well below that, and a derivation that dies is a backup that does
     * not exist. INTERACTIVE is still four orders of magnitude beyond what
     * the zip layer does on its own.
     *
     * @return array<string, mixed>
     */
    private static function derivationParameters(string $salt): array
    {
        if (self::hasSodium()) {
            return [
                'kdf' => self::KDF_ARGON2ID,
                'salt' => base64_encode($salt),
                'opslimit' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                'memlimit' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            ];
        }

        return [
            'kdf' => self::KDF_PBKDF2_SHA256,
            'salt' => base64_encode($salt),
            'iterations' => self::PBKDF2_ITERATIONS,
        ];
    }

    /**
     * Derives the key the parameters describe — never the parameters this
     * server would have chosen.
     *
     * An archive sealed with Argon2id on one host is opened on another
     * that may have no libsodium at all, and one sealed with PBKDF2 may be
     * opened where libsodium exists. Reading `kdf` from the manifest
     * instead of re-asking {@see hasSodium()} is what makes an archive
     * portable between two servers rather than only back onto its own.
     *
     * @param array<string, mixed> $params
     * @throws BackupException
     */
    private static function deriveKey(string $passphrase, string $salt, array $params): string
    {
        $kdf = (string) ($params['kdf'] ?? '');

        if ($kdf === self::KDF_ARGON2ID) {
            if (!self::hasSodium()) {
                throw new BackupException(
                    'Cette archive a été chiffrée avec Argon2id et ce serveur n\'a pas l\'extension sodium — '
                    . 'restaurez-la sur un serveur qui l\'a, ou demandez son activation à votre hébergeur.'
                );
            }

            return sodium_crypto_pwhash(
                self::KEY_LENGTH,
                $passphrase,
                $salt,
                (int) ($params['opslimit'] ?? SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE),
                (int) ($params['memlimit'] ?? SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE),
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            );
        }

        if ($kdf === self::KDF_PBKDF2_SHA256) {
            $iterations = (int) ($params['iterations'] ?? 0);
            if ($iterations < 1) {
                throw new BackupException('Le manifeste de l\'archive déclare une dérivation invalide.');
            }

            return hash_pbkdf2('sha256', $passphrase, $salt, $iterations, self::KEY_LENGTH, true);
        }

        throw new BackupException(
            'Le manifeste de l\'archive déclare une dérivation inconnue de cette version : ' . $kdf . '.'
        );
    }
}
