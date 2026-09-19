<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Storage\Location\Backend\GoogleDriveBackend;
use Core\Storage\Location\Backend\LocalStorageBackend;
use Core\Storage\Location\Backend\ObjectStorageBackend;
use Core\Storage\Location\Backend\WebDavBackend;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\LocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\Config\WebDavLocationConfig;

/**
 * The kinds of destination a storage location can be.
 *
 * An enum rather than a free string, and the column that stores it is a
 * VARCHAR rather than an ENUM: a new kind of storage then costs a case
 * here and nothing at all in the database, where widening an ENUM is a
 * `MODIFY COLUMN` on every installed site. The validation that an ENUM
 * column would have given is not lost — it moved into
 * {@see tryFrom()}, which is the only door into this type.
 *
 * **This enum never restates what a backend can do.** {@see capabilities()}
 * asks the backend class itself, so a backend that gains or loses an
 * aptitude changes one list, in one file, and every screen built from
 * these follows. The alternative — a table of capabilities written beside
 * the types — is a table that lies the first time somebody forgets it.
 */
enum StorageLocationType: string
{
    /** A directory on a filesystem this server can see. */
    case Local = 'local';

    /** An S3-compatible bucket. */
    case ObjectStorage = 's3';

    /**
     * A folder in somebody's Google Drive.
     *
     * **The value is `google_drive` and not `drive`**, because the column
     * is written once and read for the life of an installation: a second
     * provider whose product is also called « Drive » would have nowhere
     * left to go.
     */
    case GoogleDrive = 'google_drive';

    /**
     * A WebDAV share: Nextcloud, kDrive, a Hetzner Storage Box, Koofr.
     *
     * The value names the PROTOCOL rather than any one vendor, because
     * that is what this case actually is — four products and every future
     * one that speaks the same five verbs, behind one address, one
     * username and one password.
     */
    case WebDav = 'webdav';

    /**
     * The French name of this kind of storage, as an administrator picks
     * it from a list. Here rather than in a template because the same
     * words are needed by a screen, a journal-free support package and a
     * comparison table, and three copies would become three spellings.
     */
    public function frenchLabel(): string
    {
        return match ($this) {
            self::Local => 'Disque du serveur',
            self::ObjectStorage => 'S3 et compatibles',
            self::GoogleDrive => 'Google Drive',
            self::WebDav => 'Partage WebDAV (Nextcloud, kDrive, Koofr…)',
        };
    }

    /**
     * What this kind of storage can do beyond the common floor — asked of
     * the backend class, never written down twice.
     *
     * @return list<StorageCapability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Local => LocalStorageBackend::declaredCapabilities(),
            self::ObjectStorage => ObjectStorageBackend::declaredCapabilities(),
            self::GoogleDrive => GoogleDriveBackend::declaredCapabilities(),
            self::WebDav => WebDavBackend::declaredCapabilities(),
        };
    }

    public function supports(StorageCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * Rebuilds this type's configuration record from the serialised column.
     *
     * The one `match` in the codebase that knows which record shape goes
     * with which type — a new type adds its case here and nowhere else.
     *
     * @param array<string, mixed> $raw
     */
    public function configFromArray(array $raw): LocationConfig
    {
        return match ($this) {
            self::Local => LocalLocationConfig::fromArray($raw),
            self::ObjectStorage => ObjectStorageLocationConfig::fromArray($raw),
            self::GoogleDrive => GoogleDriveLocationConfig::fromArray($raw),
            self::WebDav => WebDavLocationConfig::fromArray($raw),
        };
    }
}
