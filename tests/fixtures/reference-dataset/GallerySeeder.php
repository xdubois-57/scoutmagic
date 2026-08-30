<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Gallery\Api\GalleryException;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\OgScraperService;
use Modules\Gallery\Service\StorageLocationService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;

/**
 * Creates the gallery's external album through Modules\Gallery\Service\
 * AlbumService::create(), the same call the chiefs' "Nouvel album" form
 * makes.
 *
 * That call is what fetches the target page's Open Graph tags and caches its
 * `og:image` — the entire reason an external album is worth having in this
 * dataset. It is best-effort inside the service, so a build with no outbound
 * network still creates the album; the seeder only has to say so rather than
 * pretend the thumbnail is there.
 *
 * `GalleryException` is caught for the same reason: an album is a nice thing
 * for a fixture to have and not a reason to abandon a build that has already
 * written a unit's three years.
 */
final class GallerySeeder
{
    private readonly AlbumService $albumService;

    /** @param array<string, int> $sectionIds section handle => sections.id */
    public function __construct(
        \PDO $pdo,
        EncryptionService $encryption,
        SectionService $sectionService,
        string $storagePath,
        private readonly array $sectionIds,
        private readonly int $actorId,
        private readonly string $actorEmail,
    ) {
        $albumRepository = new AlbumRepository($pdo);
        $storageLocationRepository = new StorageLocationRepository($pdo, $encryption);
        $storageBackendFactory = new StorageBackendFactory($storageLocationRepository, $storagePath);
        $settingService = new SettingService(new SettingRepository($pdo));
        $scoutYearService = new ScoutYearService($pdo);

        $this->albumService = new AlbumService(
            $albumRepository,
            new MediaRepository($pdo),
            new GalleryAccessService(
                new MemberService(new MemberYearRepository($pdo), $encryption, Connection::withPdo($pdo)),
                $sectionService,
                $scoutYearService,
            ),
            new OgScraperService(),
            $storageBackendFactory,
            $storageLocationRepository,
            new StorageLocationService(
                $storageLocationRepository,
                $albumRepository,
                $storageBackendFactory,
                $settingService,
                new S3SecretRepository($pdo, $encryption),
                $storagePath,
            ),
            $scoutYearService,
            $settingService,
            new SchedulerService(new SchedulerRepository($pdo)),
            new UploadHandler(new FileRepository($pdo), $storagePath),
            // No notification centre and no account lookup: a build has
            // nobody to tell that an album was published. Both are the
            // module's own optional collaborators, nulled exactly as the
            // composition root nulls them when the notification service is
            // not available.
            null,
            null,
            null,
        );
    }

    /**
     * @return array{albums: int, failures: list<string>} the failures are
     *         reported rather than swallowed: an album that could not be
     *         created must not read like one that was
     */
    public function seed(): array
    {
        $created = 0;
        $failures = [];

        foreach (GalleryBlueprint::EXTERNAL_ALBUMS as $album) {
            $sectionId = $this->sectionIdFor($album['section']);

            try {
                $this->albumService->create(
                    Album::TYPE_EXTERNAL,
                    $album['title'],
                    $album['subtitle'],
                    ExtrasBlueprint::dateIn($album['year'], $album['day']),
                    $sectionId,
                    $album['url'],
                    $this->actorId,
                    Role::SUPERADMIN,
                    $this->actorEmail,
                );
                $created++;
            } catch (GalleryException $exception) {
                $failures[] = $album['title'] . ' : ' . $exception->getMessage();
            }
        }

        return ['albums' => $created, 'failures' => $failures];
    }

    /** A unit-wide album has no section, and that is a value, not an absence. */
    private function sectionIdFor(?string $handle): ?int
    {
        return $handle !== null ? ($this->sectionIds[$handle] ?? null) : null;
    }
}
