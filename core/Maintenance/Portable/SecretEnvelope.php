<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Portable;

use Core\Maintenance\BackupException;

/**
 * The envelope around the two files a portable archive carries and no
 * other one does — `storage/keys/master.key` and
 * `storage/config/secrets.enc`.
 *
 * AES-256-GCM, and nothing else: **this class does no key derivation**.
 * It takes a key, seals bytes, opens them. Where that key comes from is
 * {@see PortableKeys}'s business, and the split is not tidiness — the
 * first version of this file owned both, took a passphrase, and derived
 * its own key from it. That made it look self-sufficient while the archive
 * around it was keyed by the SAME passphrase through the zip format's
 * 1000-iteration derivation, so the expensive lock here guarded a door
 * whose frame came away with one cheap pull. Separating the two makes it
 * impossible to write that again by accident: a caller has to hold a
 * derived key, and the only thing that produces one also produces the
 * archive password.
 *
 * **What this layer buys, stated honestly**, now that it is no longer
 * asked to carry the whole design: once an archive is extracted — into a
 * temporary directory during a restore, onto a desktop by a curious
 * operator, into whatever a cloud provider does with an uploaded file —
 * the secrets are still sealed instead of lying in clear next to the
 * database dump. The zip layer protects the archive; this protects the
 * two files *after* the archive stops being one.
 *
 * **`open()` ships with `seal()` deliberately**, before anything restores
 * a portable archive (IT-07). A cipher whose output nothing can decrypt is
 * not a verified cipher, it is a hope; the round trip is the only test of
 * this class that proves anything.
 */
final class SecretEnvelope
{
    public const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const KEY_LENGTH = 32;

    /**
     * Seals $plaintext under $key.
     *
     * @param string $key 32 raw bytes, from {@see PortableKeys::envelopeKey()}
     * @return string what goes in the archive — `iv || tag || ciphertext`,
     *         in that order, with no header of its own: what is needed to
     *         derive the key lives in the archive comment, once for the
     *         whole archive.
     * @throws BackupException
     */
    public static function seal(string $plaintext, string $key): string
    {
        self::assertKey($key);

        // A fresh IV per member, even though the key is shared across the
        // archive's two secrets: a reused IV under one key is the classic
        // way to make GCM leak.
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
     * A wrong key, a truncated file and a tampered byte all arrive here as
     * the same answer — the GCM tag does not verify — and all three get
     * the same exception. Distinguishing them would mean telling whoever
     * is trying which half of their guess was right.
     *
     * @throws BackupException
     */
    public static function open(string $bytes, string $key): string
    {
        self::assertKey($key);

        // `<`, not `<=`: sealing an empty plaintext legitimately yields
        // exactly IV + tag and nothing else, and rejecting that length
        // would make a validly sealed file unopenable — discovered, as
        // ever with this feature, on the day of the restore.
        if (strlen($bytes) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new BackupException('Le fichier de secrets de l\'archive est tronqué.');
        }

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

    /**
     * A key of the wrong length is a wiring mistake, not a bad passphrase.
     *
     * Loud rather than silent: OpenSSL pads or truncates a short key
     * without complaining, which would produce an archive sealed under a
     * key nobody meant and that still opens — on this machine, this once.
     *
     * @throws BackupException
     */
    private static function assertKey(string $key): void
    {
        if (strlen($key) !== self::KEY_LENGTH) {
            throw new BackupException('Clé de chiffrement de sauvegarde invalide.');
        }
    }
}
