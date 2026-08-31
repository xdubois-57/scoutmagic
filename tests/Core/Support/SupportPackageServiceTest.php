<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\Security\SecretManager;
use Core\Support\Collector\ConfigurationParametersCollector;
use Core\Support\Collector\DatabaseStructureCollector;
use Core\Support\Collector\EventJournalCollector;
use Core\Support\Collector\ScheduledTasksCollector;
use Core\Support\Collector\StatisticsCollector;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\Support\SupportPackageService;
use Core\Support\SupportPackageState;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

final class ThrowingCollector implements SupportCollectorInterface
{
    public function __construct(private string $message = 'boom')
    {
    }

    public function name(): string
    {
        return 'throwing';
    }

    public function collect(SupportCollectorContext $context): void
    {
        throw new \RuntimeException($this->message);
    }
}

final class UnavailableCollector implements SupportCollectorInterface
{
    public function name(): string
    {
        return 'unavailable_one';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $context->markUnavailable('binary_not_found');
    }
}

final class NotingCollector implements SupportCollectorInterface
{
    public function name(): string
    {
        return 'noting';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $context->addNote('truncated');
        $context->addFileFromContent('noted.txt', 'content');
    }
}

final class NetworkCollector implements SupportCollectorInterface
{
    public function name(): string
    {
        return 'network';
    }

    public function collect(SupportCollectorContext $context): void
    {
        // Deliberately reaches for the network; the surrounding test asserts
        // no such call ever happens during a generation.
        $context->addFileFromContent('network.txt', (string) @file_get_contents('https://example.invalid/'));
    }
}

class SupportPackageServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SettingRepository $settingRepository;
    private string $projectRoot;
    private string $storagePath;
    private EncryptionService $encryption;
    private FileRepository $fileRepository;
    private EncryptedFileStorageService $encryptedStorage;
    private Connection $connection;
    private SecretManager $secretManager;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-package-' . bin2hex(random_bytes(6));
        $this->storagePath = $this->projectRoot . '/storage';
        mkdir($this->storagePath . '/keys', 0700, true);
        mkdir($this->storagePath . '/config', 0700, true);
        mkdir($this->storagePath . '/temp', 0700, true);
        file_put_contents($this->projectRoot . '/VERSION', "1.0.33\n");

        $this->secretManager = new SecretManager(
            $this->storagePath . '/keys/master.key',
            $this->storagePath . '/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['db_password' => 'sup3r-s3cret-db-password']);

        $this->settings->register('support_email', 'support@scoutmagic.be', 'email', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportPackageState::FILE_ID, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportPackageState::GENERATED_AT, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register('statistics_enabled', '1', 'boolean', 'L', 'D');
        $this->settings->register('base_url', 'https://unite-exemple.be', 'url', 'L', 'D');
        $this->settings->register(InstallationIdentityService::INSTALLATION_ID_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->clearCache();

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->fileRepository = new FileRepository($this->pdo);
        $this->encryptedStorage = new EncryptedFileStorageService($this->fileRepository, $this->encryption, $this->storagePath);

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        self::removeTree($this->projectRoot);
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

    /**
     * @param array<int, SupportCollectorInterface> $collectors
     * @param array<int, string> $secretsToRedact
     */
    private function service(array $collectors = [], array $secretsToRedact = []): SupportPackageService
    {
        return new SupportPackageService(
            $this->connection,
            $this->settings,
            $this->fileRepository,
            $this->encryptedStorage,
            $this->projectRoot,
            $this->storagePath,
            $collectors,
            $secretsToRedact
        );
    }

    private function statisticsCollector(): StatisticsCollector
    {
        return new StatisticsCollector(new StatisticsPayloadBuilder(
            $this->settings,
            $this->pdo,
            new InstallationIdentityService($this->settings, $this->secretManager),
            $this->projectRoot
        ));
    }

    /**
     * @return array<string, string> archive entry name => content
     */
    private function readArchive(int $fileId): array
    {
        $bytes = $this->encryptedStorage->retrieve($fileId);
        $tempPath = sys_get_temp_dir() . '/scoutmagic-read-' . bin2hex(random_bytes(6)) . '.zip';
        file_put_contents($tempPath, $bytes);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tempPath) === true);

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($tempPath);

        return $entries;
    }

    public function testAPackageAlwaysContainsTheThreeBaseFiles(): void
    {
        $fileId = $this->service([$this->statisticsCollector()])->generate(null);

        $entries = $this->readArchive($fileId);
        $this->assertArrayHasKey('collection-status.json', $entries);
        $this->assertArrayHasKey('README.txt', $entries);
        $this->assertArrayHasKey('statistics.json', $entries);
    }

    public function testAThrowingCollectorStillProducesTheArchiveAndIsReportedAsFailed(): void
    {
        $fileId = $this->service([new ThrowingCollector(), $this->statisticsCollector()])->generate(null);

        $entries = $this->readArchive($fileId);
        $this->assertArrayHasKey('statistics.json', $entries);

        $status = json_decode($entries['collection-status.json'], true);
        $this->assertIsArray($status);
        $collectors = array_column($status['collectors'], null, 'name');
        $this->assertSame('failed', $collectors['throwing']['status']);
        $this->assertSame('boom', $collectors['throwing']['reason']);
        $this->assertSame('success', $collectors['statistics']['status']);
    }

    public function testAnUnavailableCollectorIsReportedAsSuchWithItsReason(): void
    {
        $fileId = $this->service([new UnavailableCollector()])->generate(null);

        $status = json_decode($this->readArchive($fileId)['collection-status.json'], true);
        $collectors = array_column($status['collectors'], null, 'name');
        $this->assertSame('unavailable', $collectors['unavailable_one']['status']);
        $this->assertSame('binary_not_found', $collectors['unavailable_one']['reason']);
    }

    public function testCollectorNotesAndDurationsAreReported(): void
    {
        $fileId = $this->service([new NotingCollector()])->generate(null);

        $status = json_decode($this->readArchive($fileId)['collection-status.json'], true);
        $collectors = array_column($status['collectors'], null, 'name');
        $this->assertSame(['truncated'], $collectors['noting']['notes']);
        $this->assertArrayHasKey('duration_ms', $collectors['noting']);
    }

    public function testCollectorStateDoesNotLeakBetweenCollectors(): void
    {
        $fileId = $this->service([new UnavailableCollector(), new NotingCollector()])->generate(null);

        $status = json_decode($this->readArchive($fileId)['collection-status.json'], true);
        $collectors = array_column($status['collectors'], null, 'name');
        $this->assertSame('success', $collectors['noting']['status']);
        $this->assertSame(['truncated'], $collectors['noting']['notes']);
        $this->assertArrayNotHasKey('notes', $collectors['unavailable_one']);
    }

    public function testAFailureReasonNeverCarriesAKnownSecret(): void
    {
        $secret = 'sup3r-s3cret-db-password';
        $fileId = $this->service(
            [new ThrowingCollector('SQLSTATE: access denied using password ' . $secret)],
            [$secret]
        )->generate(null);

        $statusJson = $this->readArchive($fileId)['collection-status.json'];
        $this->assertStringNotContainsString($secret, $statusJson);
        $this->assertStringContainsString('[REDACTED]', $statusJson);
    }

    /**
     * `collection-status.json` is JSON, and a driver error is very often
     * not valid UTF-8. `json_encode()` answers `false` on such a subject —
     * not a throw, not a partial document — and renderStatus() casts that
     * to string, so one collector reporting a latin-1 message used to empty
     * the entire status file and take every other collector's outcome with
     * it. The archive is still always produced; the file inside it has to
     * be readable too.
     */
    public function testANonUtf8FailureReasonDoesNotEmptyTheWholeStatusFile(): void
    {
        $fileId = $this->service([
            new ThrowingCollector("SQLSTATE[HY000] acc\xE8s refus\xE9 par le serveur"),
            new NotingCollector(),
        ])->generate(null);

        $statusJson = $this->readArchive($fileId)['collection-status.json'];
        $this->assertNotSame('', $statusJson);

        $status = json_decode($statusJson, true);
        $this->assertIsArray($status);

        $collectors = array_column($status['collectors'], null, 'name');
        $this->assertSame('failed', $collectors['throwing']['status']);
        $this->assertStringContainsString('SQLSTATE', (string) $collectors['throwing']['reason']);
        $this->assertSame('success', $collectors['noting']['status'], 'one bad reason must not erase the others');
    }

    public function testStatisticsJsonIsProducedEvenWhenReportingIsDisabled(): void
    {
        $this->settingRepository->updateValue(null, 'statistics_enabled', '0');
        $this->settings->clearCache();

        $fileId = $this->service([$this->statisticsCollector()])->generate(null);

        $entries = $this->readArchive($fileId);
        $payload = json_decode($entries['statistics.json'], true);
        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['statistics_schema_version']);
        $this->assertSame('https://unite-exemple.be', $payload['instance_url']);
    }

    public function testTheArchiveNeverContainsTheAuthenticationSecret(): void
    {
        $identity = new InstallationIdentityService($this->settings, $this->secretManager);
        $secret = $identity->getSecret();
        $this->assertNotNull($secret);

        $fileId = $this->service([$this->statisticsCollector()], [$secret])->generate(null);

        foreach ($this->readArchive($fileId) as $content) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function testTheReadmeExplainsTheArchiveAndCarriesTheFullWarning(): void
    {
        $fileId = $this->service()->generate(null);
        $readme = $this->readArchive($fileId)['README.txt'];

        $this->assertStringContainsString('générée localement', $readme);
        // Roadmap IT-26 turned the absolute promise into a conditional
        // one, and the README is the copy an administrator reads with the
        // archive already in their hands — so it has to describe the one
        // way it can leave, not merely forbid the automatic ones.
        $this->assertStringContainsString('Rien n\'est transmis automatiquement', $readme);
        $this->assertStringContainsString('Aucun envoi automatique', $readme);
        $this->assertStringContainsString('Configuration > Support', $readme);
        $this->assertStringContainsString('cocher que vous acceptez de la transmettre', $readme);
        $this->assertStringNotContainsString('sous aucune forme.', $readme, 'the absolute claim is retired');
        $this->assertStringContainsString('cela ne peut pas être garanti', $readme);
        $this->assertStringContainsString('support@scoutmagic.be', $readme);
        $this->assertStringContainsString('informations PHP', $readme);
        $this->assertStringContainsString('journaux d\'accès', $readme);
    }

    public function testTheStoredFileIsEncryptedAtRestAndSuperadminOnly(): void
    {
        $fileId = $this->service([$this->statisticsCollector()])->generate(null);

        $record = $this->fileRepository->findById($fileId);
        $this->assertNotNull($record);
        $this->assertTrue($record->encrypted);
        $this->assertSame('superadmin', $record->roleMin);
        $this->assertStringStartsWith(SupportPackageService::STORAGE_SUBDIRECTORY . '/', $record->relativePath);

        $rawOnDisk = (string) file_get_contents($this->storagePath . '/' . $record->relativePath);
        $this->assertStringNotContainsString('collection-status.json', $rawOnDisk);
    }

    public function testGeneratingAgainDeletesThePreviousPackageEntirely(): void
    {
        $service = $this->service([$this->statisticsCollector()]);

        $first = $service->generate(null);
        $firstRecord = $this->fileRepository->findById($first);
        $this->assertNotNull($firstRecord);
        $firstPath = $this->storagePath . '/' . $firstRecord->relativePath;

        $second = $service->generate(null);

        $this->assertNotSame($first, $second);
        $this->assertNull($this->fileRepository->findById($first));
        $this->assertFileDoesNotExist($firstPath);
        $this->settings->clearCache();
        $this->assertSame((string) $second, $this->settings->get(SupportPackageState::FILE_ID));
    }

    public function testNoTemporaryFileIsLeftBehind(): void
    {
        $this->service([$this->statisticsCollector()])->generate(null);

        $leftovers = glob($this->storagePath . '/temp/support-*') ?: [];
        $this->assertSame([], $leftovers);
    }

    public function testPurgeRemovesAPackagePastRetentionAndKeepsAFreshOne(): void
    {
        $service = $this->service([$this->statisticsCollector()]);
        $fileId = $service->generate(null);

        $this->assertFalse($service->purgeExpired());
        $this->assertNotNull($this->fileRepository->findById($fileId));

        $this->settingRepository->updateValue(
            null,
            SupportPackageState::GENERATED_AT,
            (new \DateTimeImmutable('-8 days'))->format(\DateTimeInterface::ATOM)
        );
        $this->settings->clearCache();

        $this->assertTrue($service->purgeExpired());
        $this->assertNull($this->fileRepository->findById($fileId));
        $this->settings->clearCache();
        $this->assertSame('', $this->settings->get(SupportPackageState::FILE_ID));
    }

    public function testPurgeIsANoOpWithoutAPackage(): void
    {
        $this->assertFalse($this->service()->purgeExpired());
    }

    public function testGenerationMakesNoOutboundNetworkCall(): void
    {
        // A generation that reached the network would be a silent
        // transmission of exactly what the whole feature promises never to
        // transmit automatically. Asserted by refusing every stream wrapper
        // that could carry one.
        $wrappers = ['http', 'https', 'ftp'];
        foreach ($wrappers as $wrapper) {
            if (in_array($wrapper, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($wrapper);
            }
        }

        try {
            $fileId = $this->service([new NetworkCollector(), $this->statisticsCollector()])->generate(null);

            $entries = $this->readArchive($fileId);
            $this->assertArrayHasKey('statistics.json', $entries);
            $this->assertSame('', $entries['network.txt']);
        } finally {
            foreach ($wrappers as $wrapper) {
                if (!in_array($wrapper, stream_get_wrappers(), true)) {
                    stream_wrapper_restore($wrapper);
                }
            }
        }
    }

    public function testArchivePathsCannotEscapeTheArchive(): void
    {
        $collector = new class implements SupportCollectorInterface {
            public function name(): string
            {
                return 'escaping';
            }

            public function collect(SupportCollectorContext $context): void
            {
                $context->addFileFromContent('../../etc/passwd', 'nope');
                $context->addFileFromContent('/absolute/path.txt', 'nope');
            }
        };

        $entries = $this->readArchive($this->service([$collector])->generate(null));

        foreach (array_keys($entries) as $name) {
            $this->assertStringNotContainsString('..', $name);
            $this->assertStringStartsNotWith('/', $name);
        }
        $this->assertArrayHasKey('etc/passwd', $entries);
        $this->assertArrayHasKey('absolute/path.txt', $entries);
    }

    /**
     * Every application collector, each broken in the bluntest possible way
     * (its table gone), must still leave a complete archive behind — the
     * package's whole contract.
     */
    public function testEachApplicationCollectorFailingIndividuallyStillProducesTheArchive(): void
    {
        foreach (['settings', 'event_log', 'scheduled_actions'] as $table) {
            $pdo = DatabaseTestHelper::createTestDatabase();
            $pdo->exec('DROP TABLE ' . $table);

            $connection = $this->createMock(Connection::class);
            $connection->method('getPdo')->willReturn($pdo);
            $connection->method('dumpCredentials')->willReturn([
                'host' => '', 'port' => 0, 'dbName' => '', 'user' => '', 'password' => '',
            ]);

            $settings = new SettingService(new SettingRepository($this->pdo));
            $service = new SupportPackageService(
                $connection,
                $settings,
                $this->fileRepository,
                $this->encryptedStorage,
                $this->projectRoot,
                $this->storagePath,
                [
                    $this->statisticsCollector(),
                    new DatabaseStructureCollector(),
                    new ConfigurationParametersCollector(),
                    new EventJournalCollector(),
                    new ScheduledTasksCollector(),
                ]
            );

            $entries = $this->readArchive($service->generate(null));

            $this->assertArrayHasKey('collection-status.json', $entries, "Broken table: {$table}");
            $this->assertArrayHasKey('README.txt', $entries, "Broken table: {$table}");
            $this->assertArrayHasKey('statistics.json', $entries, "Broken table: {$table}");

            $status = json_decode($entries['collection-status.json'], true);
            $this->assertIsArray($status);
            $this->assertCount(5, $status['collectors'], "Broken table: {$table}");
        }
    }

    /**
     * A stamp that cannot be parsed cannot be proven to be within
     * retention, so the archive goes. Erring the other way would leave the
     * most sensitive artefact this codebase produces on disk forever
     * because of one bad string.
     */
    public function testAnUnreadableGenerationStampDropsTheArchive(): void
    {
        $fileId = $this->service([$this->statisticsCollector()])->generate(null);
        $this->settingRepository->updateValue(null, SupportPackageState::GENERATED_AT, 'pas une date');
        $this->settings->clearCache();

        $this->assertTrue($this->service()->purgeExpired());
        $this->assertNull((new FileRepository($this->pdo))->findById($fileId));
    }

    public function testPurgingWhenThereIsNoPackageAtAllIsANoOp(): void
    {
        $this->assertFalse($this->service()->purgeExpired());
    }

    /**
     * A FileRecord whose bytes are already gone must not stop a new package
     * from being produced — the record is dropped and generation carries on.
     */
    public function testAHalfDeletedPackageDoesNotBlockTheNextGeneration(): void
    {
        $service = $this->service([$this->statisticsCollector()]);
        $first = $service->generate(null);

        $record = (new FileRepository($this->pdo))->findById($first);
        $this->assertNotNull($record);
        @unlink($this->storagePath . '/' . $record->storagePath);

        $second = $service->generate(null);

        $this->assertNotSame($first, $second);
        $this->assertNull((new FileRepository($this->pdo))->findById($first));
    }

    public function testTheCurrentPackageAccessorsReportNothingBeforeAnyGeneration(): void
    {
        $service = $this->service();

        $this->assertNull($service->currentPackageFileId());
        $this->assertNull($service->currentPackageGeneratedAt());
    }

    public function testTheCurrentPackageAccessorsReportTheGeneratedArchive(): void
    {
        $service = $this->service([$this->statisticsCollector()]);
        $fileId = $service->generate(null);

        $this->settings->clearCache();
        $this->assertSame($fileId, $service->currentPackageFileId());
        $this->assertNotNull($service->currentPackageGeneratedAt());
    }
}
