<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Service\GalleryLocationService;
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
    private SettingService $settings;
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
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(
            GalleryLocationService::NEW_ALBUM_LOCATION_SETTING,
            '0',
            'number',
            'Emplacement',
            'Emplacement',
            'gallery'
        );
        $this->consumer = new GalleryStorageConsumer($this->albumRepository, $this->locations, $this->settings);

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)')
            ->execute([$label, $start, $end]);
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
        $this->pdo->prepare('UPDATE gallery_albums SET location_id = NULL WHERE id = ?')->execute([$albumId]);

        $this->assertSame([$defaultId], $this->consumer->locationIdsInUse());
    }

    public function testAnUnpinnedAlbumHoldsWhicheverLocationIsDefaultNow(): void
    {
        $firstId = $this->locations->create(StorageLocationType::Local, 'Premier', new LocalLocationConfig('a'), null);
        $secondId = $this->locations->create(StorageLocationType::Local, 'Second', new LocalLocationConfig('b'), null);
        $this->locations->setDefault($secondId);
        $albumId = $this->createAlbum($firstId);
        $this->pdo->prepare('UPDATE gallery_albums SET location_id = NULL WHERE id = ?')->execute([$albumId]);

        // Read at call time, never cached: the answer follows the default,
        // because that is what the album will actually resolve onto.
        $this->assertSame([$secondId], $this->consumer->locationIdsInUse());
    }

    // ————— L'objection : «Ce changement casserait ce que je porte » —————

    /**
     * The stranding this guard exists for, and the reason it moved onto the
     * consumer interface in IT-02: only the gallery knows that a delegated
     * album cannot live behind a permanent public URL, and the storage
     * screen has no business knowing what a delegated album is.
     *
     * The refusal is not new — `DelegatedAlbumService` refuses such a
     * location at creation, and `GalleryController::serveDelegatedMedia()`
     * refuses again when the bytes are handed out. The moment nothing
     * covered is the third one: the album is created on a private location
     * and the LOCATION is later edited to carry a public URL. Nothing is
     * exposed, because the serve-time guard holds — but every media of
     * every delegated album there becomes a permanent 404 with nothing
     * anywhere explaining it.
     */
    /**
     * **A choice is a use, and that is what this covers.** An
     * administrator who picks « emplacement des nouveaux albums » before
     * creating a single album there has decided something no album row
     * records. Reported unused, the storage page renders the delete form,
     * the deletion succeeds, and the setting is left naming an identifier
     * that no longer exists — `locationForNewAlbums()` then falls back on
     * the site default exactly as documented, and the choice is gone with
     * nothing said.
     */
    public function testAChosenLocationForNewAlbumsCountsAsUsedBeforeAnyAlbumExists(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'NAS', new LocalLocationConfig('nas'), null);
        $this->settings->set(GalleryLocationService::NEW_ALBUM_LOCATION_SETTING, (string) $id, 'gallery');

        $this->assertContains(
            $id,
            $this->consumer->locationIdsInUse(),
            'Chosen for new albums is a use, even before the first album is created there.'
        );
    }

    /** 0 is « the site's default » and names no location of its own. */
    public function testTheDefaultSentinelNamesNoLocationOfItsOwn(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'NAS', new LocalLocationConfig('nas'), null);
        $this->settings->set(GalleryLocationService::NEW_ALBUM_LOCATION_SETTING, '0', 'gallery');

        $this->assertNotContains($id, $this->consumer->locationIdsInUse());
    }

    /** Chosen AND standing on: one entry, not two. */
    public function testALocationBothChosenAndUsedIsReportedOnce(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'NAS', new LocalLocationConfig('nas'), null);
        $this->createDelegatedAlbum($id);
        $this->settings->set(GalleryLocationService::NEW_ALBUM_LOCATION_SETTING, (string) $id, 'gallery');

        $ids = $this->consumer->locationIdsInUse();

        $this->assertSame([$id], array_values(array_filter($ids, static fn (int $v): bool => $v === $id)));
    }

    public function testItObjectsToAPublicUrlWhileADelegatedAlbumLivesOnTheLocation(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        $this->createDelegatedAlbum($id);
        $location = $this->locations->findById($id);
        $this->assertNotNull($location);

        $objection = $this->consumer->objectionTo($location, $this->publiclyServingConfig(), false);

        $this->assertNotNull($objection);
        $this->assertStringContainsString('albums délégués', $objection);
    }

    /**
     * While a move is in flight the files are ALREADY being written to the
     * destination, and `location_id` still names the source — so the
     * question « is anybody heading for this location? » has to read
     * `migration_target_id` too.
     */
    public function testItObjectsWhileADelegatedAlbumIsBeingMigratedOntoTheLocation(): void
    {
        $source = $this->locations->create(StorageLocationType::Local, 'Source', new LocalLocationConfig('a'), null);
        $target = $this->locations->create(StorageLocationType::Local, 'Cible', new LocalLocationConfig('b'), null);
        $albumId = $this->createDelegatedAlbum($source);
        // Through the repository rather than a bare UPDATE: startMigration()
        // also writes migration_status = 'in_progress', and a row carrying
        // a target while still saying « none » is not an in-flight
        // migration. Building the state by hand would leave this test green
        // on the day hasDelegatedAlbumsOn() starts filtering on the status.
        $this->albumRepository->startMigration($albumId, $target);
        $location = $this->locations->findById($target);
        $this->assertNotNull($location);

        $this->assertNotNull($this->consumer->objectionTo($location, $this->publiclyServingConfig(), false));
    }

    /**
     * The second door into the same breakage. A delegated album that pins
     * nothing is not on « no » location — it is on the DEFAULT, and it is
     * pinned there the next time anything touches it. So promoting a
     * publicly-serving location has to be asked about the location that is
     * ABOUT to be the default.
     */
    public function testItObjectsToAPromotionThatWouldLandDelegatedAlbumsOnAPublicLocation(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        $albumId = $this->createDelegatedAlbum($id);
        $this->pdo->prepare('UPDATE gallery_albums SET location_id = NULL WHERE id = ?')->execute([$albumId]);
        $other = $this->locations->create(StorageLocationType::Local, 'Autre', new LocalLocationConfig('b'), null);
        $location = $this->locations->findById($other);
        $this->assertNotNull($location);

        $this->assertNotNull(
            $this->consumer->objectionTo($location, $this->publiclyServingConfig(), true),
            'An unpinned delegated album lands on whichever location becomes the default.'
        );
        $this->assertNull(
            $this->consumer->objectionTo($location, $this->publiclyServingConfig(), false),
            'The same location, NOT becoming the default, carries no such album.'
        );
    }

    public function testItDoesNotObjectWhenNoDelegatedAlbumStandsOnTheLocation(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        // An ordinary album, not a delegated one: it is the gallery's own,
        // it has no second access-control layer to contradict, and a public
        // URL on its location is a legitimate configuration.
        $this->createAlbum($id);
        $location = $this->locations->findById($id);
        $this->assertNotNull($location);

        $this->assertNull($this->consumer->objectionTo($location, $this->publiclyServingConfig(), false));
    }

    /**
     * A configuration that does NOT serve publicly is never objected to,
     * whatever stands on the location — the objection is about one
     * property, and a consumer that refuses more than its reason would
     * block ordinary edits.
     */
    public function testItDoesNotObjectToAConfigurationThatDoesNotServePublicly(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        $this->createDelegatedAlbum($id);
        $location = $this->locations->findById($id);
        $this->assertNotNull($location);

        $this->assertNull($this->consumer->objectionTo($location, new LocalLocationConfig('autre-dossier'), true));
    }

    /** An S3 location with a permanent public URL — the shape that strands a delegated album. */
    private function publiclyServingConfig(): ObjectStorageLocationConfig
    {
        return new ObjectStorageLocationConfig(
            'https://example.com',
            'fr-par',
            'photos',
            'AK',
            'custom',
            'https://cdn.example.org'
        );
    }

    private function createDelegatedAlbum(int $locationId): int
    {
        return $this->albumRepository->create(
            Album::TYPE_LOCAL,
            'Photos du groupe',
            null,
            '2026-01-01',
            null,
            $this->scoutYearId,
            null,
            $locationId,
            $this->authorId,
            'discussion_group',
            7
        );
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
