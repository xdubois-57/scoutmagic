<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location;

use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Backend\StorageBackendInterface;
use Core\Storage\Location\Backend\WebDav\WebDavClient;
use Core\Storage\Location\Backend\WebDavBackend;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\GoogleDriveSecret;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\LocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\Config\WebDavLocationConfig;
use Core\Storage\Location\StorageListing;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationConsumer;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationTargetNotEmptyException;
use Core\Storage\Location\StorageLocationType;
use Core\Storage\Location\StoredObject;
use PHPUnit\Framework\TestCase;
use Tests\Core\Storage\Location\Backend\Drive\FakeDrive;
use Tests\Core\Storage\Location\Backend\RefusingBackend;
use Tests\Core\Storage\Location\Backend\WebDav\FakeWebDavServer;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class StorageLocationServiceTest extends TestCase
{
    private \PDO $pdo;
    private StorageLocationRepository $repository;
    private StorageLocationConsumerRegistry $consumers;
    private StorageLocationService $service;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-locations-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);
        $this->consumers = new StorageLocationConsumerRegistry();
        $this->service = new StorageLocationService(
            $this->repository,
            new StorageBackendFactory($this->repository, $this->storagePath),
            $this->consumers
        );
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storagePath)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }
        @rmdir($this->storagePath);
    }

    /**
     * `isEmpty()` answers from memory, and that is the whole reason it
     * exists next to `all()`.
     *
     * The response tail asks it on **every** request to decide whether
     * the Content-Security-Policy has a storage origin to name. Asking
     * `all()` there would have each registered consumer read the database
     * to answer — the cost being avoided rather than paid — and reading
     * the locations unconditionally made every route of a site with no
     * gallery pay for a query against a table it will never use.
     */
    public function testTheRegistryKnowsWhetherAnybodyConsumesStorageWithoutAskingThem(): void
    {
        $this->assertTrue($this->consumers->isEmpty());

        $asked = false;
        $this->consumers->register(new class ($asked) implements StorageLocationConsumer {
            public function __construct(private bool &$asked)
            {
            }

            public function usageLabel(): string
            {
                return 'Galeries photo';
            }

            public function locationIdsInUse(): array
            {
                $this->asked = true;

                return [];
            }

            /** This double holds nothing that a destination could strand. */
            public function objectionTo(
                StorageLocation $location,
                LocationConfig $proposedConfig,
                bool $wouldBeDefault
            ): ?string {
                return null;
            }
        });

        $this->assertFalse($this->consumers->isEmpty());
        $this->assertFalse($asked, 'isEmpty() must not ask a consumer anything — that is what costs a query.');
    }

    public function testEnsureDefaultExistsCreatesOneOnAFreshInstallation(): void
    {
        $this->assertNull($this->repository->findDefault());

        $created = $this->service->ensureDefaultExists();

        $this->assertNotNull($created);
        $this->assertSame(StorageLocationService::DEFAULT_LABEL, $created->label);
        $this->assertTrue($created->isDefault);
        $this->assertInstanceOf(LocalLocationConfig::class, $created->config);
    }

    public function testEnsureDefaultExistsIsANoOpOnceALocationExists(): void
    {
        $id = $this->service->create(
            StorageLocationType::Local,
            'Déjà là',
            new LocalLocationConfig('ailleurs'),
            null
        );

        $this->assertSame($id, $this->service->ensureDefaultExists()?->id);
        $this->assertCount(1, $this->repository->findAll());
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function lostRaceFailures(): array
    {
        // The loser arrives by three routes, not one. create() takes a gap
        // lock, and gap locks do not conflict with each other — what
        // conflicts is the INSERT's insert-intention lock against the
        // other's gap lock, which InnoDB resolves as a deadlock or a
        // lock-wait timeout. The UNIQUE index's 1062 is only the shape the
        // race takes when both callers reach the INSERT.
        return [
            'duplicate label' => ['23000', 1062, 'Duplicate entry'],
            'deadlock' => ['40001', 1213, 'Deadlock found when trying to get lock'],
            'lock wait timeout' => ['HY000', 1205, 'Lock wait timeout exceeded'],
        ];
    }

    /**
     * @dataProvider lostRaceFailures
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lostRaceFailures')]
    public function testEnsureDefaultExistsAdoptsWhatARaceCreatedRatherThanFailingTheRequest(
        string $sqlState,
        int $driverCode,
        string $message
    ): void {
        // On a fresh install the table is empty and a browser fetching
        // several renditions of one photo calls this concurrently — so
        // treating any of these as a real failure 500s the very request
        // the method exists to keep working.
        $winner = $this->repository->create(
            StorageLocationType::Local,
            StorageLocationService::DEFAULT_LABEL,
            new LocalLocationConfig(StorageLocationService::DEFAULT_PATH),
            null
        );
        $service = $this->serviceWhoseCreateThrows(
            self::pdoException($sqlState, $driverCode, $message)
        );

        $this->assertSame($winner, $service->ensureDefaultExists()?->id);
    }

    public function testAFailureThatIsNotALostRaceTravelsRatherThanBecomingNoDefaultLocation(): void
    {
        // A missing table, a dead connection, a refused write: swallowing
        // those turns a database fault into « cette installation n'a pas
        // d'emplacement par défaut », a sentence that sends an
        // administrator looking at the storage configuration instead.
        $service = $this->serviceWhoseCreateThrows(
            self::pdoException('42S02', 1146, "Table 'storage_locations' doesn't exist")
        );

        $this->expectException(\PDOException::class);
        $service->ensureDefaultExists();
    }

    /**
     * A service whose `create()` fails the way a lost race fails.
     *
     * **The first `findDefault()` answers null on purpose.** That is what
     * makes this the race rather than a no-op: `ensureDefaultExists()`
     * looks first and returns early when a default already exists, so a
     * double that only throws from `create()` never reaches the `catch`
     * being tested and the test passes whatever the catch allows. The
     * first read is the one taken before the winner committed; every read
     * after it sees the winner's row, which is what the method must go on
     * to return.
     */
    private function serviceWhoseCreateThrows(\PDOException $failure): StorageLocationService
    {
        $repository = new class ($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $failure) extends StorageLocationRepository {
            private bool $looked = false;

            public function __construct(\PDO $pdo, EncryptionService $encryption, private \PDOException $failure)
            {
                parent::__construct($pdo, $encryption);
            }

            public function findDefault(): ?StorageLocation
            {
                if (!$this->looked) {
                    $this->looked = true;

                    return null;
                }

                return parent::findDefault();
            }

            public function create(
                StorageLocationType $type,
                string $label,
                \Core\Storage\Location\Config\LocationConfig $config,
                ?string $secret
            ): int {
                throw $this->failure;
            }
        };

        return new StorageLocationService(
            $repository,
            new StorageBackendFactory($repository, $this->storagePath),
            $this->consumers
        );
    }

    private static function pdoException(string $sqlState, int $driverCode, string $message): \PDOException
    {
        return new class ($sqlState, $driverCode, $message) extends \PDOException {
            public function __construct(string $sqlState, int $driverCode, string $message)
            {
                parent::__construct($message);
                $this->code = $sqlState;
                $this->errorInfo = [$sqlState, $driverCode, $message];
            }
        };
    }

    public function testTwoLocationsCannotShareALabel(): void
    {
        $this->service->create(StorageLocationType::Local, 'Nextcloud', new LocalLocationConfig('a'), null);

        $this->expectException(StorageLocationException::class);
        $this->service->create(StorageLocationType::Local, 'Nextcloud', new LocalLocationConfig('b'), null);
    }

    public function testRenamingALocationToItsOwnLabelIsAllowed(): void
    {
        $id = $this->service->create(StorageLocationType::Local, 'Nextcloud', new LocalLocationConfig('a'), null);

        $this->service->update($id, 'Nextcloud', new LocalLocationConfig('b'), null);

        $this->assertInstanceOf(LocalLocationConfig::class, $this->service->findById($id)?->config);
        $this->assertSame('b', $this->service->findById($id)?->config->path);
    }

    public function testDeletingALocationAConsumerStandsOnIsRefusedByName(): void
    {
        $id = $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        $this->consumers->register($this->consumerNamed('Galeries photo', [$id]));

        try {
            $this->service->delete($id);
            $this->fail('A location still in use must not be deletable.');
        } catch (StorageLocationException $e) {
            // The usage is NAMED: a count would say « 1 » and leave the
            // administrator to guess one what.
            $this->assertStringContainsString('Galeries photo', $e->getMessage());
        }

        $this->assertNotNull($this->service->findById($id));
    }

    public function testAConsumerThatDependsOnTheDefaultWithoutNamingItStillBlocksItsDeletion(): void
    {
        // The shape this exists for: a consumer whose rows resolve to
        // « the default » lazily rather than storing an identifier. Such a
        // consumer depends on the location as completely as one that names
        // it, and reporting only the names it has written down is how a
        // default gets deleted out from under the files standing on it.
        $defaultId = $this->service->ensureDefaultExists()?->id;
        $this->assertNotNull($defaultId);
        $this->consumers->register($this->consumerNamed('Galeries photo', [$defaultId]));

        $this->expectException(StorageLocationException::class);
        $this->service->delete($defaultId);
    }

    public function testDeletingAnUnusedLocationSucceeds(): void
    {
        $id = $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        $this->consumers->register($this->consumerNamed('Galeries photo', [$id + 999]));

        $this->service->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    // ————— A Drive location's own folder follows it (#474) —————

    public function testRenamingADriveLocationRenamesItsFolder(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();

        $outcome = $service->update($id, 'Galeries du groupe', $this->driveConfig($drive), null);

        $this->assertTrue($outcome->isDone());
        $this->assertSame('Galeries du groupe', $drive->files['folder-1']['name']);
    }

    /** Saving without changing the label touches nothing at Google. */
    public function testSavingADriveLocationUnderTheSameLabelLeavesItsFolderAlone(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();
        $before = $drive->requests;

        $outcome = $service->update($id, 'Photos des galeries', $this->driveConfig($drive), null);

        $this->assertFalse($outcome->isDone());
        $this->assertFalse($outcome->isFailed());
        $this->assertSame($before, $drive->requests);
    }

    /**
     * **A rename Google refuses is not saved** (#832). The location's
     * folder is ScoutMagic's own, and a name accepted here but not there
     * would leave the two disagreeing. The cause travels as the previous
     * exception, for the journal.
     */
    public function testARenameGoogleRefusesIsNotSavedAndTheLabelStays(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();
        $drive->failPatchesWith = 500;

        try {
            $service->update($id, 'Galeries du groupe', $this->driveConfig($drive), null);
            $this->fail('A rename Google refused must not be saved.');
        } catch (StorageLocationException $e) {
            $this->assertStringContainsString('Le nom n\'a pas été changé', $e->getMessage());
            $this->assertStringContainsString('500', (string) $e->getPrevious()?->getMessage());
        }

        $this->assertSame('Photos des galeries', $this->repository->findById($id)?->label);
        $this->assertSame('Photos des galeries', $drive->files['folder-1']['name']);
    }

    /**
     * The case of the ticket: after a restore, the location points at a
     * folder that is gone or in the trash. Google would rename a trashed
     * folder without complaint, so the folder is looked at first — and
     * renaming the location never stands in for repairing it.
     */
    public function testARenameOfALocationWhoseFolderIsInTheTrashIsRefused(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();
        $drive->files['folder-1']['trashed'] = true;

        try {
            $service->update($id, 'Drive 2', $this->driveConfig($drive), null);
            $this->fail('A folder in the trash must not take a new name.');
        } catch (StorageLocationException $e) {
            $this->assertStringContainsString('dans la corbeille', (string) $e->getPrevious()?->getMessage());
        }

        $this->assertSame('Photos des galeries', $this->repository->findById($id)?->label);
        $this->assertSame('Photos des galeries', $drive->files['folder-1']['name']);
        $this->assertSame((string) $drive->folderId, $this->driveFolderOf($id), 'a rename must never change the folder');
    }

    /**
     * Disconnected, the site has no grant to reach the folder with — so
     * the name cannot be applied there, and is not accepted here either.
     */
    public function testARenameWhileDisconnectedIsRefused(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();
        $disconnected = new GoogleDriveLocationConfig('client-1', (string) $drive->folderId, '');
        $service->update($id, 'Photos des galeries', $disconnected, null);
        $before = $drive->requests;

        try {
            $service->update($id, 'Galeries du groupe', $disconnected, null);
            $this->fail('A disconnected location must not be renamed.');
        } catch (StorageLocationException $e) {
            $this->assertStringContainsString('plus raccordé à Google Drive', $e->getMessage());
        }

        $this->assertSame('Photos des galeries', $this->repository->findById($id)?->label);
        $this->assertSame($before, $drive->requests, 'nothing is asked of Google without a grant');
    }

    /**
     * A client secret corrected in the same submission as a new label is
     * the one the rename uses — so it goes through, and the correction is
     * saved with it rather than lost to a refusal the stale one caused.
     */
    public function testARenameUsesTheClientSecretBeingSaved(): void
    {
        $drive = new FakeDrive();
        $drive->acceptedClientSecret = 'secret-corrige';
        $service = $this->serviceOn($drive);
        $stale = new GoogleDriveSecret('secret-perime', 'refresh-1', 'unite@example.org');
        $id = $service->create(
            StorageLocationType::GoogleDrive,
            'Photos des galeries',
            $this->driveConfig($drive),
            (string) $stale->toStorage()
        );

        $service->update(
            $id,
            'Galeries du groupe',
            $this->driveConfig($drive),
            (string) $stale->withClientSecret('secret-corrige')->toStorage()
        );

        $this->assertSame('Galeries du groupe', $drive->files['folder-1']['name']);
        $this->assertSame('Galeries du groupe', $this->repository->findById($id)?->label);
        $this->assertSame(
            'secret-corrige',
            GoogleDriveSecret::fromStorage($this->repository->getSecret($id))->clientSecret
        );
    }

    /**
     * The folder took the new name, then the save failed: the folder gets
     * its old name back, so nothing changed anywhere — and the save's own
     * failure is what reaches the caller.
     */
    public function testAFailedSaveAfterTheRenameGivesTheFolderItsOldNameBack(): void
    {
        $drive = new FakeDrive();
        $failure = new \RuntimeException('base indisponible');
        $repository = new class ($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $failure) extends StorageLocationRepository {
            public bool $failUpdates = false;

            public function __construct(\PDO $pdo, EncryptionService $encryption, private \RuntimeException $failure)
            {
                parent::__construct($pdo, $encryption);
            }

            public function update(int $id, string $label, LocationConfig $config, ?string $secret): void
            {
                if ($this->failUpdates) {
                    throw $this->failure;
                }
                parent::update($id, $label, $config, $secret);
            }
        };
        $service = new StorageLocationService(
            $repository,
            new StorageBackendFactory($repository, $this->storagePath, $drive->client()),
            $this->consumers
        );
        $id = $service->create(
            StorageLocationType::GoogleDrive,
            'Photos des galeries',
            $this->driveConfig($drive),
            (string) (new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org'))->toStorage()
        );
        $repository->failUpdates = true;

        try {
            $service->update($id, 'Galeries du groupe', $this->driveConfig($drive), null);
            $this->fail('The failed save must reach the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame('Photos des galeries', $drive->files['folder-1']['name']);
        $this->assertSame('Photos des galeries', $this->repository->findById($id)?->label);
    }

    /** A Drive location never connected has no folder: its label is free to change. */
    public function testANeverConnectedDriveLocationIsRenamedWithoutAskingGoogle(): void
    {
        $drive = new FakeDrive();
        $service = $this->serviceOn($drive);
        $id = $service->create(StorageLocationType::GoogleDrive, 'Pas encore', new GoogleDriveLocationConfig('c'), null);

        $outcome = $service->update($id, 'Toujours pas', new GoogleDriveLocationConfig('c'), null);

        $this->assertFalse($outcome->isDone());
        $this->assertSame('Toujours pas', $this->repository->findById($id)?->label);
        $this->assertSame(0, $drive->requests);
    }

    // ————— Attaching a location to another target (#832) —————

    /**
     * Changing where a location points attaches it to another storage —
     * and when that one already holds files, the save waits until the
     * administrator has seen it. Nothing in it is touched either way.
     */
    public function testPointingALocationAtAFolderThatHoldsFilesWaitsForAConfirmation(): void
    {
        $id = $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('vide'), null);
        mkdir($this->storagePath . '/plein', 0777, true);
        file_put_contents($this->storagePath . '/plein/photo.jpg', 'octets');

        try {
            $this->service->update($id, 'Disque', new LocalLocationConfig('plein'), null);
            $this->fail('A target holding files must not be attached unseen.');
        } catch (StorageLocationTargetNotEmptyException $e) {
            $this->assertStringContainsString('contient déjà des fichiers', $e->getMessage());
        }
        $this->assertSame('vide', $this->localPathOf($id));

        $this->service->update($id, 'Disque', new LocalLocationConfig('plein'), null, true);

        $this->assertSame('plein', $this->localPathOf($id));
        $this->assertSame('octets', file_get_contents($this->storagePath . '/plein/photo.jpg'));
    }

    /** An empty target needs nothing — and looking at it creates nothing. */
    public function testPointingALocationAtAnEmptyFolderAsksNothingAndCreatesNothing(): void
    {
        $id = $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('vide'), null);

        $this->service->update($id, 'Disque', new LocalLocationConfig('nouveau'), null);

        $this->assertSame('nouveau', $this->localPathOf($id));
        $this->assertDirectoryDoesNotExist($this->storagePath . '/nouveau');
    }

    /** A rename keeps the target: the folder is not even looked at. */
    public function testRenamingALocationWhoseFolderHoldsFilesAsksNothing(): void
    {
        mkdir($this->storagePath . '/plein', 0777, true);
        file_put_contents($this->storagePath . '/plein/photo.jpg', 'octets');
        $id = $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('plein'), null, true);

        $this->service->update($id, 'Disque du local', new LocalLocationConfig('plein'), null);

        $this->assertSame('Disque du local', $this->repository->findById($id)?->label);
    }

    /** Declaring a new location on a folder that holds files is attaching it too. */
    public function testDeclaringALocationOnAFolderThatHoldsFilesWaitsForAConfirmation(): void
    {
        mkdir($this->storagePath . '/plein', 0777, true);
        file_put_contents($this->storagePath . '/plein/photo.jpg', 'octets');

        try {
            $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('plein'), null);
            $this->fail('A target holding files must not be attached unseen.');
        } catch (StorageLocationTargetNotEmptyException) {
        }
        $this->assertSame([], $this->repository->findAll());

        $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('plein'), null, true);

        $this->assertCount(1, $this->repository->findAll());
    }

    /**
     * A WebDAV share is a target like a local folder: declaring a location
     * on one that already holds files waits for a confirmation, and the
     * share is asked once, at its top, whatever lies deeper in it.
     */
    public function testDeclaringALocationOnAShareThatHoldsFilesWaitsForAConfirmation(): void
    {
        $share = new FakeWebDavServer();
        $probes = new ProbeRecorder(new WebDavBackend(
            new WebDavClient($share->transport()),
            new WebDavLocationConfig($share->baseUrl, 'unite'),
            'le-mot-de-passe'
        ));
        $service = $this->serviceProbing($probes);
        $config = new WebDavLocationConfig($share->baseUrl, 'unite');

        $service->create(StorageLocationType::WebDav, 'Partage vide', $config, 'le-mot-de-passe');
        $this->assertSame([$share->baseUrl], $probes->targets);

        $probes->backend->put('12/13/photo.jpg', 'octets', 'image/jpeg');
        $share->calls = [];
        try {
            $service->create(StorageLocationType::WebDav, 'Partage plein', $config, 'le-mot-de-passe');
            $this->fail('A share holding files must not be attached unseen.');
        } catch (StorageLocationTargetNotEmptyException $e) {
            $this->assertStringContainsString('contient déjà des fichiers', $e->getMessage());
        }
        $this->assertCount(1, $share->calls);
        $this->assertCount(1, $this->repository->findAll());
    }

    /**
     * A bucket has no cheap « anything here? » of its own: one entry of
     * its listing answers, which S3 caps on the server. What names the
     * target is the endpoint and the bucket — a new public address or
     * access key reaches the same objects and is not looked at.
     */
    public function testPointingALocationAtABucketThatHoldsObjectsWaitsForAConfirmation(): void
    {
        $bucket = new class extends RefusingBackend {
            /** @var list<int> */
            public array $limits = [];
            /** @var list<StoredObject> */
            public array $objects = [];

            public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
            {
                $this->limits[] = $limit;

                return new StorageListing(array_slice($this->objects, 0, $limit));
            }
        };
        $probes = new ProbeRecorder($bucket);
        $service = $this->serviceProbing($probes);
        $id = $service->create(
            StorageLocationType::ObjectStorage,
            'Bucket',
            new ObjectStorageLocationConfig('https://s3.example.org', 'eu', 'vide', 'ak'),
            'sk'
        );

        $service->update(
            $id,
            'Bucket',
            new ObjectStorageLocationConfig('https://s3.example.org', 'eu', 'vide', 'ak2', null, 'https://cdn.example.org'),
            null
        );
        $this->assertSame(['https://s3.example.org/vide'], $probes->targets);

        $bucket->objects = [new StoredObject('12/a.jpg', 6), new StoredObject('12/b.jpg', 6)];
        try {
            $service->update($id, 'Bucket', new ObjectStorageLocationConfig('https://s3.example.org', 'eu', 'plein', 'ak'), null);
            $this->fail('A bucket holding objects must not be attached unseen.');
        } catch (StorageLocationTargetNotEmptyException) {
        }
        $this->assertSame(['https://s3.example.org/vide', 'https://s3.example.org/plein'], $probes->targets);
        $this->assertSame([1, 1], $bucket->limits);
        $config = $this->repository->findById($id)?->config;
        $this->assertInstanceOf(ObjectStorageLocationConfig::class, $config);
        $this->assertSame('vide', $config->bucket);

        $service->update($id, 'Bucket', new ObjectStorageLocationConfig('https://s3.example.org', 'eu', 'plein', 'ak'), null, true);

        $config = $this->repository->findById($id)?->config;
        $this->assertInstanceOf(ObjectStorageLocationConfig::class, $config);
        $this->assertSame('plein', $config->bucket);
    }

    /**
     * The repair the ticket asks for: a restore brought back a folder id
     * that leads nowhere, the files are in another folder, and the
     * location is attached to it — by its address, after a look, and
     * once confirmed, since it holds files. Its label stays.
     */
    public function testADriveLocationIsAttachedToTheFolderHoldingItsFiles(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();
        $drive->files['folder-1']['trashed'] = true;
        $current = $drive->addFolder('Drive', (string) $drive->parentFolderId, 'dossier-actuel-0001');
        $drive->put('photo.jpg', 'octets', 'image/jpeg', $current);

        try {
            $service->attachDriveFolder($id, 'https://drive.google.com/drive/folders/' . $current . '?usp=sharing', false);
            $this->fail('A folder holding files must not be attached unseen.');
        } catch (StorageLocationTargetNotEmptyException $e) {
            $this->assertStringContainsString('« Drive » contient déjà des fichiers', $e->getMessage());
        }
        $this->assertSame('folder-1', $this->driveFolderOf($id));

        $name = $service->attachDriveFolder($id, 'https://drive.google.com/drive/folders/' . $current, true);

        $this->assertSame('Drive', $name);
        $this->assertSame($current, $this->driveFolderOf($id));
        $this->assertSame('Photos des galeries', $this->repository->findById($id)?->label);
        $this->assertSame('octets', $drive->contentOf('photo.jpg'));
        $this->assertSame('Drive', $drive->files[$current]['name'], 'attaching renames nothing');
    }

    /**
     * Refused before anything is saved: a folder this account cannot see,
     * one in the trash, a file, another location's folder, the one it
     * already has, and something that is not an address at all.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function foldersThatCannotBeAttached(): array
    {
        return [
            'unknown' => ['dossier-inconnu-0001', 'ne voit aucun dossier'],
            'trashed' => ['dossier-corbeille-01', 'dans la corbeille'],
            'a file' => ['fichier-pas-dossier1', 'pas un dossier'],
            'another location\'s' => ['dossier-autre-00001', 'Sauvegardes'],
            'its own' => ['folder-1-courant-01', 'déjà rattaché'],
            'not an address' => ['?', 'Collez l\'adresse'],
            // Deleting a location trashes its folder with all it holds: one
            // attached around or inside another location's would take that
            // location's files with it.
            'the shared parent' => ['scoutmagic-1', 'dossier commun'],
            'inside another location\'s' => ['sous-dossier-autre1', 'rangé dans celui'],
            'around another location\'s' => ['conteneur-0000001', 'contient celui'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foldersThatCannotBeAttached')]
    public function testAFolderThatCannotBeTheLocationsIsRefused(string $reference, string $reason): void
    {
        $drive = new FakeDrive('folder-1-courant-01');
        $service = $this->serviceOn($drive);
        $id = $service->create(
            StorageLocationType::GoogleDrive,
            'Photos des galeries',
            $this->driveConfig($drive),
            (string) (new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org'))->toStorage()
        );
        $parent = (string) $drive->parentFolderId;
        $drive->addFolder('Ancien', $parent, 'dossier-corbeille-01');
        $drive->files['dossier-corbeille-01']['trashed'] = true;
        $drive->put('note.txt', 'x', 'text/plain', $parent);
        $file = array_key_last($drive->files);
        $drive->files['fichier-pas-dossier1'] = $drive->files[$file];
        $container = $drive->addFolder('Conteneur', $parent, 'conteneur-0000001');
        $drive->addFolder('Autre', $container, 'dossier-autre-00001');
        $drive->addFolder('Sous-dossier', 'dossier-autre-00001', 'sous-dossier-autre1');
        $service->create(
            StorageLocationType::GoogleDrive,
            'Sauvegardes',
            new GoogleDriveLocationConfig('client-1', 'dossier-autre-00001', '2026-09-01T00:00:00+00:00'),
            null
        );

        try {
            $service->attachDriveFolder($id, $reference, true);
            $this->fail('This folder must not be attached.');
        } catch (StorageLocationException $e) {
            $this->assertNotInstanceOf(StorageLocationTargetNotEmptyException::class, $e);
            $this->assertStringContainsString($reason, $e->getMessage());
        }

        $this->assertSame('folder-1-courant-01', $this->driveFolderOf($id));
    }

    /**
     * The shared folder is recognised by its id, not its name: renamed in
     * Drive, and with no other location to give it away, it is still
     * refused — deleting the location would trash everything it holds.
     */
    public function testARenamedSharedFolderIsStillRefused(): void
    {
        $drive = new FakeDrive('folder-1-courant-01');
        $service = $this->serviceOn($drive);
        $id = $service->create(
            StorageLocationType::GoogleDrive,
            'Photos des galeries',
            $this->driveConfig($drive),
            (string) (new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org'))->toStorage()
        );
        $drive->files['scoutmagic-1']['name'] = 'Ancien ScoutMagic';
        $drive->files['folder-1-courant-01']['trashed'] = true;

        try {
            $service->attachDriveFolder($id, 'scoutmagic-1', true);
            $this->fail('The shared folder must not be attached under another name.');
        } catch (StorageLocationException $e) {
            $this->assertStringContainsString('dossier commun', $e->getMessage());
        }
        $this->assertSame('folder-1-courant-01', $this->driveFolderOf($id));
    }

    /**
     * …and still when the location's own folder is gone for good, which is
     * exactly when it is repaired: the shared folder sits at the top of
     * the drive, where no location's own folder ever does.
     */
    public function testARenamedSharedFolderIsRefusedEvenOnceTheLocationsFolderIsGone(): void
    {
        $drive = new FakeDrive('folder-1-courant-01');
        $service = $this->serviceOn($drive);
        $id = $service->create(
            StorageLocationType::GoogleDrive,
            'Photos des galeries',
            $this->driveConfig($drive),
            (string) (new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org'))->toStorage()
        );
        $drive->files['scoutmagic-1']['name'] = 'Ancien ScoutMagic';
        unset($drive->files['folder-1-courant-01']);

        try {
            $service->attachDriveFolder($id, 'scoutmagic-1', true);
            $this->fail('The shared folder must not be attached, whatever its name.');
        } catch (StorageLocationException $e) {
            $this->assertStringContainsString('dossier commun', $e->getMessage());
        }
        $this->assertSame('folder-1-courant-01', $this->driveFolderOf($id));
    }

    /**
     * Moved deeper in the drive, the shared folder no longer sits at the
     * top, and once the location's own folder is gone nothing ties it to
     * it by id: its name is what is left, and it is enough to refuse it.
     */
    public function testASharedFolderMovedDeeperIsRefusedByItsName(): void
    {
        $drive = new FakeDrive('folder-1-courant-01');
        $service = $this->serviceOn($drive);
        $id = $service->create(
            StorageLocationType::GoogleDrive,
            'Photos des galeries',
            $this->driveConfig($drive),
            (string) (new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org'))->toStorage()
        );
        $drive->files['scoutmagic-1']['parent'] = $drive->addFolder('Archives', (string) $drive->files['scoutmagic-1']['parent']);
        unset($drive->files['folder-1-courant-01']);

        try {
            $service->attachDriveFolder($id, 'scoutmagic-1', true);
            $this->fail('The shared folder must not be attached, wherever it was moved.');
        } catch (StorageLocationException $e) {
            $this->assertStringContainsString('dossier commun', $e->getMessage());
        }
        $this->assertSame('folder-1-courant-01', $this->driveFolderOf($id));
    }

    /** Without an account, the folder cannot be looked at: nothing is attached. */
    public function testADisconnectedDriveLocationCannotBeAttachedToAFolder(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();
        $service->update($id, 'Photos des galeries', new GoogleDriveLocationConfig('client-1', 'folder-1', ''), null);
        $before = $drive->requests;

        $this->expectException(StorageLocationException::class);
        $this->expectExceptionMessage('Raccordez d\'abord un compte Google');

        try {
            $service->attachDriveFolder($id, 'dossier-actuel-0001', true);
        } finally {
            $this->assertSame($before, $drive->requests);
        }
    }

    /**
     * A new folder is the administrator's explicit act, and only once the
     * old one is gone: while it is there, what is in it would drop out
     * of sight without anything saying so.
     */
    public function testANewDriveFolderIsCreatedOnlyOnceTheOldOneIsGone(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();

        try {
            $service->createDriveFolder($id);
            $this->fail('A location whose folder is there must not be given another one.');
        } catch (StorageLocationException $e) {
            $this->assertStringContainsString('existe toujours', $e->getMessage());
        }
        $this->assertSame('folder-1', $this->driveFolderOf($id));

        $drive->files['folder-1']['trashed'] = true;
        $service->createDriveFolder($id);

        $created = $this->driveFolderOf($id);
        $this->assertNotSame('folder-1', $created);
        $this->assertSame('ScoutMagic/Photos des galeries', $drive->pathOf($created));
        $this->assertTrue($drive->isTrashed('folder-1'), 'the old folder is left as it was');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function folderReferences(): array
    {
        return [
            'the address Drive shows' => ['https://drive.google.com/drive/folders/1AbC_dEf-2345678', '1AbC_dEf-2345678'],
            'with a user prefix and a query' => ['https://drive.google.com/drive/u/0/folders/1AbCdEf2345678?usp=sharing', '1AbCdEf2345678'],
            'the old open?id= form' => ['https://drive.google.com/open?id=1AbCdEf2345678', '1AbCdEf2345678'],
            'the bare id, with spaces around' => ['  1AbCdEf2345678 ', '1AbCdEf2345678'],
            'a name' => ['Photos des galeries', ''],
            'too short to be an id' => ['abc', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('folderReferences')]
    public function testAFolderIsRecognisedByItsAddressOrItsId(string $reference, string $expected): void
    {
        $this->assertSame($expected, StorageLocationService::driveFolderIdFrom($reference));
    }

    private function localPathOf(int $id): string
    {
        $config = $this->repository->findById($id)?->config;

        return $config instanceof LocalLocationConfig ? $config->path : '';
    }

    private function driveFolderOf(int $id): string
    {
        $config = $this->repository->findById($id)?->config;

        return $config instanceof GoogleDriveLocationConfig ? $config->folderId : '';
    }

    public function testDeletingADriveLocationPutsItsFolderInTheTrash(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();

        $outcome = $service->delete($id);

        $this->assertTrue($outcome->isDone());
        $this->assertNull($this->repository->findById($id));
        $this->assertTrue($drive->files['folder-1']['trashed'], 'the folder was not put in the trash');
    }

    /** A Drive that cannot be reached does not keep a location alive. */
    public function testADeletionGoogleRefusesStillDeletesTheLocation(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();
        $drive->failPatchesWith = 503;

        $outcome = $service->delete($id);

        $this->assertTrue($outcome->isFailed());
        $this->assertNull($this->repository->findById($id));
        $this->assertFalse($drive->files['folder-1']['trashed']);
    }

    /** Still refused while a usage depends on it — and nothing is trashed. */
    public function testADriveLocationInUseIsNeitherDeletedNorTrashed(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();
        $this->consumers->register($this->consumerNamed('Sauvegardes hors site', [$id]));

        try {
            $service->delete($id);
            $this->fail('A location still in use must not be deletable.');
        } catch (StorageLocationException) {
            // As for every other kind.
        }

        $this->assertFalse($drive->files['folder-1']['trashed']);
    }

    /** A location never connected has no folder, and nothing is claimed. */
    public function testDeletingANeverConnectedDriveLocationTrashesNothing(): void
    {
        $drive = new FakeDrive();
        $service = $this->serviceOn($drive);
        $id = $service->create(StorageLocationType::GoogleDrive, 'Pas encore', new GoogleDriveLocationConfig('c'), null);

        $outcome = $service->delete($id);

        $this->assertFalse($outcome->isDone());
        $this->assertFalse($outcome->isFailed());
        $this->assertSame(0, $drive->requests);
    }

    /**
     * **A folder two locations share is never trashed nor renamed** (#474
     * review). Before #474 the connection found its folder by NAME, so two
     * Drive locations on one account were given the same folder id, and
     * nothing migrates them: trashing it for one would hide — then, after
     * thirty days, destroy — the other's live files.
     */
    public function testAFolderAnotherLocationStillUsesIsNeitherTrashedNorRenamed(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();
        $service->create(
            StorageLocationType::GoogleDrive,
            'Sauvegardes hors site',
            $this->driveConfig($drive),
            (string) (new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org'))->toStorage()
        );
        $before = $drive->requests;

        $renamed = $service->update($id, 'Galeries du groupe', $this->driveConfig($drive), null);
        $deleted = $service->delete($id);

        $this->assertTrue($renamed->isShared());
        $this->assertTrue($deleted->isShared());
        $this->assertSame('Sauvegardes hors site', $deleted->reason);
        $this->assertFalse($deleted->isDone());
        $this->assertFalse($deleted->isFailed());
        $this->assertNull($this->repository->findById($id));
        $this->assertFalse($drive->files['folder-1']['trashed'], 'a shared folder was put in the trash');
        $this->assertSame('Photos des galeries', $drive->files['folder-1']['name']);
        $this->assertSame($before, $drive->requests, 'Google was asked to touch a shared folder');
    }

    /**
     * **A disconnected location is deleted without reaching Google**
     * (#474 review). « Déraccorder » keeps the folder id so a
     * reconnection finds it again, but with no grant left, asking Google
     * would fail and report an ordinary state as a Drive fault. (Its
     * rename is refused instead since #832 — see
     * testARenameWhileDisconnectedIsRefused().)
     */
    public function testADisconnectedLocationLeavesItsFolderWithoutClaimingAFault(): void
    {
        [$drive, $service, $id] = $this->connectedDriveLocation();
        $disconnected = new GoogleDriveLocationConfig('', (string) $drive->folderId, '');
        $service->update($id, 'Photos des galeries', $disconnected, null);
        $before = $drive->requests;

        $deleted = $service->delete($id);

        $this->assertTrue($deleted->isDisconnected());
        $this->assertFalse($deleted->isFailed());
        $this->assertNull($this->repository->findById($id));
        $this->assertFalse($drive->files['folder-1']['trashed']);
        $this->assertSame($before, $drive->requests, 'Google was asked without a grant');
    }

    /** A local folder was named by the administrator and is never touched. */
    public function testDeletingALocalLocationLeavesItsFolderAlone(): void
    {
        $id = $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('garde'), null);
        mkdir($this->storagePath . '/garde');
        file_put_contents($this->storagePath . '/garde/photo.jpg', 'x');

        $outcome = $this->service->delete($id);

        $this->assertFalse($outcome->isDone());
        $this->assertFileExists($this->storagePath . '/garde/photo.jpg');
    }

    /**
     * @return array{0: FakeDrive, 1: StorageLocationService, 2: int}
     */
    private function connectedDriveLocation(): array
    {
        $drive = new FakeDrive();
        $service = $this->serviceOn($drive);
        $id = $service->create(
            StorageLocationType::GoogleDrive,
            'Photos des galeries',
            $this->driveConfig($drive),
            (string) (new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org'))->toStorage()
        );

        return [$drive, $service, $id];
    }

    private function driveConfig(FakeDrive $drive): GoogleDriveLocationConfig
    {
        return new GoogleDriveLocationConfig('client-1', (string) $drive->folderId, '2026-09-01T00:00:00+00:00');
    }

    private function serviceOn(FakeDrive $drive): StorageLocationService
    {
        return new StorageLocationService(
            $this->repository,
            new StorageBackendFactory($this->repository, $this->storagePath, $drive->client()),
            $this->consumers
        );
    }

    /**
     * The screens may lose a line rather than the page: a configuration
     * page that 500s because one module's table is missing is a page
     * nobody can open to repair that module.
     */
    public function testAConsumerThatThrowsDoesNotTakeTheDisplayedAnswerDown(): void
    {
        $id = $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        $this->consumers->register($this->brokenConsumer());

        $this->assertSame([], $this->service->usagesOf($id));
    }

    /**
     * **But a deletion may not.** « I could not determine whether anybody
     * is using this » and « nobody is using this » are opposite
     * conclusions, and the registry used to hand back the second when it
     * meant the first — so a transient database error inside one consumer
     * turned into a clean removal of a location something was still
     * standing on. The albums with a location written down were still
     * caught by the foreign key; the ones resolving to the default
     * lazily had nothing at all protecting them.
     */
    public function testADeletionIsRefusedWhenAConsumerCannotBeAskedAtAll(): void
    {
        $id = $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('a'), null);
        $this->consumers->register($this->brokenConsumer());

        try {
            $this->service->delete($id);
            $this->fail('A deletion must not proceed on an answer nobody could give.');
        } catch (StorageLocationException $e) {
            // French, and says the location was NOT removed — an
            // administrator's next move depends on knowing which.
            $this->assertStringContainsString("n'a pas été supprimé", $e->getMessage());
        }

        $this->assertNotNull($this->service->findById($id));
    }

    private function brokenConsumer(): StorageLocationConsumer
    {
        return new class implements StorageLocationConsumer {
            public function usageLabel(): string
            {
                return 'Module en panne';
            }

            public function locationIdsInUse(): array
            {
                throw new \RuntimeException('table missing');
            }

            /** This double holds nothing that a destination could strand. */
            public function objectionTo(
                StorageLocation $location,
                LocationConfig $proposedConfig,
                bool $wouldBeDefault
            ): ?string {
                return null;
            }
        };
    }

    public function testCheckNowRecordsASuccessForAReachableLocalFolder(): void
    {
        $id = $this->service->create(
            StorageLocationType::Local,
            'Disque',
            new LocalLocationConfig('photos'),
            null
        );

        $this->service->checkNow($this->repository->findById($id));

        $location = $this->repository->findById($id);
        $this->assertTrue($location?->lastCheckOk);
        $this->assertNull($location?->lastCheckError);
    }

    public function testCheckNowRecordsAFrenchReasonForAnUnreachableFolder(): void
    {
        $id = $this->service->create(
            StorageLocationType::Local,
            'Disque réseau',
            // A path under a file, so the directory cannot be created.
            new LocalLocationConfig('/proc/self/cmdline/photos'),
            null
        );

        $this->service->checkNow($this->repository->findById($id));

        $location = $this->repository->findById($id);
        $this->assertFalse($location?->lastCheckOk);
        $this->assertNotNull($location?->lastCheckError);
        // Rendered on a configuration page: French, and naming no class,
        // no driver and no stack.
        $this->assertStringNotContainsString('\\', (string) $location?->lastCheckError);
    }

    public function testCheckFreshSkipsALocationCheckedAMomentAgo(): void
    {
        $id = $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('photos'), null);
        $this->repository->recordCheckResult($id, false, 'Panne enregistrée il y a une seconde.');

        $refreshed = $this->service->checkFresh($this->repository->findById($id));

        // Not re-checked: the recorded answer is inside the TTL, and a
        // gallery page must never pay for a round trip to the storage.
        $this->assertFalse($refreshed->lastCheckOk);
        $this->assertSame('Panne enregistrée il y a une seconde.', $refreshed->lastCheckError);
    }

    public function testCheckFreshReChecksAStaleResult(): void
    {
        $id = $this->service->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('photos'), null);
        $stale = date('Y-m-d H:i:s', time() - StorageLocationService::HEALTH_CHECK_TTL_SECONDS - 60);
        $stmt = $this->pdo->prepare(
            'UPDATE storage_locations SET last_checked_at = ?, last_check_ok = 0, last_check_error = ? WHERE id = ?'
        );
        $stmt->execute([$stale, 'Vieille panne.', $id]);

        $refreshed = $this->service->checkFresh($this->repository->findById($id));

        $this->assertTrue($refreshed->lastCheckOk);
        $this->assertNull($refreshed->lastCheckError);
    }

    public function testCheckNowOnALocationWhoseBackendCannotBeBuiltRecordsAnErrorRatherThanThrowing(): void
    {
        // An S3 location pointing nowhere: building the client succeeds,
        // the connection test does not. What matters is that the failure
        // lands in the row instead of 500ing the page an administrator
        // opened to fix it.
        $id = $this->service->create(
            StorageLocationType::ObjectStorage,
            'Bucket cassé',
            new ObjectStorageLocationConfig('https://127.0.0.1:1/', 'eu', 'b', 'ak'),
            'sk'
        );

        $this->service->checkNow($this->repository->findById($id));

        $this->assertFalse($this->repository->findById($id)?->lastCheckOk);
    }

    private function serviceProbing(ProbeRecorder $probes): StorageLocationService
    {
        $factory = new class ($this->repository, $this->storagePath, $probes) extends StorageBackendFactory {
            public function __construct(
                StorageLocationRepository $repository,
                string $storagePath,
                private ProbeRecorder $probes
            ) {
                parent::__construct($repository, $storagePath);
            }

            public function probe(StorageLocation $proposed, ?string $secret): StorageBackendInterface
            {
                $this->probes->targets[] = $proposed->config->target();

                return $this->probes->backend;
            }
        };

        return new StorageLocationService($this->repository, $factory, $this->consumers);
    }

    /**
     * @param list<int> $ids
     */
    private function consumerNamed(string $label, array $ids): StorageLocationConsumer
    {
        return new class ($label, $ids) implements StorageLocationConsumer {
            /** @param list<int> $ids */
            public function __construct(private string $label, private array $ids)
            {
            }

            public function usageLabel(): string
            {
                return $this->label;
            }

            public function locationIdsInUse(): array
            {
                return $this->ids;
            }

            /** This double holds nothing that a destination could strand. */
            public function objectionTo(
                StorageLocation $location,
                LocationConfig $proposedConfig,
                bool $wouldBeDefault
            ): ?string {
                return null;
            }
        };
    }
}
