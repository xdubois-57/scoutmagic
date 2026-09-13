<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\SettingService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Modules\Gallery\Repository\AlbumRepository;

/**
 * The five objects it takes to reach storage from a scheduled task,
 * assembled once.
 *
 * Three handlers used to build this same chain by hand —
 * `Task\ProcessPhotoHandler`, `Task\ProcessVideoHandler` and
 * `Api\DelegatedAlbumManagerFactory` — because a task runs outside
 * `public/index.php` and gets no wired services. Three copies of one
 * assembly is three chances to add a dependency in two of them, which had
 * already happened: one of the three passed the settings service where
 * another passed the storage path.
 *
 * A value object rather than a container: everything is built eagerly,
 * nothing is resolved by name, and what it holds is exactly what the
 * callers need to name.
 */
final class GalleryStorageWiring
{
    public function __construct(
        public readonly StorageLocationRepository $locations,
        public readonly StorageBackendFactory $backends,
        public readonly StorageLocationService $locationService,
        public readonly GalleryLocationService $galleryLocations
    ) {
    }

    /**
     * Built from $context alone — no composition-root state, so this is
     * safe to call from any handler.
     */
    public static function forTask(TaskContext $context, AlbumRepository $albumRepository): self
    {
        return self::build(
            $context->connection->getPdo(),
            $context->encryption,
            $context->settings,
            $context->storagePath,
            $albumRepository
        );
    }

    /**
     * The assembly itself, from the four things it genuinely needs.
     *
     * Separate from {@see forTask()} because a test has a PDO and a
     * storage path but no `TaskContext` — and a test that builds the chain
     * by hand is a fourth copy of it, which is exactly what this class
     * exists to stop.
     */
    public static function build(
        \PDO $pdo,
        EncryptionService $encryption,
        SettingService $settings,
        string $storagePath,
        AlbumRepository $albumRepository
    ): self {
        $locations = new StorageLocationRepository($pdo, $encryption);
        $backends = new StorageBackendFactory($locations, $storagePath);

        // The registry is what answers « is anybody still standing on this
        // location? ». A task never deletes one, so it would work empty —
        // but an empty registry is also how a deletion silently becomes
        // allowed, so it is filled here too rather than left as a hole
        // somebody widens later.
        $consumers = new StorageLocationConsumerRegistry();
        $consumers->register(new GalleryStorageConsumer($albumRepository));

        $locationService = new StorageLocationService($locations, $backends, $consumers);

        return new self(
            $locations,
            $backends,
            $locationService,
            new GalleryLocationService($locationService, $albumRepository, $settings, $storagePath)
        );
    }
}
