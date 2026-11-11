<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Archive;

/**
 * Opens a ZIP somebody else built, and refuses to be hurt by it.
 *
 * Extracted from `Core\Mail\Feedback\Dmarc\BoundedArchive` — the first
 * archive this site ever opened that it had not written itself — when the
 * data migration module became the second (chantier « Migration de
 * données », lot A). Two consumers with the same threat is the point where
 * one reader is worth more than two copies of it.
 *
 * **Nothing is ever extracted to disk.** Members are read through
 * `ZipArchive` streams into memory or into a consumer, so a hostile name
 * could not write anywhere even if it were accepted. The names are refused
 * anyway, because a member called `../config/app.php` or `C:\boot.ini` is
 * not a mistake an honest exporter makes, and a format that tolerates it
 * is a format whose next reader might extract.
 *
 * What {@see open()} refuses before a single byte is decompressed, from the
 * central directory alone:
 *
 * - more members than {@see ArchiveLimits::$maxEntries};
 * - an absolute name, a drive letter, a `..` or `.` segment, a backslash,
 *   an empty segment or a control character;
 * - a symbolic link (the Unix mode carried in the external attributes);
 * - an encrypted member, which could only be read by guessing;
 * - two members with the same name, which make « the member called X »
 *   ambiguous;
 * - a declared size over the per-member or total ceiling — a cheap
 *   refusal and nothing more, since the declaration is written by whoever
 *   built the file;
 * - a member whose extension the format does not allow.
 *
 * The REAL bound is applied while reading ({@see OpenedArchive}): every
 * byte that comes out of the decompressor is counted, per member and in
 * total, and the read stops the moment a ceiling is crossed. A
 * decompression bomb is bounded because the reading is, not because the
 * archive was believed.
 */
final class BoundedZipReader
{
    public function __construct(private ArchiveLimits $limits)
    {
    }

    public static function isAvailable(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * @param int $alreadyMeasured decompressed bytes already counted against
     *        the total ceiling by an earlier request reading the same
     *        archive — a verification run in slices carries it forward so
     *        the ceiling bounds the archive, not one slice of it
     *
     * @throws ArchiveRejectedException
     */
    public function open(string $path, int $alreadyMeasured = 0): OpenedArchive
    {
        if (!self::isAvailable()) {
            throw new ArchiveRejectedException(
                'Ce serveur ne sait pas lire les archives ZIP (extension PHP « zip » absente). '
                . 'Demandez à votre hébergeur de l\'activer.'
            );
        }

        $zip = new \ZipArchive();
        if (!is_file($path) || @$zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new ArchiveRejectedException('Le fichier n\'est pas une archive ZIP lisible.');
        }

        try {
            $files = $this->inspect($zip);
        } catch (ArchiveRejectedException $e) {
            $zip->close();
            throw $e;
        }

        return new OpenedArchive($zip, $files, $this->limits, $alreadyMeasured);
    }

    /**
     * @return array<string, int> file name => index, in archive order
     *
     * @throws ArchiveRejectedException
     */
    private function inspect(\ZipArchive $zip): array
    {
        $count = $zip->numFiles;
        if ($count > $this->limits->maxEntries) {
            throw new ArchiveRejectedException(sprintf(
                'L\'archive contient %d éléments, au-delà des %d acceptés pour ce format.',
                $count,
                $this->limits->maxEntries
            ));
        }

        $files = [];
        $declaredTotal = 0;

        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                throw new ArchiveRejectedException('Le sommaire de l\'archive est illisible.');
            }

            $name = (string) $stat['name'];
            $isDirectory = str_ends_with($name, '/');
            self::assertSafeName($isDirectory ? substr($name, 0, -1) : $name, $name);

            if (self::isSymbolicLink($zip, $index)) {
                throw new ArchiveRejectedException(sprintf(
                    'L\'archive contient un lien symbolique (« %s »), ce qui n\'est pas accepté.',
                    self::shown($name)
                ));
            }

            if ($stat['encryption_method'] !== \ZipArchive::EM_NONE) {
                throw new ArchiveRejectedException(sprintf(
                    'L\'élément « %s » de l\'archive est chiffré. Exportez l\'archive sans mot de passe.',
                    self::shown($name)
                ));
            }

            if ($isDirectory) {
                continue;
            }

            if (isset($files[$name])) {
                throw new ArchiveRejectedException(sprintf(
                    'L\'archive contient deux fois l\'élément « %s ».',
                    self::shown($name)
                ));
            }

            if (!$this->limits->allowsName($name)) {
                throw new ArchiveRejectedException(sprintf(
                    'L\'élément « %s » n\'a pas une extension acceptée pour ce format.',
                    self::shown($name)
                ));
            }

            $declared = (int) $stat['size'];
            if ($declared > $this->limits->maxEntryBytes) {
                throw new ArchiveRejectedException(sprintf(
                    'L\'élément « %s » dépasse la taille acceptée pour un élément de ce format.',
                    self::shown($name)
                ));
            }

            $declaredTotal += $declared;
            if ($declaredTotal > $this->limits->maxTotalBytes) {
                throw new ArchiveRejectedException(
                    'Le contenu décompressé de l\'archive dépasse la taille acceptée pour ce format.'
                );
            }

            $files[$name] = $index;
        }

        return $files;
    }

    /**
     * @throws ArchiveRejectedException
     */
    private static function assertSafeName(string $path, string $original): void
    {
        $refuse = static function () use ($original): never {
            throw new ArchiveRejectedException(sprintf(
                'L\'archive contient un chemin refusé (« %s ») : absolu, avec une lettre de lecteur, '
                . 'ou remontant d\'un dossier.',
                self::shown($original)
            ));
        };

        if ($path === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1) {
            $refuse();
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            $refuse();
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $refuse();
            }
        }
    }

    /**
     * A Unix-made archive keeps the file mode in the high sixteen bits of
     * the external attributes; `S_IFLNK` there is a symbolic link, whose
     * "content" is a path — exactly what an extracting reader would follow.
     */
    private static function isSymbolicLink(\ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        if ($opsys !== \ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return ((($attributes >> 16) & 0xF000) === 0xA000);
    }

    /** A member name as a message may quote it: printable and short. */
    public static function shown(string $name): string
    {
        $printable = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $name);
        if (!mb_check_encoding($printable, 'UTF-8')) {
            $printable = mb_convert_encoding($printable, 'UTF-8', 'UTF-8');
        }

        return mb_strlen($printable) > 80 ? mb_substr($printable, 0, 77) . '…' : $printable;
    }
}
