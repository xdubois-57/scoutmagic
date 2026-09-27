<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

/**
 * A backend whose root folder this application CREATED for the location,
 * and which therefore follows the location's life: renamed with it,
 * put in the trash when it is deleted (#474).
 *
 * **Google Drive only, and that is the distinction rather than an
 * accident.** A local folder, a WebDAV collection and a bucket are named
 * by the administrator — a path, an address, a bucket name typed into the
 * form — and belong to them: renaming a location there must not move
 * their folder, and deleting it must not touch their files. A Drive
 * location's folder is minted by the connection flow under `ScoutMagic/`,
 * named after the location's label, and nobody typed where it is. Keeping
 * its name in step with the label is what lets an operator browsing their
 * Drive recognise it; trashing it on deletion is what keeps a location
 * that no longer exists from leaving a folder nobody will ever clean.
 *
 * **Not a {@see \Core\Storage\Location\StorageCapability}.** Capabilities
 * are what a CONSUMER asks of a destination and what the Stockage screen
 * turns into consequences; this is asked by the storage subsystem itself,
 * about its own declarations, and means nothing to a gallery.
 *
 * Both methods may throw: {@see \Core\Storage\Location\StorageLocationService}
 * is where a failure becomes a journal entry and a sentence for the
 * administrator, and where it is decided that the rename or the deletion
 * in ScoutMagic stands regardless.
 */
interface ManagedRootFolderBackend extends StorageBackendInterface
{
    /**
     * Gives the location's folder a new name. A location that has no
     * folder yet (never connected) has nothing to rename and returns.
     */
    public function renameRootFolder(string $name): void;

    /**
     * Moves the location's folder to the trash — recoverable, never a
     * deletion. A location that has no folder yet returns.
     */
    public function trashRootFolder(): void;
}
