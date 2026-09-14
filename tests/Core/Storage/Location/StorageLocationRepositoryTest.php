<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location;

use Core\Security\EncryptionService;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class StorageLocationRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private StorageLocationRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    public function testCreateAndFindByIdRoundTripsTheConfiguration(): void
    {
        $id = $this->repository->create(
            StorageLocationType::Local,
            'Disque du serveur',
            new LocalLocationConfig('modules/gallery'),
            null
        );

        $location = $this->repository->findById($id);

        $this->assertNotNull($location);
        $this->assertSame('Disque du serveur', $location->label);
        $this->assertSame(StorageLocationType::Local, $location->type);
        $this->assertInstanceOf(LocalLocationConfig::class, $location->config);
        $this->assertSame('modules/gallery', $location->config->path);
        $this->assertFalse($location->secretConfigured);
    }

    public function testTheSecretIsEncryptedAtRestAndReadableOnlyThroughTheRepository(): void
    {
        $id = $this->repository->create(
            StorageLocationType::ObjectStorage,
            'Bucket Hetzner',
            new ObjectStorageLocationConfig(
                endpoint: 'https://fsn1.your-objectstorage.com',
                region: 'fsn1',
                bucket: 'scoutmagic',
                accessKey: 'AK123',
                provider: 'hetzner'
            ),
            'super-secret'
        );

        $location = $this->repository->findById($id);
        $this->assertNotNull($location);
        $this->assertTrue($location->secretConfigured);
        $this->assertSame('super-secret', $this->repository->getSecret($id));

        // The DTO every screen, journal and export handles carries no way
        // to reach the value — the whole point of keeping it off the
        // entity (D7 of the storage-locations chantier).
        $this->assertObjectNotHasProperty('secret', $location);

        $raw = $this->pdo->query('SELECT secret_encrypted FROM storage_locations WHERE id = ' . $id)->fetchColumn();
        $this->assertStringNotContainsString('super-secret', (string) $raw);
    }

    public function testGetSecretReturnsNullWhenNoneWasStored(): void
    {
        $id = $this->repository->create(
            StorageLocationType::Local,
            'Local',
            new LocalLocationConfig('modules/gallery'),
            null
        );

        $this->assertNull($this->repository->getSecret($id));
    }

    public function testAnObjectStorageConfigurationSurvivesTheRoundTrip(): void
    {
        $id = $this->repository->create(
            StorageLocationType::ObjectStorage,
            'Bucket',
            new ObjectStorageLocationConfig(
                endpoint: 'https://s3.example.test',
                region: 'eu-west',
                bucket: 'photos',
                accessKey: 'AK',
                provider: 'custom',
                publicUrl: 'https://cdn.example.test'
            ),
            'sk'
        );

        $location = $this->repository->findById($id);
        $this->assertNotNull($location);
        $config = $location->config;
        $this->assertInstanceOf(ObjectStorageLocationConfig::class, $config);
        $this->assertSame('https://s3.example.test', $config->endpoint);
        $this->assertSame('eu-west', $config->region);
        $this->assertSame('photos', $config->bucket);
        $this->assertSame('AK', $config->accessKey);
        $this->assertSame('custom', $config->provider);
        $this->assertSame('https://cdn.example.test', $config->publicUrl);
        $this->assertTrue($location->servesPubliclyWithoutExpiry());
    }

    public function testFindAllReturnsEveryLocation(): void
    {
        $this->repository->create(
            StorageLocationType::Local,
            'Local',
            new LocalLocationConfig('modules/gallery'),
            null
        );
        $this->repository->create(
            StorageLocationType::ObjectStorage,
            'S3',
            new ObjectStorageLocationConfig('https://x', 'eu', 'bucket', 'ak'),
            'sk'
        );

        $this->assertCount(2, $this->repository->findAll());
    }

    public function testUpdateWithABlankSecretKeepsTheExistingOne(): void
    {
        $id = $this->repository->create(
            StorageLocationType::ObjectStorage,
            'S3',
            new ObjectStorageLocationConfig('https://x', 'eu', 'bucket', 'ak'),
            'original-secret'
        );

        $this->repository->update(
            $id,
            'S3 renommé',
            new ObjectStorageLocationConfig('https://x', 'eu', 'bucket', 'ak'),
            ''
        );

        $this->assertSame('original-secret', $this->repository->getSecret($id));
        $this->assertSame('S3 renommé', $this->repository->findById($id)?->label);
    }

    public function testUpdateWithANewSecretReplacesIt(): void
    {
        $id = $this->repository->create(
            StorageLocationType::ObjectStorage,
            'S3',
            new ObjectStorageLocationConfig('https://x', 'eu', 'bucket', 'ak'),
            'original-secret'
        );

        $this->repository->update(
            $id,
            'S3',
            new ObjectStorageLocationConfig('https://x', 'eu', 'bucket', 'ak'),
            'new-secret'
        );

        $this->assertSame('new-secret', $this->repository->getSecret($id));
    }

    public function testDeleteRemovesTheRow(): void
    {
        $id = $this->repository->create(
            StorageLocationType::Local,
            'Local',
            new LocalLocationConfig('modules/gallery'),
            null
        );

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testDeletingTheDefaultHandsTheFlagToTheSurvivor(): void
    {
        // Leaving the table with no default at all is half of the state
        // this class exists to keep unobservable — and the half nothing
        // breaks on, because findDefault() falls back to the lowest id.
        // What an administrator saw was a page marking NO location as
        // « Défaut » while new albums landed on one of them anyway.
        $firstId = $this->repository->create(StorageLocationType::Local, 'Premier', new LocalLocationConfig('a'), null);
        $secondId = $this->repository->create(StorageLocationType::Local, 'Second', new LocalLocationConfig('b'), null);
        $this->repository->setDefault($secondId);

        $this->repository->delete($secondId);

        $this->assertTrue($this->repository->findById($firstId)?->isDefault);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM storage_locations WHERE is_default = 1')->fetchColumn()
        );
    }

    public function testDeletingALocationThatIsNotTheDefaultLeavesTheDefaultWhereItIs(): void
    {
        $firstId = $this->repository->create(StorageLocationType::Local, 'Premier', new LocalLocationConfig('a'), null);
        $secondId = $this->repository->create(StorageLocationType::Local, 'Second', new LocalLocationConfig('b'), null);
        $thirdId = $this->repository->create(StorageLocationType::Local, 'Troisième', new LocalLocationConfig('c'), null);
        $this->repository->setDefault($thirdId);

        $this->repository->delete($secondId);

        $this->assertSame($thirdId, $this->repository->findDefault()?->id);
        $this->assertFalse($this->repository->findById($firstId)?->isDefault);
    }

    public function testDeletingTheLastLocationLeavesAnEmptyTableRatherThanFailing(): void
    {
        // An empty table is legitimate: ensureDefaultExists() recreates the
        // default on the next request, exactly as on a fresh install.
        $id = $this->repository->create(StorageLocationType::Local, 'Seul', new LocalLocationConfig('a'), null);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findDefault());
        $this->assertSame([], $this->repository->findAll());
    }

    public function testRecordCheckResultPersistsBothOutcomes(): void
    {
        $id = $this->repository->create(
            StorageLocationType::Local,
            'Local',
            new LocalLocationConfig('modules/gallery'),
            null
        );

        $this->repository->recordCheckResult($id, false, 'Dossier introuvable.');

        $location = $this->repository->findById($id);
        $this->assertNotNull($location);
        $this->assertFalse($location->lastCheckOk);
        $this->assertSame('Dossier introuvable.', $location->lastCheckError);
        $this->assertNotNull($location->lastCheckedAt);
    }

    public function testFindByLabelReturnsNullWhenNoMatch(): void
    {
        $this->assertNull($this->repository->findByLabel('inconnu'));
    }

    public function testTheFirstLocationCreatedBecomesDefaultAutomatically(): void
    {
        $id = $this->repository->create(
            StorageLocationType::Local,
            'Premier',
            new LocalLocationConfig('a'),
            null
        );

        $this->assertTrue($this->repository->findById($id)?->isDefault);
    }

    public function testSubsequentLocationsAreNotDefault(): void
    {
        $this->repository->create(StorageLocationType::Local, 'Premier', new LocalLocationConfig('a'), null);
        $secondId = $this->repository->create(
            StorageLocationType::Local,
            'Second',
            new LocalLocationConfig('b'),
            null
        );

        $this->assertFalse($this->repository->findById($secondId)?->isDefault);
    }

    public function testSetDefaultLeavesExactlyOne(): void
    {
        $firstId = $this->repository->create(StorageLocationType::Local, 'Premier', new LocalLocationConfig('a'), null);
        $secondId = $this->repository->create(StorageLocationType::Local, 'Second', new LocalLocationConfig('b'), null);

        $this->repository->setDefault($secondId);

        $this->assertFalse($this->repository->findById($firstId)?->isDefault);
        $this->assertTrue($this->repository->findById($secondId)?->isDefault);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM storage_locations WHERE is_default = 1')->fetchColumn()
        );
    }

    public function testPromotingALocationThatIsGoneLeavesTheExistingDefaultAlone(): void
    {
        // setDefault() demotes everything and then promotes one row. An id
        // that matches nothing would leave the installation with ZERO
        // defaults — and the promoting statement itself succeeds, which is
        // what makes it easy to miss.
        $firstId = $this->repository->create(StorageLocationType::Local, 'Premier', new LocalLocationConfig('a'), null);

        try {
            $this->repository->setDefault($firstId + 12_345);
            $this->fail('Promoting a location that does not exist should be refused.');
        } catch (\Core\Storage\Location\StorageLocationException) {
            // Expected — the message names what the administrator should do.
        }

        $this->assertSame($firstId, $this->repository->findDefault()?->id);
    }

    public function testPromotingTheLocationThatIsAlreadyTheDefaultIsAccepted(): void
    {
        // MySQL reports zero affected rows for an UPDATE that matched a row
        // already holding the value, so rowCount() alone cannot tell this
        // apart from the case above.
        $id = $this->repository->create(StorageLocationType::Local, 'Premier', new LocalLocationConfig('a'), null);

        $this->repository->setDefault($id);

        $this->assertSame($id, $this->repository->findDefault()?->id);
    }

    public function testFindDefaultReturnsTheFlaggedLocation(): void
    {
        $this->repository->create(StorageLocationType::Local, 'Premier', new LocalLocationConfig('a'), null);
        $secondId = $this->repository->create(StorageLocationType::Local, 'Second', new LocalLocationConfig('b'), null);
        $this->repository->setDefault($secondId);

        $this->assertSame($secondId, $this->repository->findDefault()?->id);
    }

    public function testFindDefaultReturnsNullWhenNoLocationExists(): void
    {
        $this->assertNull($this->repository->findDefault());
    }

    public function testARowOfAnUnknownTypeIsRefusedRatherThanMisread(): void
    {
        // A row written by a newer version of the site. Treating it as a
        // local folder would point a consumer at the wrong disk, so it
        // refuses instead — with a sentence naming the location.
        $this->pdo->exec(
            "INSERT INTO storage_locations (type, label, is_default, config, created_at)
             VALUES ('quantum_tape', 'Bande quantique', 0, '{}', '2026-01-01 00:00:00')"
        );

        $this->expectException(\Core\Storage\Location\StorageLocationException::class);
        $this->expectExceptionMessageMatches('/Bande quantique/');
        $this->repository->findAll();
    }
}
