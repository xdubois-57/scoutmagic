<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Protection;

use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\Protection\StorageProtectionConsumer;
use Core\Storage\Location\Protection\StorageProtectionRepository;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * A safety copy stands on its destination, and the screen has to say so.
 *
 * **The first consumer that is part of the storage subsystem itself**, so
 * it is also the first that no module enables. Registering it beside the
 * gallery's, inside a module block, would have made it disappear on any
 * installation with that module turned off — which is exactly the kind of
 * hole the registry exists to close.
 */
final class StorageProtectionConsumerTest extends TestCase
{
    private \PDO $pdo;
    private StorageLocationRepository $locations;
    private StorageProtectionRepository $protections;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->locations = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->protections = new StorageProtectionRepository($this->pdo);
    }

    private function declareLocal(string $label, string $path): int
    {
        return $this->locations->create(
            StorageLocationType::Local,
            $label,
            new LocalLocationConfig($path),
            null
        );
    }

    public function testTheDestinationIsDeclaredInUseAndTheSourceIsNot(): void
    {
        $source = $this->declareLocal('Galerie', 'gallery');
        $destination = $this->declareLocal('NAS', 'nas');
        $this->protections->save($source, $destination, 30, 24, true);

        $consumer = new StorageProtectionConsumer($this->protections, $this->locations);

        $this->assertSame([$destination], $consumer->locationIdsInUse());
        // A location is not kept alive by being protected: deleting it
        // means its content stops being copied, and the relation goes with
        // it (ON DELETE CASCADE).
        $this->assertNotContains($source, $consumer->locationIdsInUse());
    }

    /**
     * **The refusal is a French sentence, not a foreign-key error.**
     *
     * `RESTRICT` on the destination would stop the deletion either way —
     * as an uncaught PDOException and a 500 in front of an administrator,
     * which is the whole thing this interface replaces.
     */
    public function testDeletingADestinationIsRefusedByNameRatherThanByTheDatabase(): void
    {
        $source = $this->declareLocal('Galerie', 'gallery');
        $destination = $this->declareLocal('NAS', 'nas');
        $this->protections->save($source, $destination, 30, 24, true);

        $consumers = new StorageLocationConsumerRegistry();
        $consumers->register(new StorageProtectionConsumer($this->protections, $this->locations));
        $service = new StorageLocationService(
            $this->locations,
            new StorageBackendFactory($this->locations, sys_get_temp_dir()),
            $consumers
        );

        $this->expectException(StorageLocationException::class);
        $this->expectExceptionMessageMatches('/Copies de secours/');
        $service->delete($destination);
    }

    private function declareBucket(string $label, ?string $publicUrl): int
    {
        return $this->locations->create(
            StorageLocationType::ObjectStorage,
            $label,
            new ObjectStorageLocationConfig(
                endpoint: 'https://s3.example',
                region: 'eu-west-1',
                bucket: 'seau',
                accessKey: 'AKIA',
                publicUrl: $publicUrl
            ),
            null
        );
    }

    /**
     * **Publishing a location that holds somebody's private copy is
     * refused, and this is the other half of the declaration-time rule.**
     *
     * `StorageProtectionService::refuseExposure()` asks the question once,
     * when the relation is declared. The same exposure is reachable one
     * screen later from the other end: declare A → B while B publishes
     * nothing, let a pass copy A's files into B, then give B a public URL.
     * Nothing about that edit concerns A, no consumer stands on B, and
     * every file copied out of A becomes readable by whoever has the
     * address.
     */
    public function testPublishingADestinationThatHoldsAPrivateCopyIsRefused(): void
    {
        $source = $this->declareLocal('Galerie', 'gallery');
        $destination = $this->declareBucket('Seau de secours', null);
        $this->protections->save($source, $destination, 30, 24, true);

        $consumer = new StorageProtectionConsumer($this->protections, $this->locations);
        $location = $this->locations->findById($destination) ?? $this->fail('missing');

        $objection = $consumer->objectionTo(
            $location,
            new ObjectStorageLocationConfig(
                endpoint: 'https://s3.example',
                region: 'eu-west-1',
                bucket: 'seau',
                accessKey: 'AKIA',
                publicUrl: 'https://cdn.example'
            ),
            false
        );

        $this->assertNotNull($objection);
        $this->assertStringContainsString('Galerie', $objection);
        $this->assertStringContainsString('copie de secours', $objection);
    }

    /**
     * A source that already publishes loses nothing by being copied
     * somewhere that publishes too — the same asymmetry the
     * declaration-time refusal is built on.
     */
    public function testPublishingADestinationWhoseSourceAlreadyPublishesIsAllowed(): void
    {
        $source = $this->declareBucket('Galerie publique', 'https://photos.example');
        $destination = $this->declareBucket('Seau de secours', null);
        $this->protections->save($source, $destination, 30, 24, true);

        $consumer = new StorageProtectionConsumer($this->protections, $this->locations);
        $location = $this->locations->findById($destination) ?? $this->fail('missing');

        $this->assertNull($consumer->objectionTo(
            $location,
            new ObjectStorageLocationConfig(
                endpoint: 'https://s3.example',
                region: 'eu-west-1',
                bucket: 'seau',
                accessKey: 'AKIA',
                publicUrl: 'https://cdn.example'
            ),
            false
        ));
    }

    /** A change that does not publish is not this rule's business. */
    public function testAChangeThatDoesNotPublishIsNotObjectedTo(): void
    {
        $source = $this->declareLocal('Galerie', 'gallery');
        $destination = $this->declareBucket('Seau de secours', null);
        $this->protections->save($source, $destination, 30, 24, true);

        $consumer = new StorageProtectionConsumer($this->protections, $this->locations);
        $location = $this->locations->findById($destination) ?? $this->fail('missing');

        $this->assertNull($consumer->objectionTo($location, $location->config, false));
    }

    /**
     * And it is registered where no module can take it away.
     *
     * The registry is filled by each module's own block further down
     * `public/index.php`; this consumer belongs to none of them, so its
     * registration has to sit in the unconditional storage wiring above
     * the first `if ($isEnabled(...))`. Registered inside a module block
     * instead, it would answer nothing on an installation with that module
     * disabled — and a destination would delete straight into the foreign
     * key.
     */
    public function testTheCompositionRootRegistersItOutsideEveryModuleBlock(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 5) . '/public/index.php');

        $registration = strpos($index, 'Protection\\StorageProtectionConsumer(');
        $this->assertNotFalse($registration, 'nothing registers StorageProtectionConsumer in public/index.php');

        $firstModuleBlock = strpos($index, 'if ($isEnabled(');
        $this->assertNotFalse($firstModuleBlock);
        $this->assertLessThan(
            $firstModuleBlock,
            $registration,
            'the safety-copy consumer must be registered unconditionally, not inside a module block'
        );
    }
}
