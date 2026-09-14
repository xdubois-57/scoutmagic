<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\StorageBackendFactory;

/**
 * Every directory this installation has declared as a storage location,
 * resolved to an absolute path.
 *
 * **Why this exists as a type rather than as an array.** It is the input
 * to one rule — D10 of the storage chantier: *a file archive contains
 * `storage/`, minus every directory declared as a storage location.*
 * Before that rule, a backup decided what to leave out from the NAME of
 * the thing (`storage/gallery`, spelled in a constant) and from a boolean
 * the caller passed (`$includeGallery`). Both were wrong in the same way:
 * a location has its own lifecycle, so it has no business in an archive
 * whether it sits inside `storage/` or on a mounted disk, and whether or
 * not the gallery is what happens to live there.
 *
 * The consequence is deliberate and is the whole of IT-03: **on an
 * ordinary installation `storage/gallery` is a declared location**, so it
 * leaves every archive — which is why `full_with_gallery` stops being a
 * scope anybody can ask for. What replaces the archive as the gallery's
 * safety net is a location's own protection (IT-04), not a bigger zip.
 *
 * **A path nobody can resolve is left out of the list, and that is the
 * safe direction.** {@see StorageBackendFactory::localDirectoryFor()}
 * refuses a configured path this application will not open — and a
 * directory it refuses is one the application has never written to, so
 * excluding it from an archive protects nothing. Leaving it out makes the
 * archive no smaller than it was before this rule existed; inventing a
 * prefix from a path we could not resolve would make it skip a directory
 * nobody meant.
 */
final class DeclaredStorageDirectories
{
    /**
     * @param list<string> $directories absolute, trailing slash stripped
     */
    private function __construct(private readonly array $directories)
    {
    }

    /**
     * The declared locations of a running installation.
     *
     * Reads the DTOs only: resolving a local directory needs the
     * configured path and the storage root, never the secret, so nothing
     * here decrypts anything.
     */
    public static function of(
        StorageLocationRepository $repository,
        StorageBackendFactory $backends
    ): self {
        $directories = [];

        foreach ($repository->findAll() as $location) {
            try {
                $path = $backends->localDirectoryFor($location);
            } catch (StorageLocationException) {
                // See the class docblock: a path this application refuses
                // is a path it has never written to, so there is nothing
                // of ours in it for an archive to carry.
                continue;
            }

            if ($path === null) {
                // An object store is not a directory on this server. There
                // is nothing on a volume to keep out of a zip.
                continue;
            }

            $path = rtrim($path, '/');
            if ($path !== '' && !in_array($path, $directories, true)) {
                $directories[] = $path;
            }
        }

        return new self($directories);
    }

    /**
     * The declared locations of an installation, assembled from the
     * database — the form eleven call sites want, so that none of them
     * has to know that resolving a directory needs a repository AND a
     * backend factory.
     *
     * **A missing table answers "none", and that is not defensive
     * programming.** The backup every one of those call sites takes
     * happens BEFORE the operation it protects — before the truncate,
     * before the restore, before the files are replaced — so the schema
     * is normally intact. The exception is real and is the one this
     * catch exists for: an installation updating from a version older
     * than the storage chantier takes its pre-update safety backup while
     * `storage_locations` does not exist yet, because the migration runs
     * after the files are replaced. A site with no such table has
     * declared no location, so the honest list is empty — and the
     * resulting archive is simply the one that version already wrote.
     */
    public static function fromDatabase(
        \PDO $pdo,
        EncryptionService $encryption,
        string $storagePath
    ): self {
        try {
            $repository = new StorageLocationRepository($pdo, $encryption);

            return self::of($repository, new StorageBackendFactory($repository, $storagePath));
        } catch (\PDOException) {
            return self::none();
        }
    }

    /**
     * No location at all — the honest answer in exactly one situation:
     * the site is still being installed, so the table these come from
     * does not yet hold a row. Everywhere else an empty list has to be
     * an empty TABLE rather than an omitted dependency, which is what
     * {@see \Tests\Architecture\BackupServiceWiringTest} checks.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<string> $directories
     */
    public static function fromPaths(array $directories): self
    {
        $normalised = [];
        foreach ($directories as $directory) {
            $path = rtrim($directory, '/');
            if ($path !== '' && !in_array($path, $normalised, true)) {
                $normalised[] = $path;
            }
        }

        return new self($normalised);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->directories;
    }
}
