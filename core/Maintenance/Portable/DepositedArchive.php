<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Portable;

use Core\Maintenance\BackupException;
use Core\Security\BootstrapHandoff;

/**
 * The portable archive waiting on the server to be restored (#719, C).
 *
 * It arrives one of three ways — deposited by the bootstrap, uploaded in
 * the setup wizard, or copied by FTP to the same place when it is too
 * large for a browser — and from then on it is one file at one address
 * ({@see BootstrapHandoff::ARCHIVE_PATH}). **It stays there after a wrong
 * passphrase**: the operator retypes the phrase, they do not send two
 * gigabytes again. It goes away on a successful restore, when the operator
 * abandons it (« Abandonner cette sauvegarde », or finishing the wizard
 * without it), or once nobody has touched it for
 * {@see BootstrapHandoff::ABANDONED_AFTER_SECONDS} — it is a full copy of
 * the unit's data, and must not linger on a host.
 */
final class DepositedArchive
{
    public function __construct(private readonly string $installRoot)
    {
    }

    public function path(): string
    {
        return rtrim($this->installRoot, '/') . '/' . BootstrapHandoff::ARCHIVE_PATH;
    }

    private function incomingDir(): string
    {
        return rtrim($this->installRoot, '/') . '/' . BootstrapHandoff::INCOMING_DIR;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function sizeBytes(): int
    {
        return $this->exists() ? (int) filesize($this->path()) : 0;
    }

    /** When it was deposited — what « abandoned » counts from. */
    public function depositedAt(): ?int
    {
        $mtime = $this->exists() ? filemtime($this->path()) : false;

        return $mtime === false ? null : $mtime;
    }

    /**
     * What the archive says about itself in clear, read without a
     * passphrase — or null when it says nothing readable. Hints only
     * ({@see PortableArchiveHints}): shown as plain text so the operator
     * can recognise the archive, never trusted for anything.
     */
    public function hints(): ?PortableArchiveHints
    {
        if (!$this->exists()) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($this->path(), \ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            return PortableKeys::parseHints((string) $zip->getArchiveComment());
        } catch (BackupException) {
            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Takes over an archive uploaded in the wizard, so it is kept like a
     * deposited one: a wrong passphrase no longer costs the upload. A
     * previous deposit is replaced — the operator just chose another file.
     */
    public function adopt(string $uploadedPath): string
    {
        $destination = $this->path();
        $this->ensureDirectory(dirname($destination));
        if (is_file($destination)) {
            @unlink($destination);
        }
        if (!@rename($uploadedPath, $destination)) {
            // A temp directory on another filesystem: rename() cannot
            // cross it, a copy can.
            if (!@copy($uploadedPath, $destination)) {
                throw new BackupException('L\'archive envoyée n\'a pas pu être conservée sur le serveur.');
            }
            @unlink($uploadedPath);
        }

        return $destination;
    }

    /** Restored, or abandoned: the archive and any chunks still on their way in go. */
    public function discard(): void
    {
        if (is_file($this->path())) {
            @unlink($this->path());
        }
        $this->removeIncoming(null);
    }

    /**
     * Deletes what nobody has touched for
     * {@see BootstrapHandoff::ABANDONED_AFTER_SECONDS}: the archive, and
     * chunks of an upload that never finished. Returns whether the archive
     * itself went.
     */
    public function purgeAbandoned(int $now): bool
    {
        $cutoff = $now - BootstrapHandoff::ABANDONED_AFTER_SECONDS;
        $this->removeIncoming($cutoff);

        $depositedAt = $this->depositedAt();
        if ($depositedAt === null || $depositedAt > $cutoff) {
            return false;
        }

        return @unlink($this->path());
    }

    /** @param int|null $olderThan a cutoff timestamp, or null for everything */
    private function removeIncoming(?int $olderThan): void
    {
        foreach (glob($this->incomingDir() . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $mtime = filemtime($file);
            if ($olderThan === null || ($mtime !== false && $mtime <= $olderThan)) {
                @unlink($file);
            }
        }
    }

    /**
     * Created with a deny-all `.htaccess`: on a host whose document root
     * holds the installation (layout A), `storage/` is reachable by URL
     * unless something says otherwise, and this file is the unit's data.
     */
    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new BackupException('Le dossier qui reçoit l\'archive n\'a pas pu être créé.');
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }
    }
}
