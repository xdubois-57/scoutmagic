<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

use Core\Config\ScoutYearService;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Badge\MemberBadgeRepository;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Import\MemberYearRepository;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Service\DelegatedAlbumService;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\GalleryStorageWiring;
use Modules\Gallery\Service\MediaService;
use Modules\Gallery\Service\StoredFileCleaner;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * Builds a DelegatedAlbumManager from a Core\Scheduler\TaskContext —
 * the part of the prompt-5 delegated-album API that a **scheduled task**
 * needs and a controller does not.
 *
 * Why this exists. A controller receives its manager from the composition
 * root, which has every gallery service already built. A task handler
 * receives only a TaskContext (Core\Scheduler\SchedulerRunner constructs
 * every handler with a bare `new $handlerClass()`), so an owning module
 * whose retention purge has to delete stored media would otherwise have
 * to reassemble gallery's whole internal stack itself — the repositories,
 * the storage backend factory, the media service and its own six
 * dependencies. That is exactly the coupling the Api namespace exists to
 * prevent (ARCHITECTURE.md §7.5): the consuming module would break the
 * next time gallery changed a constructor it has no business knowing
 * about.
 *
 * So the assembly lives here, on gallery's side of the boundary, and the
 * consumer asks for the interface it already depends on. Modules\Groups\
 * Task\PurgeClosedGroupsHandler is the first caller.
 */
final class DelegatedAlbumManagerFactory
{
    /**
     * Everything below is built from $context alone — no composition-root
     * state, so this is safe to call from any handler.
     */
    public static function fromTaskContext(TaskContext $context): DelegatedAlbumManager
    {
        $pdo = $context->connection->getPdo();

        $albumRepository = new AlbumRepository($pdo);
        $mediaRepository = new MediaRepository($pdo);
        $scoutYearService = new ScoutYearService($pdo);
        $fileRepository = new FileRepository($pdo);
        $storage = GalleryStorageWiring::forTask($context, $albumRepository);

        $mediaService = new MediaService(
            $mediaRepository,
            $albumRepository,
            new UploadHandler(
                $fileRepository,
                $context->storagePath,
                new \Core\Storage\DiskBudget($context->storagePath, $context->settings)
            ),
            new SchedulerService(new SchedulerRepository($pdo)),
            $context->settings,
            new GalleryAccessService(
                new MemberService(
                    new MemberYearRepository($pdo),
                    new MemberProfileRepository($context->connection, $context->encryption),
                    null,
                    new MemberEmailRepository($pdo, $context->encryption)
                ),
                new SectionService(
                    new SectionRepository($context->connection),
                    new MemberProfileRepository(
                        $context->connection,
                        $context->encryption,
                        new MemberBadgeRepository($pdo)
                    )
                ),
                $scoutYearService
            ),
            $storage->backends,
            $storage->galleryLocations,
            new FfmpegAvailability(),
            new StoredFileCleaner($fileRepository, $context->storagePath)
        );

        return new DelegatedAlbumService(
            $albumRepository,
            $mediaRepository,
            $mediaService,
            $storage->locations,
            $storage->locationService,
            $storage->galleryLocations,
            $storage->backends,
            $scoutYearService
        );
    }
}
