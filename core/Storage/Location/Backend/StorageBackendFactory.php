<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

use Core\Storage\Location\Backend\Drive\GoogleDriveClient;
use Core\Storage\Location\Backend\WebDav\WebDavClient;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\GoogleDriveSecret;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\Config\WebDavLocationConfig;
use Core\Storage\Location\Backend\WebDav\WebDavAccessException;
use Core\Security\SsrfUrlValidator;
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

        if ($config instanceof WebDavLocationConfig) {
            // **The address is checked again here, not only when it was
            // saved.** A username and a password travel on it in an
            // `Authorization` header on every single request, and the row
            // it comes from can have arrived since the form: a restore
            // from another installation, a hand-edited column. The save
            // path refuses anything that is not a public https URL
            // (SECURITY.md §17); this refuses it again before a single
            // byte of the credential leaves the server.
            //
            // The re-check is the STORED-value one, which differs from the
            // save-time check in a single place: a host the resolver
            // cannot answer for is not a refusal here. It buys nothing —
            // the request about to be made cannot reach anything either —
            // and it costs an accusation, because this message is what the
            // Emplacements page shows about a location whose address may
            // be perfectly good.
            if (!SsrfUrlValidator::isStoredHttpsTargetStillSafe($config->baseUrl, true)) {
                throw WebDavAccessException::of(
                    'L\'adresse enregistrée pour ce partage ne mène plus à une adresse publique. '
                    . 'Vérifiez-la sur la fiche de cet emplacement.'
                );
            }

            // The password is read here and nowhere else, like every other
            // credential: the strict reader, because this builds a backend
            // that is about to talk to the share.
            return new WebDavBackend(
                new WebDavClient(),
                $config,
                $this->repository->getSecret($location->id) ?? ''
            );
        }

        if ($config instanceof GoogleDriveLocationConfig) {
            // **The secret is read here and nowhere else**, which is the
            // rule the repository's own docblock states: nothing that
            // renders, journals or exports a location ever holds the
            // refresh token, and this is the one place that turns the
            // encrypted column back into something usable.
            return new GoogleDriveBackend(
                new GoogleDriveClient(),
                $config,
                GoogleDriveSecret::fromStorage($this->repository->getSecret($location->id))
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
     * Where a local location actually is, for a caller that needs the
     * DIRECTORY rather than a backend built on it — the volume inventory,
     * which groups declared directories by the filesystem they sit on and
     * never opens any of them.
     *
     * Null for every other type: an S3 bucket is not on a volume this
     * server can measure, and answering with a path would put it on one.
     *
     * @throws StorageLocationException when the configured path is one
     *         this application refuses (see {@see resolveLocalDirectory()})
     */
    public function localDirectoryFor(StorageLocation $location): ?string
    {
        $config = $location->config;

        return $config instanceof LocalLocationConfig ? $this->resolveLocalDirectory($config) : null;
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
        if ($config->isAbsolute()) {
            return rtrim($config->path, '/') !== '' ? rtrim($config->path, '/') : '/';
        }

        // A relative path is relative, and `../public` is not. Refused
        // here rather than normalised away: an administrator who typed it
        // meant somewhere outside `storage/`, and quietly reinterpreting
        // that as a folder inside it is a surprise rather than a fix —
        // while accepting it would put every rendition in the web root.
        // Wanting a directory outside `storage/` is legitimate (a network
        // mount, a second volume); it is spelled as an absolute path,
        // which says so.
        foreach (explode('/', trim($config->path, '/')) as $segment) {
            if ($segment === '..') {
                throw new StorageLocationException(
                    'Le dossier de cet emplacement remonte hors du dossier de stockage du site. Indiquez un '
                        . 'chemin relatif sans « .. », ou un chemin absolu si le dossier est ailleurs.'
                );
            }
        }

        return rtrim($this->storagePath, '/') . '/' . trim($config->path, '/');
    }
}
