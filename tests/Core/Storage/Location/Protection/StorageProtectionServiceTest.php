<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Protection;

use Core\Maintenance\BackupRepository;
use Core\Security\EncryptionService;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\Protection\StorageProtectionRepository;
use Core\Storage\Location\Protection\StorageProtectionService;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Choosing a location's safety copy: what is refused, what is merely
 * warned about, and why the line falls where it does.
 */
final class StorageProtectionServiceTest extends TestCase
{
    private \PDO $pdo;
    private StorageLocationRepository $locations;
    private StorageProtectionRepository $protections;
    private StorageProtectionService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->locations = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->protections = new StorageProtectionRepository($this->pdo);
        $this->service = new StorageProtectionService(
            $this->protections,
            $this->locations,
            new BackupRepository($this->pdo)
        );
    }

    private function local(string $label, string $path = 'gallery'): int
    {
        return $this->locations->create(
            StorageLocationType::Local,
            $label,
            new LocalLocationConfig($path),
            null
        );
    }

    // ————— The relation itself —————

    public function testSavingTheSameSourceTwiceCorrectsTheRelationRatherThanAddingASecond(): void
    {
        $source = $this->local('Galerie');
        $first = $this->local('NAS');
        $second = $this->local('Second disque', 'autre');

        $this->service->save($source, $first, 30, 24, true);
        $this->service->save($source, $second, 30, 24, true);

        $this->assertCount(1, $this->protections->findAll(), 'A source has exactly one destination.');
        $this->assertSame($second, $this->protections->findBySourceId($source)?->destinationLocationId);
    }

    /**
     * A destination may protect several sources — the other half of the
     * asymmetry, and the one an administrator actually uses: one NAS
     * behind the gallery and behind the documents.
     */
    public function testOneDestinationMayProtectSeveralSources(): void
    {
        $destination = $this->local('NAS');
        $this->service->save($this->local('Galerie'), $destination, 30, 24, true);
        $this->service->save($this->local('Documents', 'documents'), $destination, 30, 24, true);

        $this->assertCount(2, $this->protections->findAll());
        $this->assertSame([$destination], $this->protections->destinationLocationIds());
    }

    /**
     * **Changing the destination throws the working state away.** A pass
     * half-way through an inventory of the old destination has nothing to
     * say about the new one, and resuming into it would reconcile one
     * destination's inventory against another destination's files.
     */
    public function testChangingTheDestinationDiscardsAPassInProgress(): void
    {
        $source = $this->local('Galerie');
        $id = $this->service->save($source, $this->local('NAS'), 30, 24, true);
        $this->protections->recordPassProgress($id, 'inventory', 'page-7', 4200, '2026-09-01 02:00:00');

        $this->service->save($source, $this->local('Second disque', 'autre'), 30, 24, true);

        $protection = $this->protections->findBySourceId($source);
        $this->assertNotNull($protection);
        $this->assertFalse($protection->isPassInProgress());
        $this->assertSame(0, $protection->passSeenCount);
    }

    // ————— The refusals —————

    public function testALocationCannotBeItsOwnSafetyCopy(): void
    {
        $location = $this->local('Galerie');

        $this->expectException(StorageLocationException::class);
        $this->expectExceptionMessageMatches('/sa propre copie de secours/');
        $this->service->save($location, $location, 30, 24, true);
    }

    /**
     * A to B to A, which no single relation reveals.
     *
     * A cycle is not merely useless: each pass copies the other's copy
     * back, so a file deleted at the source is restored on the next pass
     * and deleted on the one after, for ever — and the grace period, which
     * counts from « absent from the source », never elapses for anything.
     */
    public function testAChainThatComesBackOnItselfIsRefused(): void
    {
        $a = $this->local('A');
        $b = $this->local('B', 'b');
        $this->service->save($b, $a, 30, 24, true);

        $this->expectException(StorageLocationException::class);
        $this->expectExceptionMessageMatches('/revenir sur/');
        $this->service->save($a, $b, 30, 24, true);
    }

    /** And a longer one, because two hops is not a special case. */
    public function testAThreeStepChainThatComesBackOnItselfIsRefused(): void
    {
        $a = $this->local('A');
        $b = $this->local('B', 'b');
        $c = $this->local('C', 'c');
        $this->service->save($b, $c, 30, 24, true);
        $this->service->save($c, $a, 30, 24, true);

        $this->expectException(StorageLocationException::class);
        $this->service->save($a, $b, 30, 24, true);
    }

    /** A chain that does not come back is fine, however long. */
    public function testAChainThatDoesNotCloseIsAccepted(): void
    {
        $a = $this->local('A');
        $b = $this->local('B', 'b');
        $c = $this->local('C', 'c');
        $this->service->save($b, $c, 30, 24, true);

        $this->service->save($a, $b, 30, 24, true);

        $this->assertSame($b, $this->protections->findBySourceId($a)?->destinationLocationId);
    }

    /**
     * **The refusal that is about somebody's photographs rather than
     * about the mechanism, and this iteration is what creates the hole.**
     *
     * A consumer with its own access control — a delegated album, a
     * discussion group's photographs — is already refused a location that
     * hands out permanent public URLs. That guard reads the location the
     * consumer STANDS on, and knows nothing about a second location the
     * bytes are about to be copied to: D4 keeps the assignment with the
     * consumer, and a protection is not an assignment. So a private album
     * on a private location, protected to a public bucket, would have
     * every one of its files readable by anybody holding the URL, with no
     * screen anywhere saying so.
     */
    public function testAPublishingDestinationMayNotProtectASourceThatDoesNotPublish(): void
    {
        $source = $this->local('Galerie');
        $destination = $this->publishingObject('Bucket public', 'public-bucket');

        $this->expectException(StorageLocationException::class);
        $this->expectExceptionMessageMatches('/adresses publiques et permanentes/');
        $this->service->save($source, $destination, 30, 24, true);
    }

    /**
     * And the pairing is allowed when the source publishes too: nothing
     * becomes reachable that was not already, so refusing it would only
     * stop a unit whose photographs are public on purpose from keeping a
     * copy of them.
     */
    public function testAPublishingDestinationMayProtectASourceThatAlsoPublishes(): void
    {
        $source = $this->publishingObject('Bucket public A', 'public-a');
        $destination = $this->publishingObject('Bucket public B', 'public-b');

        $id = $this->service->save($source, $destination, 30, 24, true);

        $this->assertGreaterThan(0, $id);
    }

    /**
     * The other direction is fine too: copying public content onto a
     * destination that does not publish takes nothing away from anybody.
     */
    public function testAPrivateDestinationMayProtectAPublishingSource(): void
    {
        $source = $this->publishingObject('Bucket public', 'public-bucket');
        $destination = $this->local('NAS', 'nas');

        $id = $this->service->save($source, $destination, 30, 24, true);

        $this->assertGreaterThan(0, $id);
    }

    public function testAGracePeriodOfZeroIsRefused(): void
    {
        $this->expectException(StorageLocationException::class);
        $this->expectExceptionMessageMatches('/au moins un jour/');
        $this->service->save($this->local('Galerie'), $this->local('NAS'), 0, 24, true);
    }

    // ————— The warnings —————

    /**
     * **Remote to remote is warned about, never forbidden.** Every byte
     * transits through this server, which is slower and paid for twice;
     * it is also the only arrangement that survives losing the server, so
     * a unit that chose it usually chose it on purpose.
     */
    public function testTwoRemoteLocationsAreWarnedAboutRatherThanRefused(): void
    {
        $source = $this->object('Bucket A', 'bucket-a');
        $destination = $this->object('Bucket B', 'bucket-b');

        $id = $this->service->save($source, $destination, 30, 24, true);
        $this->assertGreaterThan(0, $id, 'the relation must be allowed');

        $warnings = $this->service->warningsFor(
            $this->locations->findById($source) ?? $this->fail('missing source'),
            $this->locations->findById($destination) ?? $this->fail('missing destination'),
            30
        );

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('transite par ce serveur', implode(' ', $warnings));
    }

    public function testALocalSourceAndALocalDestinationAreNotWarnedAbout(): void
    {
        $source = $this->locations->findById($this->local('Galerie')) ?? $this->fail('missing');
        $destination = $this->locations->findById($this->local('NAS', 'nas')) ?? $this->fail('missing');

        $this->assertSame([], $this->service->warningsFor($source, $destination, 30));
    }

    /**
     * **The grace period against the restore horizon is arithmetic, not
     * documentation.** Restoring a database older than the grace period
     * resurrects `gallery_media` rows whose files the copy has already
     * purged: the albums come back holed, permanently, and nothing
     * anywhere says why.
     */
    public function testAGracePeriodShorterThanTheOldestRestorableBackupIsWarnedAbout(): void
    {
        $this->completeBackupAgedInDays(21);
        $source = $this->locations->findById($this->local('Galerie')) ?? $this->fail('missing');
        $destination = $this->locations->findById($this->local('NAS', 'nas')) ?? $this->fail('missing');

        $warnings = $this->service->warningsFor($source, $destination, 7);

        $this->assertNotEmpty($warnings);
        $joined = implode(' ', $warnings);
        $this->assertStringContainsString('21 jours', $joined);
        $this->assertStringContainsString('7 jours', $joined);
    }

    public function testAGracePeriodLongerThanTheHorizonIsNotWarnedAbout(): void
    {
        $this->completeBackupAgedInDays(21);
        $source = $this->locations->findById($this->local('Galerie')) ?? $this->fail('missing');
        $destination = $this->locations->findById($this->local('NAS', 'nas')) ?? $this->fail('missing');

        $this->assertSame([], $this->service->warningsFor($source, $destination, 30));
    }

    /**
     * An installation with no restorable backup has no horizon to compare
     * against — and says nothing, rather than inventing a number.
     */
    public function testAnInstallationWithNoBackupHasNoHorizon(): void
    {
        $this->assertNull($this->service->restorableHorizonInDays());
    }

    /**
     * A backup with no database dump is not restorable, so it sets no
     * horizon: what matters to this calculation is rows coming back, and
     * a file-only archive brings none.
     */
    public function testABackupWithoutADatabaseDumpSetsNoHorizon(): void
    {
        $backups = new BackupRepository($this->pdo);
        $id = $backups->create('full_no_gallery', null);
        $this->pdo->prepare(
            "UPDATE backups SET status = 'completed', completed_at = ?, db_dump_file_id = NULL WHERE id = ?"
        )->execute([(new \DateTimeImmutable('-21 days'))->format('Y-m-d H:i:s'), $id]);

        $this->assertNull($this->service->restorableHorizonInDays());
    }

    private function object(string $label, string $bucket): int
    {
        return $this->locations->create(
            StorageLocationType::ObjectStorage,
            $label,
            new ObjectStorageLocationConfig(
                endpoint: 'https://s3.example.org',
                region: 'eu-west-1',
                bucket: $bucket,
                accessKey: 'AKIA'
            ),
            'un-secret'
        );
    }

    private function publishingObject(string $label, string $bucket): int
    {
        return $this->locations->create(
            StorageLocationType::ObjectStorage,
            $label,
            new ObjectStorageLocationConfig(
                endpoint: 'https://s3.example.org',
                region: 'eu-west-1',
                bucket: $bucket,
                accessKey: 'AKIA',
                publicUrl: 'https://cdn.example.org/' . $bucket
            ),
            'un-secret'
        );
    }

    private function completeBackupAgedInDays(int $days): void
    {
        $backups = new BackupRepository($this->pdo);
        $id = $backups->create('full_no_gallery', null);
        $this->pdo->prepare(
            "UPDATE backups SET status = 'completed', completed_at = ?, db_dump_file_id = 1 WHERE id = ?"
        )->execute([(new \DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d H:i:s'), $id]);
    }
}
