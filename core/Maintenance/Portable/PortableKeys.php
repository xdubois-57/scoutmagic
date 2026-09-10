<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Portable;

use Core\Maintenance\BackupException;

/**
 * The two keys a portable archive runs on, and the one slow derivation
 * that produces both.
 *
 * **Why this class exists at all — a review finding that emptied the
 * previous design of its purpose.** The first version handed the
 * operator's passphrase straight to `ZipArchive` as the archive password
 * AND used it to key the inner envelope. The zip format derives its key
 * with PBKDF2-HMAC-SHA1 at **1000 iterations**, and — this is the part
 * that undid everything — it stores a password *verification value*
 * derived alongside it. Cracking that layer therefore recovers **the
 * passphrase itself**, not some zip-scoped key: an attacker who breaks the
 * cheap layer simply types the recovered phrase into the expensive one and
 * derives the envelope key in a single honest pass. The slow derivation
 * added nothing to the attack it was built to stop.
 *
 * Worse still, and the reason a documentation fix would not have done: the
 * database dump sits under that same weak layer. Breaking 1000 iterations
 * already yielded every address, date of birth and telephone number the
 * unit holds — the envelope was not even protecting the smaller prize.
 *
 * **So the zip never sees the passphrase.** The archive password is
 * `base64` of a key derived by Argon2id (or PBKDF2-SHA256 at 600 000
 * iterations), which is a high-entropy string nobody enumerates. The 1000
 * iterations now stand in front of a secret with no dictionary; the only
 * remaining route is to guess the passphrase itself, and every guess costs
 * one full Argon2id. That is the regime the whole feature was supposed to
 * be in.
 *
 * **One derivation, two keys, domain-separated.** The archive password is
 * a string that travels: it is handed to `ZipArchive`, and a value handed
 * to a library is a value that can end up in a temporary file, a core
 * dump, or an error message. It must therefore not BE the envelope key.
 * One slow pass produces a master secret; `hash_hkdf()` splits it into two
 * keys that cannot be computed from each other.
 *
 * **The parameters travel in the zip's archive comment**, in clear. They
 * have to: the archive password is derived FROM them, so anything that
 * needs a password to read is unreachable — the manifest inside the zip
 * cannot hold them without being circular. A salt is not a secret; what it
 * costs an attacker is nothing, and what it buys is an archive that can be
 * opened at all.
 *
 * **The operator still types one phrase** (D4). The derived password is
 * machinery they never see, so "one zip, one password" holds where it was
 * meant to hold — in what a human has to remember.
 */
final class PortableKeys
{
    /** Recorded in the archive comment, so a reader never has to guess. */
    public const KDF_ARGON2ID = 'argon2id';
    public const KDF_PBKDF2_SHA256 = 'pbkdf2-sha256';

    /**
     * Iterations for the fallback derivation.
     *
     * 600 000 is the OWASP figure for PBKDF2-HMAC-SHA256, quoted rather
     * than invented. The cost is paid once per backup and once per
     * restore, so the only pressure on it is what a shared host tolerates
     * in one request — and it is six hundred times what the zip format
     * allows itself.
     */
    public const PBKDF2_ITERATIONS = 600000;

    private const PBKDF2_SALT_LENGTH = 16;
    private const MASTER_LENGTH = 32;

    /**
     * HKDF context strings. Changing one invalidates every archive sealed
     * with it, which is why they are constants and not inline literals.
     */
    private const CONTEXT_ARCHIVE = 'scoutmagic-portable-zip-password-v1';
    private const CONTEXT_ENVELOPE = 'scoutmagic-portable-secret-envelope-v1';

    private function __construct(private readonly string $master)
    {
    }

    /**
     * A fresh derivation for one archive: a new salt and the parameters
     * this server can honour.
     *
     * @return array<string, mixed> to be written to the archive comment
     *         verbatim, and read back from it on restore
     */
    public static function newDerivation(): array
    {
        if (self::hasSodium()) {
            return [
                'kdf' => self::KDF_ARGON2ID,
                'salt' => base64_encode(random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES)),
                // INTERACTIVE rather than MODERATE, and that is a
                // shared-hosting decision rather than a compromise:
                // MODERATE asks libsodium for 256 MiB in a single request
                // on a host that often caps the process well below that,
                // and a derivation that dies is a backup that does not
                // exist.
                'opslimit' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                'memlimit' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            ];
        }

