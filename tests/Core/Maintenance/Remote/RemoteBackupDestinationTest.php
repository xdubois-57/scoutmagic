<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Remote;

use Core\Maintenance\Remote\RemoteBackupConsumer;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Remote\RemoteBackupException;
use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\GoogleDriveBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Where the off-site backup sends, and what stops it being deleted from
 * under it.
 *
 * **This is what is left of the old `RemoteBackupConnection` once a Drive
 * folder is a storage location** — one assignment, held by the consumer
 * that made it (D4), with no join table in the middle. The questions worth
 * asking of it are therefore not about OAuth at all: which location, what
 * happens when it is gone, and who is told before somebody deletes it.
 */
final class RemoteBackupDestinationTest extends TestCase
{
    private InMemorySettingService $settings;
    private StorageLocationRepository $locations;
    private RemoteBackupDestination $destination;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new InMemorySettingService();
        $this->locations = new StorageLocationRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->destination = new RemoteBackupDestination(
            $this->settings,
            $this->locations,
            new StorageBackendFactory($this->locations, sys_get_temp_dir())
        );
    }

    public function testASiteWithNoDestinationChosenHasNoneAndNoBackend(): void
    {
        $this->assertFalse($this->destination->isConfigured());
        $this->assertNull($this->destination->location());
        $this->assertNull($this->destination->backend());
        $this->assertSame('', $this->destination->activeSince());
    }

    public function testChoosingALocationResolvesItAndItsBackend(): void
    {
        $this->destination->choose($this->declareDrive());

        $this->assertTrue($this->destination->isConfigured());
        $this->assertSame('Google Drive', $this->destination->location()?->label);
        $this->assertInstanceOf(GoogleDriveBackend::class, $this->destination->backend());
    }

    /**
     * **A destination whose row has vanished reads as none**, rather than
     * as a fatal in the scheduler.
     *
     * Deleting one is refused while the backup stands on it — that is
     * what {@see RemoteBackupConsumer} is for — but an installation
     * restored from a backup taken before the assignment was made can
     * still leave the setting pointing at nothing.
     */
    public function testADestinationThatNoLongerExistsReadsAsNone(): void
    {
        $this->destination->choose(4242);

        $this->assertFalse($this->destination->isConfigured());
        $this->assertNull($this->destination->backend());
    }

    /**
     * **A destination that cannot resume an interrupted upload is
     * refused, in French.**
     *
     * An archive of several gibibytes over a domestic upstream link does
     * not finish in one run on any hosting this application exists for,
     * and `put()` takes the whole thing as a string — so the alternative
     * to this sentence is a fatal at four in the morning.
     */
    public function testADestinationThatCannotResumeAnUploadIsRefusedWithAReason(): void
    {
        $this->destination->choose($this->locations->create(
            StorageLocationType::ObjectStorage,
            'Bucket',
            new ObjectStorageLocationConfig('https://s3.example', 'eu', 'seau', 'cle'),
            null
        ));

        try {
            $this->destination->backend();
            $this->fail('a destination that cannot resume an upload was accepted');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('Bucket', $e->getMessage());
            $this->assertStringContainsString('reprendre un envoi interrompu', $e->getMessage());
        }
    }

    /**
     * A Drive location knows when its grant was obtained; any other
     * location falls back to the day it was declared. Either way the age
     * check has a date to measure « rien n'est jamais parti » from.
     */
    public function testTheDateMeasuredFromIsTheGrantsWhenThereIsOne(): void
    {
        $this->destination->choose($this->declareDrive('2026-03-01T00:00:00+00:00'));
        $this->assertSame('2026-03-01T00:00:00+00:00', $this->destination->activeSince());

        $id = $this->locations->create(
            StorageLocationType::Local,
            'Disque réseau',
            new LocalLocationConfig('/mnt/nas/backups'),
            null
        );
        $this->destination->choose($id);

        // Compared against the row's own `createdAt`, not merely asserted
        // non-empty: « not the empty string » passes for any date at all,
        // including a wrong one — and a wrong date here is the whole
        // failure, since RemoteBackupAgeCheck measures « rien n'est jamais
        // parti » from exactly this value. A fallback that answered today
        // would keep the alert permanently re-armed on a site nothing has
        // ever left.
        $this->assertSame(
            $this->locations->findById($id)?->createdAt,
            $this->destination->activeSince()
        );
    }

    // ————— The consumer —————

    /**
     * **Deleting the destination is refused with its reason**, which is
     * the whole point of the consumer existing.
     *
     * Without it, an administrator tidying up the Stockage page would
     * remove the folder their unit's only off-site archives go to, be told
     * nothing, and find out on the night the server was gone.
     */
    public function testDeletingTheDestinationIsRefusedAndNamesTheUsage(): void
    {
        $id = $this->declareDrive();
        $this->destination->choose($id);

        try {
            $this->service()->delete($id);
            $this->fail('the off-site backup\'s destination was deleted from under it');
        } catch (StorageLocationException $e) {
            $this->assertStringContainsString('Sauvegardes hors site', $e->getMessage());
        }

        $this->assertNotNull($this->locations->findById($id), 'the declaration was removed anyway');
    }

    /** And a location nothing sends to is deletable, as it always was. */
    public function testALocationTheBackupDoesNotUseStaysDeletable(): void
    {
        $unused = $this->declareDrive();
        $this->destination->choose($this->declareDrive('2026-03-01T00:00:00+00:00', 'Autre Drive'));

        $this->service()->delete($unused);

        $this->assertNull($this->locations->findById($unused));
    }

    /**
     * **Read at call time, never cached.** An administrator who re-points
     * the backup a minute ago must make the old destination deletable and
     * the new one protected.
     */
    public function testRepointingTheBackupFreesTheOldDestinationAtOnce(): void
    {
        $first = $this->declareDrive();
        $second = $this->declareDrive('2026-03-01T00:00:00+00:00', 'Autre Drive');
        $consumer = new RemoteBackupConsumer($this->destination);

        $this->destination->choose($first);
        $this->assertSame([$first], $consumer->locationIdsInUse());

        $this->destination->choose($second);
        $this->assertSame([$second], $consumer->locationIdsInUse());
    }

    /**
     * Nothing to object to, and the empty answer is deliberate: an
     * off-site archive is encrypted with a phrase that never leaves this
     * server, so a destination that became world-readable would publish
     * ciphertext.
     */
    public function testTheBackupObjectsToNoReconfiguration(): void
    {
        $id = $this->declareDrive();
        $this->destination->choose($id);
        $location = $this->locations->findById($id);
        $this->assertNotNull($location);

        $this->assertNull(
            (new RemoteBackupConsumer($this->destination))
                ->objectionTo($location, new GoogleDriveLocationConfig('autre-client'), true)
        );
    }

    private function service(): StorageLocationService
    {
        $consumers = new StorageLocationConsumerRegistry();
        $consumers->register(new RemoteBackupConsumer($this->destination));

        return new StorageLocationService(
            $this->locations,
            new StorageBackendFactory($this->locations, sys_get_temp_dir()),
            $consumers
        );
    }

    private function declareDrive(string $connectedAt = '', string $label = 'Google Drive'): int
    {
        return $this->locations->create(
            StorageLocationType::GoogleDrive,
            $label,
            new GoogleDriveLocationConfig('client-1', 'dossier-1', $connectedAt),
            (string) json_encode([
                'client_secret' => 's',
                'refresh_token' => 'refresh-1',
                'account' => 'unite@example.org',
            ])
        );
    }
}
