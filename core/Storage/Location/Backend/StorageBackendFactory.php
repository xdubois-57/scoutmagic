<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;

/**
 * Builds the backend for a location, on demand and never at the
 * composition root — constructing an S3 client is pointless work on every
 * request when it is not going to be used.
 *
 * Several locations coexist by design, so a caller always resolves the
 * specific one the thing it is reading is pinned to. There is no single
 * « the » active backend.
 */
class StorageBackendFactory
{
    public function __construct(
        private StorageLocationRepository $repository,
        private string $storagePath
    ) {
    }

    /**
     * Backends already built, per location, for the lifetime of this
     * instance.
     *
     * An S3 backend is the most expensive object in this graph (secret
     * decryption plus a full client handler stack), and an album view used
     * to construct one per media per size — 1600 clients for an 800-photo
     * album. A location's configuration cannot change mid-request (the
     * configuration page saves and redirects), so the first backend serves
     * the whole view.
     *
     * @var array<int, StorageBackendInterface>
     */
    private array $backendsByLocationId = [];

    public function create(StorageLocation $location): StorageBackendInterface
    {
        return $this->backendsByLocationId[$location->id] ??= $this->build($location);
    }

    private function build(StorageLocation $location): StorageBackendInterface
    {
        $config = $location->config;

        if ($config instanceof LocalLocationConfig) {
            return new LocalStorageBackend($this->resolveLocalDirectory($config));
        }

        if ($config instanceof ObjectStorageLocationConfig) {
            return new ObjectStorageBackend(
                $config->endpoint,
                $config->region,
                $config->bucket,
                $config->accessKey,
                $this->repository->getSecret($location->id) ?? '',
                $config->publicUrl
            );
        }

        // Unreachable while every declared type has a case above, and
        // deliberately a refusal rather than a null: a location whose type
        // nothing can build is a configuration an administrator has to be
        // told about, not a silent no-op that loses their photos.
        throw new StorageLocationException(sprintf(
            "L'emplacement « %s » utilise un type de stockage que cette version du site ne sait pas ouvrir.",
            $location->label
        ));
    }

    /**
     * Where a local location actually is.
     *
     * **The one place the relative/absolute rule lives.** A relative path
     * hangs under `storage/`, which is the ordinary case and needs no
     * configuration at all; an absolute one is taken as it stands, which
     * is how a network mount or a second volume is reached. Settling it
     * here rather than in the backend means nothing else — no service, no
     * screen, no scheduled task — ever has to reimplement the rule and get
     * it subtly different.
     */
    private function resolveLocalDirectory(LocalLocationConfig $config): string
    {
        return $config->isAbsolute()
            ? rtrim($config->path, '/')
            : rtrim($this->storagePath, '/') . '/' . trim($config->path, '/');
    }
}
