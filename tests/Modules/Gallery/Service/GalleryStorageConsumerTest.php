<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Security\EncryptionService;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Service\GalleryStorageConsumer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * What the gallery answers when the storage page asks « is anybody still
 * standing on this location? ».
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GalleryStorageConsumerTest extends TestCase
{
    private \PDO $pdo;
    private AlbumRepository $albumRepository;
    private StorageLocationRepository $locations;
    private GalleryStorageConsumer $consumer;
    private int $scoutYearId;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $this->albumRepository = new AlbumRepository($this->pdo);
        $this->locations = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->consumer = new GalleryStorageConsumer($this->albumRepository, $this->locations);

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES ('{$label}', '{$start}', '{$end}')"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    public function testItIsNamedForTheAdministratorRatherThanForTheCode(): void
    {
        $this->assertSame('Galeries photo', $this->consumer->usageLabel());
    }

    public function testNothingIsInUseOnAnInstallationWithNoAlbums(): void
    {
        $this->locations->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);

        $this->assertSame([], $this->consumer->locationIdsInUse());
    }

    public function testAnAlbumPinsTheLocationItNames(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        $this->createAlbum($id);

        $this->assertSame([$id], $this->consumer->locationIdsInUse());
    }

    public function testAMigrationTargetCountsWhileTheMoveIsInFlight(): void
    {
        $sourceId = $this->locations->create(StorageLocationType::Local, 'Source', new LocalLocationConfig('a'), null);
        $targetId = $this->locations->create(StorageLocationType::Local, 'Cible', new LocalLocationConfig('b'), null);
        $albumId = $this->createAlbum($sourceId);
        $this->albumRepository->startMigration($albumId, $targetId);

        // Files are already being written to the target: a location deleted
        // at this moment takes a half-copied album with it.
        $this->assertEqualsCanonicalizing([$sourceId, $targetId], $this->consumer->locationIdsInUse());
    }

    public function testADelegatedAlbumCountsExactlyLikeAnyOther(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        $this->albumRepository->create(
            Album::TYPE_LOCAL,
            'Photos du groupe',
            null,
            '2026-01-01',
            null,
            $this->scoutYearId,
            null,
            $id,
            $this->authorId,
            'discussion_group',
            7
        );

        // Nobody sees it in the gallery's own listings, but its photos
        // occupy real space on a real destination.
        $this->assertSame([$id], $this->consumer->locationIdsInUse());
    }

    public function testAnAlbumThatPinsNothingStillHoldsTheDefaultLocation(): void
    {
        // The bug this exists for. A null location_id is not « this album
        // uses no storage »; it is « nobody has written down which one
        // yet », and the album resolves onto the DEFAULT the next time it
        // is touched. Reporting only the written-down identifiers left the
        // default looking unused — so the storage page offered to delete
        // it, cleanly, and every such album then resolved onto a
        // replacement where its files are not.
        $defaultId = $this->locations->create(
            StorageLocationType::Local,
            'Disque du serveur',
            new LocalLocationConfig('a'),
            null
        );
        $albumId = $this->createAlbum($defaultId);
        $this->pdo->exec('UPDATE gallery_albums SET location_id = NULL WHERE id = ' . $albumId);

        $this->assertSame([$defaultId], $this->consumer->locationIdsInUse());
    }

    public function testAnUnpinnedAlbumHoldsWhicheverLocationIsDefaultNow(): void
    {
        $firstId = $this->locations->create(StorageLocationType::Local, 'Premier', new LocalLocationConfig('a'), null);
        $secondId = $this->locations->create(StorageLocationType::Local, 'Second', new LocalLocationConfig('b'), null);
        $this->locations->setDefault($secondId);
        $albumId = $this->createAlbum($firstId);
        $this->pdo->exec('UPDATE gallery_albums SET location_id = NULL WHERE id = ' . $albumId);

        // Read at call time, never cached: the answer follows the default,
        // because that is what the album will actually resolve onto.
        $this->assertSame([$secondId], $this->consumer->locationIdsInUse());
    }

    public function testAnExternalAlbumHoldsNothingAtAll(): void
    {
        $this->locations->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        $this->albumRepository->create(
            Album::TYPE_EXTERNAL,
            'Album partagé',
            null,
            '2026-01-01',
            null,
            $this->scoutYearId,
            'https://example.test/album',
            null,
            $this->authorId
        );

        // It hosts no file, so its null location_id really does mean
        // « nothing » — the one case where it does.
        $this->assertSame([], $this->consumer->locationIdsInUse());
    }

    private function createAlbum(int $locationId): int
    {
        return $this->albumRepository->create(
            Album::TYPE_LOCAL,
            'Camp ' . bin2hex(random_bytes(3)),
            null,
            '2026-01-01',
            null,
            $this->scoutYearId,
            null,
            $locationId,
            $this->authorId
        );
    }
}