        return [
            'kdf' => self::KDF_PBKDF2_SHA256,
            'salt' => base64_encode(random_bytes(self::PBKDF2_SALT_LENGTH)),
            'iterations' => self::PBKDF2_ITERATIONS,
        ];
    }

    /**
     * Runs the slow derivation once, for the whole archive.
     *
     * **The parameters decide, never this server's own capabilities.** An
     * archive sealed with Argon2id may be restored on a host with no
     * libsodium, and one sealed with PBKDF2 where libsodium exists.
     * Re-asking "what can I do?" instead of reading `kdf` would derive the
     * wrong key silently and report a wrong passphrase, sending an
     * operator hunting for a phrase that was right all along.
     *
     * @param array<string, mixed> $params from {@see newDerivation()}, as
     *        carried by the archive comment
     * @throws BackupException
     */
    public static function derive(string $passphrase, array $params): self
    {
        if ($passphrase === '') {
            throw new BackupException('Une phrase de passe est requise.');
        }

        $salt = base64_decode((string) ($params['salt'] ?? ''), true);
        if ($salt === false || $salt === '') {
            throw new BackupException('L\'archive ne déclare pas de sel de dérivation.');
        }

        $kdf = (string) ($params['kdf'] ?? '');

        if ($kdf === self::KDF_ARGON2ID) {
            if (!self::hasSodium()) {
                throw new BackupException(
                    'Cette archive a été chiffrée avec Argon2id et ce serveur n\'a pas l\'extension sodium — '
                    . 'restaurez-la sur un serveur qui l\'a, ou demandez son activation à votre hébergeur.'
                );
            }

            return new self(sodium_crypto_pwhash(
                self::MASTER_LENGTH,
                $passphrase,
                $salt,
                (int) ($params['opslimit'] ?? SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE),
                (int) ($params['memlimit'] ?? SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE),
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            ));
        }

        if ($kdf === self::KDF_PBKDF2_SHA256) {
            $iterations = (int) ($params['iterations'] ?? 0);
            if ($iterations < 1) {
                throw new BackupException('L\'archive déclare une dérivation invalide.');
            }

            return new self(
                hash_pbkdf2('sha256', $passphrase, $salt, $iterations, self::MASTER_LENGTH, true)
            );
        }

        throw new BackupException(
            'L\'archive déclare une dérivation inconnue de cette version : ' . $kdf . '.'
        );
    }

    /**
     * What `ZipArchive` is given as the archive password.
     *
     * Base64 of 32 derived bytes: printable, because it goes through a
     * library that takes a C string, and 256 bits of entropy behind it, so
     * the zip format's 1000 iterations guard something no dictionary
     * contains.
     */
    public function archivePassword(): string
    {
        return base64_encode(hash_hkdf('sha256', $this->master, self::MASTER_LENGTH, self::CONTEXT_ARCHIVE));
    }

    /**
     * The raw key {@see SecretEnvelope} seals the two secret files with.
     *
     * Not the archive password, and not derivable from it: that string is
     * handed to a third-party library, and this key must survive its
     * leaking.
     */
    public function envelopeKey(): string
    {
        return hash_hkdf('sha256', $this->master, self::MASTER_LENGTH, self::CONTEXT_ENVELOPE);
    }

    /** Whether the strong derivation is available on this server. */
    public static function hasSodium(): bool
    {
        return function_exists('sodium_crypto_pwhash')
            && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
    }

    /**
     * The zip's archive comment: everything needed to derive, in clear.
     *
     * Readable without a password by any zip tool, which is the whole
     * point — it is what turns the passphrase into the archive password.
     * The French line is there because a human who opens the file in
     * 7-Zip deserves to be told what they are looking at and what to do
     * with it.
     *
     * @param array<string, mixed> $params
     * @throws BackupException
     */
    public static function comment(array $params): string
    {
        $document = [
            'format' => PortableManifest::FORMAT,
            'format_version' => PortableManifest::FORMAT_VERSION,
            'note' => 'Sauvegarde portable ScoutMagic. Elle se restaure depuis une installation ScoutMagic '
                . 'neuve, à qui vous la téléversez avec votre phrase de passe.',
            'key_derivation' => $params,
        ];

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new BackupException('L\'en-tête de la sauvegarde portable n\'a pas pu être écrit.');
        }

        return $json;
    }

    /**
     * The derivation parameters an archive comment carries.
     *
     * @return array<string, mixed>
     * @throws BackupException when this is not one of our archives, or its
     *         header is not one this version knows how to read — refused
     *         by name rather than guessed at.
     */
    public static function parseComment(string $comment): array
    {
        $document = json_decode($comment, true);
        if (!is_array($document) || ($document['format'] ?? null) !== PortableManifest::FORMAT) {
            throw new BackupException(
                'Ce fichier ne porte pas l\'en-tête d\'une sauvegarde portable ScoutMagic.'
            );
        }

        $version = $document['format_version'] ?? null;
        if ($version !== PortableManifest::FORMAT_VERSION) {
            throw new BackupException(
                'Cette sauvegarde portable a été écrite dans un format que cette version ne sait pas lire. '
                . 'Mettez le site à jour, puis réessayez.'
            );
        }

        $params = $document['key_derivation'] ?? null;
        if (!is_array($params)) {
            throw new BackupException('L\'en-tête de cette sauvegarde portable ne déclare pas de dérivation.');
        }

        return $params;
    }
}
