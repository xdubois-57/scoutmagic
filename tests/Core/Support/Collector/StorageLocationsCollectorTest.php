<?php

declare(strict_types=1);

namespace Tests\Core\Support\Collector;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\LocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\Config\WebDavLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationConsumer;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use Core\Storage\Volume\VolumeInventory;
use Core\Support\Collector\StorageLocationsCollector;
use Core\Support\SupportCollectorContext;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `storage-locations.txt` — and above all what it may never say
 * (ARCHITECTURE.md §8.48, §8.107, §8.108).
 *
 * The package leaves the installation and goes to a third party, so the
 * assertions that matter most here are the negative ones, and they are
 * written against a fixture whose paths and endpoint are distinctive
 * strings: an edit that starts printing `/home/marie.dupont/...` or a
 * bucket's endpoint host fails this file rather than shipping quietly.
 *
 * The collector's own docblock makes three promises — no credential, no
 * absolute path, no endpoint host — and until this file existed nothing
 * checked any of them. One of the three was in fact false: `redact()`
 * replaces the credentials a run knows about and normalises whitespace,
 * and a person's name in a directory is neither.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class StorageLocationsCollectorTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private StorageLocationRepository $repository;
    private StorageLocationConsumerRegistry $consumers;
    private Connection $connection;
    private string $projectRoot;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->repository = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->consumers = new StorageLocationConsumerRegistry();

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-storage-collector-' . bin2hex(random_bytes(6));
        $this->storagePath = $this->projectRoot . '/storage';
        mkdir($this->storagePath . '/temp', 0777, true);

        // A stub rather than a mock: it answers a question, it never
        // verifies that one was asked.
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $this->connection = $connection;

        $this->repository->create(
            StorageLocationType::Local,
            'Disque du serveur',
            new LocalLocationConfig('gallery'),
            null
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->projectRoot);
    }

    public function testItNamesEveryDeclaredLocationAndWhatItCanDo(): void
    {
        $report = $this->collect();

        $this->assertStringContainsString('Disque du serveur', $report);
        $this->assertStringContainsString('storage/gallery', $report, 'A path under the root stays readable.');
        $this->assertStringContainsString('Photos', $report, 'The consequences, per D3.');
        $this->assertStringContainsString('jamais testé', $report);
    }

    /**
     * The first of the three promises, and the easiest to keep: the secret
     * is absent from `StorageLocation` by construction.
     */
    public function testItNeverCarriesACredential(): void
    {
        $this->declareBucket(secret: 'CLE-SECRETE-A-NE-JAMAIS-ECRIRE');

        $report = $this->collect();

        $this->assertStringNotContainsString('CLE-SECRETE-A-NE-JAMAIS-ECRIRE', $report);
        $this->assertStringNotContainsString('AKIA-IDENTIFIANT', $report);
        $this->assertStringContainsString('Identifiants   : configurés', $report, 'Whether one is set is diagnostic.');
    }

    /**
     * The second promise, and the one that was false. A storage path is a
     * server directory and usually says nothing about anybody — but a site
     * is free to have `/home/marie.dupont/photos`, and that word is the
     * name of a person on a file about to be sent to a third party.
     */
    public function testItNeverCarriesTheAbsolutePathOfADeclaredLocation(): void
    {
        $outside = sys_get_temp_dir() . '/scoutmagic-marie.dupont-' . bin2hex(random_bytes(4)) . '/photos';
        mkdir($outside, 0777, true);
        $this->repository->create(
            StorageLocationType::Local,
            'Disque réseau',
            new LocalLocationConfig($outside),
            null
        );

        $report = $this->collect();

        try {
            $this->assertStringNotContainsString(dirname($outside), $report, 'The tree above it names the account.');
            $this->assertStringContainsString('[hors racine #', $report, 'Replaced by a fingerprint of that tree.');
            $this->assertStringContainsString('/photos', $report, 'The folder itself still reads.');
        } finally {
            self::removeTree(dirname($outside));
        }
    }

    /**
     * **A Windows path is absolute too, and the export forgot it.**
     * `normalizeLocalPath()` accepts and persists `C:\Users\…`
     * unchanged, and a POSIX-only « starts with / » test called it
     * relative — so the masking returned it verbatim and the OS account
     * name left the installation inside an archive bound for a third
     * party. The drive letter and the UNC spelling both count, and
     * `LocalLocationConfig::isAbsolutePath()` is now the single place that
     * says so.
     *
     * @param string $declared the path exactly as an administrator typed it
     * @param string $secret   the fragment that must never reach the archive
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('windowsShapedPaths')]
    public function testAWindowsOrUncPathIsMaskedLikeAnyOtherAbsolutePath(
        string $declared,
        string $secret
    ): void {
        $this->repository->create(
            StorageLocationType::Local,
            'Disque Windows',
            new LocalLocationConfig($declared),
            null
        );

        $report = $this->collect();

        $this->assertStringNotContainsString($secret, $report, 'The account name must not reach the archive.');
        $this->assertStringNotContainsString($declared, $report, 'Nor the path it sits in.');
        $this->assertStringContainsString('[hors racine #', $report, 'It is masked like any absolute path.');
    }

    /** @return array<string, array{string, string}> */
    public static function windowsShapedPaths(): array
    {
        return [
            'drive letter, backslashes' => ['C:\\Users\\marie.dupont\\photos', 'marie.dupont'],
            'drive letter, slashes' => ['D:/Users/marie.dupont/photos', 'marie.dupont'],
            'UNC share' => ['\\\\nas-de-marie.dupont\\photos', 'marie.dupont'],
        ];
    }

    /**
     * Two locations under one external tree must still read as two
     * locations under one external tree — that is the whole diagnostic
     * value the absolute path carried, and a fingerprint keeps it.
     */
    public function testTwoLocationsSharingATreeShareTheirFingerprint(): void
    {
        $tree = sys_get_temp_dir() . '/scoutmagic-nas-' . bin2hex(random_bytes(4));
        mkdir($tree . '/photos', 0777, true);
        mkdir($tree . '/videos', 0777, true);
        $this->repository->create(StorageLocationType::Local, 'Photos', new LocalLocationConfig($tree . '/photos'), null);
        $this->repository->create(StorageLocationType::Local, 'Vidéos', new LocalLocationConfig($tree . '/videos'), null);

        $report = $this->collect();

        try {
            preg_match_all('/\[hors racine #([0-9a-f]{6})\]/', $report, $matches);
            $this->assertNotEmpty($matches[1], 'Both locations must be fingerprinted.');
            $this->assertCount(1, array_unique($matches[1]), 'One tree, one fingerprint.');
        } finally {
            self::removeTree($tree);
        }
    }

    /**
     * The third promise. The endpoint is a provider's public address and
     * would normally be safe — but with the bucket name beside it, it is
     * half of a target, and the provider's NAME answers every diagnostic
     * question it would.
     */
    public function testItNamesTheProviderRatherThanTheEndpointHost(): void
    {
        $this->declareBucket(secret: null);

        $report = $this->collect();

        $this->assertStringNotContainsString('fsn1.endpoint-a-ne-pas-ecrire.test', $report);
        $this->assertStringContainsString('hetzner', $report, 'The provider answers the same question.');
        $this->assertStringContainsString('photos-de-lunite', $report, 'The bucket is not the target on its own.');
    }

    /**
     * **A share's address is a host plus the account it belongs to.** The
     * path under a Nextcloud is literally `…/dav/files/{login}/…`, and a
     * login is very often somebody's name — the same promise the absolute
     * path above keeps, one type further along. The host answers the
     * diagnostic question on its own: which cloud the unit is on.
     */
    public function testItNamesTheShareHostAndNotTheAccountUnderIt(): void
    {
        $this->repository->create(
            StorageLocationType::WebDav,
            'Nextcloud de l\'unité',
            new WebDavLocationConfig(
                'https://cloud.exemple.test/remote.php/dav/files/marie.dupont/scoutmagic',
                'marie.dupont'
            ),
            'MOT-DE-PASSE-A-NE-JAMAIS-ECRIRE'
        );

        $report = $this->collect();

        $this->assertStringContainsString('cloud.exemple.test', $report);
        $this->assertStringNotContainsString('marie.dupont', $report);
        $this->assertStringNotContainsString('MOT-DE-PASSE-A-NE-JAMAIS-ECRIRE', $report);
    }

    /**
     * **« rien » and « indéterminé » are opposite answers.** This collector
     * runs in a scheduled task, where no module has registered a consumer:
     * printing « rien » there says nobody uses a location a gallery may be
     * standing on, which is the conclusion somebody deletes on.
     */
    public function testItSaysUsageIsUndeterminedWhenNoConsumerWasEverRegistered(): void
    {
        $report = $this->collect();

        $this->assertStringContainsString('Sert à         : indéterminé', $report);
        $this->assertStringNotContainsString('Sert à         : rien', $report);
    }

    public function testItSaysRienOnlyOnceAConsumerHasActuallyBeenAsked(): void
    {
        $this->consumers->register($this->consumerHolding('Galeries photo', []));

        $report = $this->collect();

        $this->assertStringContainsString('Sert à         : rien', $report);
    }

    public function testItNamesTheUsageWhenAConsumerStandsOnTheLocation(): void
    {
        $id = $this->repository->findAll()[0]->id;
        $this->consumers->register($this->consumerHolding('Galeries photo', [$id]));

        $report = $this->collect();

        $this->assertStringContainsString('Sert à         : Galeries photo', $report);
    }

    /**
     * The archive's contract is that it is always produced (§8.48), so a
     * module in trouble must not empty the file.
     */
    public function testAConsumerThatCannotAnswerIsNamedRatherThanFatal(): void
    {
        $this->consumers->register($this->failingConsumer());

        $report = $this->collect();

        $this->assertStringContainsString('indéterminé (un module n\'a pas pu répondre)', $report);
        $this->assertStringContainsString('Disque du serveur', $report, 'The rest of the file survives.');
    }

    public function testAnInstallationWithNoLocationAtAllStillProducesTheFile(): void
    {
        $this->pdo->exec('DELETE FROM storage_locations');

        $report = $this->collect();

        $this->assertStringContainsString('Aucun emplacement déclaré.', $report);
    }

    private function declareBucket(?string $secret): int
    {
        return $this->repository->create(
            StorageLocationType::ObjectStorage,
            'Bucket de l\'unité',
            new ObjectStorageLocationConfig(
                endpoint: 'https://fsn1.endpoint-a-ne-pas-ecrire.test',
                region: 'fsn1',
                bucket: 'photos-de-lunite',
                accessKey: 'AKIA-IDENTIFIANT',
                provider: 'hetzner'
            ),
            $secret
        );
    }

    /** @param list<int> $ids */
    private function consumerHolding(string $label, array $ids): StorageLocationConsumer
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

            public function objectionTo(
                StorageLocation $location,
                LocationConfig $proposedConfig,
                bool $wouldBeDefault
            ): ?string {
                return null;
            }
        };
    }

    private function failingConsumer(): StorageLocationConsumer
    {
        return new class implements StorageLocationConsumer {
            public function usageLabel(): string
            {
                return 'Galeries photo';
            }

            public function locationIdsInUse(): array
            {
                throw new \RuntimeException('la table est absente');
            }

            public function objectionTo(
                StorageLocation $location,
                LocationConfig $proposedConfig,
                bool $wouldBeDefault
            ): ?string {
                return null;
            }
        };
    }

    private function collect(): string
    {
        $backends = new StorageBackendFactory($this->repository, $this->storagePath);
        $collector = new StorageLocationsCollector(
            $this->repository,
            $this->consumers,
            new VolumeInventory(
                $this->storagePath,
                $this->settings,
                new StorageLocationService($this->repository, $backends, new StorageLocationConsumerRegistry()),
                $backends
            )
        );

        $archivePath = $this->storagePath . '/temp/support-' . bin2hex(random_bytes(6)) . '.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        $collector->collect(new SupportCollectorContext(
            $archive,
            $this->connection,
            $this->settings,
            $this->projectRoot,
            $this->storagePath
        ));
        $archive->close();

        $read = new \ZipArchive();
        $this->assertTrue($read->open($archivePath) === true);
        $content = $read->getFromName('storage-locations.txt');
        $read->close();

        $this->assertIsString($content, 'The collector must always produce its file.');

        return $content;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
