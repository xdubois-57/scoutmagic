<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location;

use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationConsumer;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
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
        };
    }
}
