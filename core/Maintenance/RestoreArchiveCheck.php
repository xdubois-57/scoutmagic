<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Maintenance\Portable\PortableArchive;

/**
 * Whether an archive can be opened with the password it came with — asked
 * of the restore form BEFORE a restore is queued (issue #753).
 *
 * **Read-only, and cheap where it can be.** Nothing is extracted, nothing
 * is written, no safety copy is taken: the archive is opened and the one
 * member a restore needs first is read through a stream and thrown away.
 * A wrong password used to be found by Task\RestoreBackupHandler only after
 * the restore had been queued, and for an ordinary backup only after its
 * safety copy — minutes of work for an operation that could never finish.
 *
 * **Not a substitute for the handler's own checks.** The worker stays the
 * last barrier before a destructive operation and re-reads everything; a
 * file this class accepted is still treated as untrusted there.
 *
 * Each refusal says which of the cases it is, because each calls for
 * something different: another file, or another password. « database.sql
 * introuvable — mot de passe incorrect ? » made the operator guess.
 */
final class RestoreArchiveCheck
{
    public const DATABASE_MEMBER = 'database.sql';

    public const WRONG_PASSWORD = 'Mot de passe ou phrase de passe incorrect.';

    /** Bytes read at a time while a member is streamed through and discarded. */
    private const READ_BLOCK = 1048576;

    /**
     * An uploaded archive, portable or ordinary, with what the operator typed.
     *
     * @throws BackupException with the sentence to show
     */
    public static function assertUploadOpens(string $zipPath, ?string $password): void
    {
        if (PortableArchive::looksPortable($zipPath)) {
            if ($password === null || $password === '') {
                throw new BackupException('Une sauvegarde portable ne se restaure qu\'avec sa phrase de passe.');
            }
            // The manifest is the first member it reads, encrypted like
            // the rest: a wrong passphrase is refused there, by the same
            // code the restore itself runs.
            PortableArchive::open($zipPath, $password)->close();

            return;
        }

        $zip = self::openReadOnly($zipPath);
        try {
            if ($zip->locateName(self::DATABASE_MEMBER) === false) {
                throw new BackupException(
                    'Ce fichier n\'est pas une sauvegarde du site : il ne contient pas de base de données '
                    . '(' . self::DATABASE_MEMBER . ').'
                );
            }
            self::assertMemberReads($zip, self::DATABASE_MEMBER, $password);
        } finally {
            $zip->close();
        }
    }

    /**
     * An archive of this server whose password the site never kept — a full
     * backup from before IT-03 — with what the operator typed. An archive in
     * clear passes whatever was typed; one that cannot be read is left to the
     * handler, which says why.
     *
     * The smallest encrypted member is the one read: any of them proves the
     * password, and a gallery archive's largest can be hundreds of megabytes.
     *
     * @throws BackupException with the sentence to show
     */
    public static function assertServerArchiveOpens(string $zipPath, ?string $password): void
    {
        if (!is_file($zipPath)) {
            return;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            return;
        }

        try {
            $smallest = null;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false || $stat['encryption_method'] === \ZipArchive::EM_NONE) {
                    continue;
                }
                if ($smallest === null || $stat['size'] < $smallest['size']) {
                    $smallest = $stat;
                }
            }
            if ($smallest !== null) {
                self::assertMemberReads($zip, (string) $smallest['name'], $password);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws BackupException
     */
    private static function openReadOnly(string $zipPath): \ZipArchive
    {
        $zip = new \ZipArchive();
        if (!is_file($zipPath) || $zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new BackupException('Ce fichier n\'est pas une archive de sauvegarde lisible.');
        }

        return $zip;
    }

    /**
     * Streams one member to its end. An encrypted member read without its
     * password, or with the wrong one, gives no stream at all; one that is
     * damaged fails on the way.
     *
     * @throws BackupException
     */
    private static function assertMemberReads(\ZipArchive $zip, string $name, ?string $password): void
    {
        $stat = $zip->statName($name);
        $encrypted = $stat !== false && $stat['encryption_method'] !== \ZipArchive::EM_NONE;
        if ($encrypted && ($password === null || $password === '')) {
            throw new BackupException('Cette sauvegarde est protégée par un mot de passe : saisissez-le.');
        }
        if ($password !== null && $password !== '') {
            $zip->setPassword($password);
        }

        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new BackupException(
                $encrypted ? self::WRONG_PASSWORD : 'Cette archive de sauvegarde est endommagée.'
            );
        }

        try {
            while (!feof($stream)) {
                if (fread($stream, self::READ_BLOCK) === false) {
                    throw new BackupException(
                        $encrypted ? self::WRONG_PASSWORD : 'Cette archive de sauvegarde est endommagée.'
                    );
                }
            }
        } finally {
            fclose($stream);
        }
    }
}
