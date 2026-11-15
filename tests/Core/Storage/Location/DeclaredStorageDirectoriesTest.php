<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location;

use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\DeclaredStorageDirectories;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The directories an archive leaves out, read from the locations an
 * installation declared — and the two states where there is nothing to
 * leave out although something is there to read.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class DeclaredStorageDirectoriesTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private StorageLocationRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new StorageLocationRepository($this->pdo, $this->encryption);
    }

    /**
     * A relative path that climbs out of `storage/` is refused by the
     * factory, so nothing of this site was ever written there: it is not
     * declared, and the location beside it still is.
     */
    public function testAPathThisSiteRefusesToOpenIsNotDeclared(): void
    {
        $this->repository->create(StorageLocationType::Local, 'Galerie', new LocalLocationConfig('gallery'), null);
        $this->repository->create(StorageLocationType::Local, 'Web', new LocalLocationConfig('../public'), null);

        $declared = DeclaredStorageDirectories::of(
            $this->repository,
            new StorageBackendFactory($this->repository, '/srv/scoutmagic/storage')
        );

        $this->assertSame(['/srv/scoutmagic/storage/gallery'], $declared->all());
    }

    /**
     * The pre-update backup of a version older than the storage chantier
     * runs before the migration creates `storage_locations`: no table is
     * no location declared, and the backup goes ahead.
     */
    public function testAnInstallationWithoutTheLocationsTableDeclaresNone(): void
    {
        $this->repository->create(StorageLocationType::Local, 'Galerie', new LocalLocationConfig('gallery'), null);
        $this->assertSame(
            ['/srv/scoutmagic/storage/gallery'],
            DeclaredStorageDirectories::fromDatabase($this->pdo, $this->encryption, '/srv/scoutmagic/storage')->all()
        );

        $this->pdo->exec('DROP TABLE storage_locations');

        $this->assertSame(
            [],
            DeclaredStorageDirectories::fromDatabase($this->pdo, $this->encryption, '/srv/scoutmagic/storage')->all()
        );
    }
}
