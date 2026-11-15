<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\Security\SecretManager;
use Core\Support\Collector\BackupsCollector;
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

#[\PHPUnit\Framework\Attributes\Group('database')]
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

        $connection = $this->createStub(Connection::class);
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
        $this->assertSame(
            \Core\Statistics\StatisticsPayloadBuilder::STATISTICS_SCHEMA_VERSION,
            $payload['statistics_schema_version']
        );
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

        // The span it announces is the one exported (issue #846), and the
        // backup inventory is described for what it is.
        $this->assertStringContainsString('ce que contiennent les 7 jours du journal', $readme);
        $this->assertStringNotContainsString('48 h du journal', $readme);
        $this->assertStringContainsString('backups.xlsx : les sauvegardes enregistrées sur ce serveur', $readme);

        $this->assertStringContainsString('générée localement', $readme);
        // Roadmap IT-26 turned the absolute promise into a conditional
        // one, and the README is the copy an administrator reads with the
        // archive already in their hands — so it has to describe the one
        // way it can leave, not merely forbid the automatic ones.
        $this->assertStringContainsString('Rien n\'est transmis automatiquement', $readme);
        $this->assertStringContainsString('Aucun envoi automatique', $readme);
        $this->assertStringContainsString('Configuration > Diagnostic', $readme);
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
        foreach (['settings', 'event_log', 'scheduled_actions', 'backups'] as $table) {
            $pdo = DatabaseTestHelper::createTestDatabase();
            $pdo->exec('DROP TABLE ' . $table);

            $connection = $this->createStub(Connection::class);
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
                    new BackupsCollector(),
                ]
            );

            $entries = $this->readArchive($service->generate(null));

            $this->assertArrayHasKey('collection-status.json', $entries, "Broken table: {$table}");
            $this->assertArrayHasKey('README.txt', $entries, "Broken table: {$table}");
            $this->assertArrayHasKey('statistics.json', $entries, "Broken table: {$table}");

            $status = json_decode($entries['collection-status.json'], true);
            $this->assertIsArray($status);
            $this->assertCount(6, $status['collectors'], "Broken table: {$table}");
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
        $bytes = $this->storagePath . '/' . $record->relativePath;
        $this->assertFileExists($bytes);
        unlink($bytes);
        $this->assertFileDoesNotExist($bytes);

        $second = $service->generate(null);

        $this->assertNotSame($first, $second);
        $this->assertNull((new FileRepository($this->pdo))->findById($first));
    }

    /**
     * The bookkeeping row is gone — an installation whose settings never
     * received it — and the package is still produced and stored: the
     * archive is what the administrator asked for, the setting only
     * remembers where it is. The write that can still land does.
     */
    public function testAMissingBookkeepingSettingDoesNotCostThePackage(): void
    {
        $this->pdo->exec("DELETE FROM settings WHERE setting_key = '" . SupportPackageState::FILE_ID . "'");
        $this->settings->clearCache();

        $fileId = $this->service([$this->statisticsCollector()])->generate(null);

        $this->assertNotNull($this->fileRepository->findById($fileId));
        $this->assertArrayHasKey('collection-status.json', $this->readArchive($fileId));
        $this->settings->clearCache();
        $this->assertNull($this->settings->get(SupportPackageState::FILE_ID), 'Nothing recreated the row.');
        $this->assertNotSame('', (string) $this->settings->get(SupportPackageState::GENERATED_AT));
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

    /**
     * Reading the previous package's FileRecord failing is a database
     * failure, and the archive's randomised path is written down nowhere
     * else. Dropping the record anyway, as the fallback once did, left the
     * encrypted server logs on disk with nothing able to find them. The
     * record now stays, the failure is journaled, and the new package is
     * still produced.
     */
    public function testAPreviousPackageWhoseRecordCannotBeReadKeepsItsRecord(): void
    {
        $service = $this->journaledService();
        $first = $service->generate(null);
        $firstPath = $this->storedPath($first);

        // Only findById() reads this column: the INSERT and the DELETE
        // still work, which is exactly the state that orphaned the bytes.
        $this->pdo->exec('ALTER TABLE files DROP COLUMN custom_resolver');

        $second = $service->generate(null);

        $this->assertNotSame($first, $second);
        $this->assertTrue($this->recordExists($first), 'the only pointer to the old bytes must survive');
        $this->assertFileExists($firstPath);
        $this->assertContains('support_package_delete_failed', $this->journaledTypes());
        $this->settings->clearCache();
        $this->assertSame((string) $second, $this->settings->get(SupportPackageState::FILE_ID));
    }

    /**
     * The same failure seen through to the end of retention: the bytes the
     * failed deletion left behind are gone once they are seven days old,
     * found from the directory even though their record still cannot be
     * read, and their record goes with them.
     */
    public function testBytesLeftByAFailedDeletionAreSweptOncePastRetention(): void
    {
        $service = $this->journaledService();
        $first = $service->generate(null);
        $firstPath = $this->storedPath($first);
        $this->pdo->exec('ALTER TABLE files DROP COLUMN custom_resolver');
        $service->deleteCurrentPackage();

        $this->assertSame(0, $service->sweepExpiredFiles(), 'within retention, nothing is swept');
        $this->assertFileExists($firstPath);

        touch($firstPath, time() - (SupportPackageService::RETENTION_DAYS + 1) * 86400);

        $this->assertSame(1, $service->sweepExpiredFiles());
        $this->assertFileDoesNotExist($firstPath);
        $this->assertFalse($this->recordExists($first));
        $this->assertContains('support_package_files_swept', $this->journaledTypes());
    }

    /**
     * The sweep deletes the expired bytes, then the record that named them.
     * A database that refuses that second step costs the record only: the
     * bytes are gone, which is what retention is about, and the sweep still
     * counts and journals them.
     */
    public function testAnExpiredFileIsSweptEvenWhenItsRecordCannotBeDropped(): void
    {
        $service = $this->journaledService();
        $fileId = $service->generate(null);
        $path = $this->storedPath($fileId);
        touch($path, time() - (SupportPackageService::RETENTION_DAYS + 1) * 86400);
        $this->pdo->exec(
            "CREATE TRIGGER refuse_file_delete BEFORE DELETE ON files BEGIN SELECT RAISE(ABORT, 'refusé'); END"
        );

        $this->assertSame(1, $service->sweepExpiredFiles());

        $this->assertFileDoesNotExist($path);
        $this->assertTrue($this->recordExists($fileId), 'the refused deletion left the record');
        $this->assertContains('support_package_files_swept', $this->journaledTypes());
    }

    /**
     * The journal is often the very database that is failing. When it
     * cannot be written, the sweep still does its work, and the event type
     * — never any part of the archive — reaches the server log instead.
     */
    public function testASweepWhoseJournalCannotBeWrittenFallsBackToTheServerLog(): void
    {
        $service = $this->journaledService();
        $fileId = $service->generate(null);
        $path = $this->storedPath($fileId);
        touch($path, time() - (SupportPackageService::RETENTION_DAYS + 1) * 86400);
        $this->pdo->exec('DROP TABLE event_log');

        $log = (string) tempnam(sys_get_temp_dir(), 'supportlog');
        $previous = ini_set('error_log', $log);
        try {
            $this->assertSame(1, $service->sweepExpiredFiles());
            $written = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($log);
        }

        $this->assertFileDoesNotExist($path);
        $this->assertStringContainsString('ScoutMagic support package: support_package_files_swept', $written);
    }

    /**
     * A database that refuses to drop the record must not block the next
     * package either, and must not keep the old bytes: they are deleted
     * first, and the record left behind names nothing.
     */
    public function testARefusedRecordDeletionStillRemovesTheBytesAndProducesTheNextPackage(): void
    {
        $service = $this->journaledService();
        $first = $service->generate(null);
        $firstPath = $this->storedPath($first);

        $this->pdo->exec(
            "CREATE TRIGGER refuse_file_delete BEFORE DELETE ON files BEGIN SELECT RAISE(ABORT, 'refusé'); END"
        );

        $second = $service->generate(null);

        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($firstPath);
        $this->assertContains('support_package_delete_failed', $this->journaledTypes());
        $this->assertFileExists($this->storedPath($second));
    }

    /**
     * Files nothing references at all — the bookkeeping after a store()
     * never landed, or a record was lost some other way — are found from
     * the directory. Only stored-archive names past retention go: the
     * current package, a fresh orphan, and anything this service did not
     * write are left alone.
     */
    public function testTheSweepRemovesOnlyStoredArchivesPastRetention(): void
    {
        $service = $this->journaledService();
        $current = $service->generate(null);
        $currentPath = $this->storedPath($current);

        $directory = $this->storagePath . '/' . SupportPackageService::STORAGE_SUBDIRECTORY;
        $expired = time() - (SupportPackageService::RETENTION_DAYS + 1) * 86400;
        $oldOrphan = $directory . '/' . bin2hex(random_bytes(16)) . '.bin.enc';
        $freshOrphan = $directory . '/' . bin2hex(random_bytes(16)) . '.bin.enc';
        $foreign = $directory . '/notes.txt';
        foreach ([$oldOrphan, $freshOrphan, $foreign] as $path) {
            file_put_contents($path, 'chiffré');
        }
        touch($oldOrphan, $expired);
        touch($foreign, $expired);

        $this->assertSame(1, $service->sweepExpiredFiles());

        $this->assertFileDoesNotExist($oldOrphan);
        $this->assertFileExists($freshOrphan);
        $this->assertFileExists($foreign);
        $this->assertFileExists($currentPath);
        $this->assertArrayHasKey('README.txt', $this->readArchive($current));
    }

    /**
     * Bytes past retention are removed even when they are still the
     * current package, and the settings stop naming a package that no
     * longer exists.
     */
    public function testACurrentPackageWhoseBytesArePastRetentionIsForgottenEntirely(): void
    {
        $service = $this->journaledService();
        $fileId = $service->generate(null);
        $path = $this->storedPath($fileId);
        touch($path, time() - (SupportPackageService::RETENTION_DAYS + 1) * 86400);

        $this->assertSame(1, $service->sweepExpiredFiles());

        $this->assertFileDoesNotExist($path);
        $this->assertFalse($this->recordExists($fileId));
        $this->settings->clearCache();
        $this->assertNull($service->currentPackageFileId());
    }

    /**
     * A generation killed outright never reaches its `finally`, and its
     * working directory holds the archive in clear. The sweep removes it,
     * and the structure dump written beside it, once a day old, and leaves
     * alone everything a live generation could still be writing, anything
     * it did not create, and every symbolic link.
     */
    public function testAbandonedWorkingFilesAreSweptAndLiveOnesAreNot(): void
    {
        $temp = $this->storagePath . '/temp';
        $old = time() - SupportPackageService::ABANDONED_WORKING_FILE_SECONDS - 3600;

        $abandoned = $this->workingDirectory($temp, $old, $old);
        $stillWriting = $this->workingDirectory($temp, $old, time());
        $fresh = $this->workingDirectory($temp, time(), time());

        $dump = $temp . '/support-structure-' . bin2hex(random_bytes(8)) . '.sql';
        file_put_contents($dump, 'CREATE TABLE members (id INT);');
        touch($dump, $old);
        $foreign = $temp . '/import-' . bin2hex(random_bytes(8));
        file_put_contents($foreign, 'autre');
        touch($foreign, $old);

        $outside = $this->projectRoot . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/scoutmagic-support.zip', 'ailleurs');
        touch($outside . '/scoutmagic-support.zip', $old);
        touch($outside, $old);
        $linked = $temp . '/support-' . bin2hex(random_bytes(8));
        symlink($outside, $linked);
        $holdingALink = $this->workingDirectory($temp, $old, $old);
        symlink($outside . '/scoutmagic-support.zip', $holdingALink . '/lien');
        touch($holdingALink, $old);

        try {
            $this->assertSame(2, $this->journaledService()->sweepAbandonedWorkingFiles());

            $this->assertDirectoryDoesNotExist($abandoned);
            $this->assertFileDoesNotExist($dump);
            $this->assertFileExists($stillWriting . '/scoutmagic-support.zip');
            $this->assertFileExists($fresh . '/scoutmagic-support.zip');
            $this->assertFileExists($holdingALink . '/scoutmagic-support.zip');
            $this->assertFileExists($foreign);
            $this->assertTrue(is_link($linked));
            $this->assertFileExists($outside . '/scoutmagic-support.zip');
            $this->assertContains('support_package_working_files_swept', $this->journaledTypes());
            $this->assertContains('support_package_working_files_sweep_failed', $this->journaledTypes());
        } finally {
            // tearDown() would follow these links; remove them first.
            @unlink($holdingALink . '/lien');
            @unlink($linked);
        }
    }

    /**
     * A working directory as generate() names it, holding a stand-in
     * archive whose mtime is $fileTime, the directory itself at $directoryTime.
     */
    private function workingDirectory(string $temp, int $directoryTime, int $fileTime): string
    {
        $directory = $temp . '/support-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory . '/scoutmagic-support.zip', 'journaux en clair');
        touch($directory . '/scoutmagic-support.zip', $fileTime);
        touch($directory, $directoryTime);

        return $directory;
    }

    private function journaledService(): SupportPackageService
    {
        return new SupportPackageService(
            $this->connection,
            $this->settings,
            $this->fileRepository,
            $this->encryptedStorage,
            $this->projectRoot,
            $this->storagePath,
            [],
            [],
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function storedPath(int $fileId): string
    {
        $record = $this->fileRepository->findById($fileId);
        $this->assertNotNull($record);

        return $this->storagePath . '/' . $record->relativePath;
    }

    private function recordExists(int $fileId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files WHERE id = ?');
        $stmt->execute([$fileId]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * @return list<string>
     */
    private function journaledTypes(): array
    {
        $stmt = $this->pdo->query('SELECT event_type FROM event_log');

        return $stmt !== false ? array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN))) : [];
    }
}
