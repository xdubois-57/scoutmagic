<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Scheduler\CoreTaskHandlers;
use Core\Support\Collector\ConfigurationParametersCollector;
use Core\Support\Collector\DatabaseStructureCollector;
use Core\Support\Collector\EventJournalCollector;
use Core\Support\Collector\ScheduledTasksCollector;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class ApplicationCollectorsTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SettingRepository $settingRepository;
    private string $projectRoot;
    private string $storagePath;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-collectors-' . bin2hex(random_bytes(6));
        $this->storagePath = $this->projectRoot . '/storage';
        mkdir($this->storagePath . '/temp', 0700, true);

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $connection->method('dumpCredentials')->willReturn([
            'host' => '', 'port' => 0, 'dbName' => '', 'user' => '', 'password' => '',
        ]);
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
     * Runs one collector into a real archive and returns its entries plus
     * whatever it declared about itself.
     *
     * @param array<int, string> $secrets
     * @return array{entries: array<string, string>, unavailable: ?string, notes: array<int, string>}
     */
    private function runCollector(SupportCollectorInterface $collector, array $secrets = []): array
    {
        $archivePath = $this->storagePath . '/temp/collector-' . bin2hex(random_bytes(6)) . '.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        $context = new SupportCollectorContext(
            $archive,
            $this->connection,
            $this->settings,
            $this->projectRoot,
            $this->storagePath,
            $secrets
        );

        $collector->collect($context);
        $archive->close();

        $entries = [];
        $reader = new \ZipArchive();
        if ($reader->open($archivePath) === true) {
            for ($i = 0; $i < $reader->numFiles; $i++) {
                $entries[(string) $reader->getNameIndex($i)] = (string) $reader->getFromIndex($i);
            }
            $reader->close();
        }
        @unlink($archivePath);

        return ['entries' => $entries, 'unavailable' => $context->unavailableReason(), 'notes' => $context->notes()];
    }

    /**
     * @return array<int, array<int, string>> rows, header row included
     */
    private function readSheet(string $xlsxBytes): array
    {
        $path = sys_get_temp_dir() . '/scoutmagic-sheet-' . bin2hex(random_bytes(6)) . '.xlsx';
        file_put_contents($path, $xlsxBytes);

        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        unlink($path);

        return array_map(
            static fn(array $row): array => array_map(static fn($cell): string => (string) $cell, $row),
            $rows
        );
    }

    // --- database-structure.sql ---

    public function testTheStructureDumpIsUnavailableWithoutMysqlCredentials(): void
    {
        $result = $this->runCollector(new DatabaseStructureCollector());

        $this->assertSame('no_mysql_connection_credentials', $result['unavailable']);
        $this->assertSame([], $result['entries']);
    }

    /**
     * The one requirement that has to be verified against a real dump: no
     * INSERT, ever. Runs against the MySQL test database because
     * Core\Database\DatabaseDumper genuinely speaks MySQL.
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testTheStructureDumpContainsNoInsertStatement(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $mysql = new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName),
                $user,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
        }

        $mysql->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($mysql->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $table) {
            $mysql->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $mysql->exec('SET FOREIGN_KEY_CHECKS = 1');
        $mysql->exec('CREATE TABLE support_dump_probe (id INT PRIMARY KEY, secret_label VARCHAR(50))');
        $mysql->exec("INSERT INTO support_dump_probe (id, secret_label) VALUES (1, 'ROW-THAT-MUST-NOT-BE-EXPORTED')");

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($mysql);
        $connection->method('dumpCredentials')->willReturn([
            'host' => $host, 'port' => $port, 'dbName' => $dbName, 'user' => $user, 'password' => $password,
        ]);
        $this->connection = $connection;

        $result = $this->runCollector(new DatabaseStructureCollector());

        $this->assertNull($result['unavailable']);
        $sql = $result['entries']['database-structure.sql'] ?? '';
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('support_dump_probe', $sql);
        $this->assertDoesNotMatchRegularExpression('/\bINSERT\s+INTO\b/i', $sql);
        $this->assertStringNotContainsString('ROW-THAT-MUST-NOT-BE-EXPORTED', $sql);

        $mysql->exec('DROP TABLE IF EXISTS support_dump_probe');
    }

    // --- configuration-parameters.xlsx ---

    public function testASecretSettingIsRedactedWhileAnOrdinaryOneIsNot(): void
    {
        $this->settings->register('ordinary_key', 'ordinary-default', 'text', 'Libellé ordinaire', 'D');
        $this->settingRepository->updateValue(null, 'ordinary_key', 'ordinary-current');
        $this->settings->register('future_secret', 'sensitive-default', ConfigurationParametersCollector::SECRET_SETTING_TYPE, 'Libellé secret', 'D');
        $this->settingRepository->updateValue(null, 'future_secret', 'sensitive-current');
        $this->settings->clearCache();

        $rows = $this->readSheet($this->runCollector(new ConfigurationParametersCollector())['entries']['configuration-parameters.xlsx']);
        $byKey = [];
        foreach (array_slice($rows, 1) as $row) {
            $byKey[$row[0]] = $row;
        }

        $this->assertSame('ordinary-current', $byKey['ordinary_key'][4]);
        $this->assertSame('ordinary-default', $byKey['ordinary_key'][5]);

        $this->assertSame(ConfigurationParametersCollector::REDACTED, $byKey['future_secret'][4]);
        $this->assertSame(ConfigurationParametersCollector::REDACTED, $byKey['future_secret'][5]);
        // The key and the label stay visible — knowing a secret is set is
        // itself diagnostic.
        $this->assertSame('Libellé secret', $byKey['future_secret'][2]);
    }

    public function testTheDifferenceFlagReflectsTheValueAgainstItsDefault(): void
    {
        $this->settings->register('untouched', 'same', 'text', 'L', 'D');
        $this->settings->register('changed', 'original', 'text', 'L', 'D');
        $this->settingRepository->updateValue(null, 'changed', 'modified');
        $this->settings->clearCache();

        $rows = $this->readSheet($this->runCollector(new ConfigurationParametersCollector())['entries']['configuration-parameters.xlsx']);
        $byKey = [];
        foreach (array_slice($rows, 1) as $row) {
            $byKey[$row[0]] = $row;
        }

        $this->assertSame('non', $byKey['untouched'][6]);
        $this->assertSame('oui', $byKey['changed'][6]);
    }

    public function testEncryptedCredentialTablesAreNeverReadAtAll(): void
    {
        // Nothing outside `settings` is exported: the collector reads that
        // one table and nothing else, so a credentials table cannot leak
        // through it even by accident.
        $this->pdo->exec('CREATE TABLE llm_providers (id INTEGER PRIMARY KEY, api_key BLOB)');
        $this->pdo->prepare('INSERT INTO llm_providers (api_key) VALUES (?)')->execute(['SUPER-SECRET-API-KEY']);
        $this->settings->register('ordinary_key', 'value', 'text', 'L', 'D');
        $this->settings->clearCache();

        $xlsx = $this->runCollector(new ConfigurationParametersCollector())['entries']['configuration-parameters.xlsx'];
        $flattened = implode(' ', array_map(static fn(array $row): string => implode(' ', $row), $this->readSheet($xlsx)));

        $this->assertStringNotContainsString('SUPER-SECRET-API-KEY', $flattened);
        $this->assertStringNotContainsString('llm_providers', $flattened);
        $this->assertStringNotContainsString('api_key', $flattened);
    }

    // --- event-journal.xlsx ---

    public function testTheJournalExportIsBoundedTo48Hours(): void
    {
        $this->insertJournalEntry('-2 hours', 'recent_event');
        $this->insertJournalEntry('-47 hours', 'still_inside_the_window');
        $this->insertJournalEntry('-49 hours', 'too_old_event');

        $rows = $this->readSheet($this->runCollector(new EventJournalCollector())['entries']['event-journal.xlsx']);
        $types = array_column(array_slice($rows, 1), 4);

        $this->assertContains('recent_event', $types);
        $this->assertContains('still_inside_the_window', $types);
        $this->assertNotContains('too_old_event', $types);
    }

    public function testTheJournalExportIsStructuredOneColumnPerField(): void
    {
        $this->insertJournalEntry('-1 hour', 'login_success');

        $rows = $this->readSheet($this->runCollector(new EventJournalCollector())['entries']['event-journal.xlsx']);

        $this->assertSame(
            ['Horodatage', 'Compte utilisateur', 'Adresse IP', 'Catégorie', 'Type', 'Niveau', 'Description', 'Contexte'],
            array_slice($rows[0], 0, 8)
        );
        $this->assertSame('login_success', $rows[1][4]);
        $this->assertSame('192.0.2.10', $rows[1][2]);
    }

    // --- scheduled-tasks.xlsx ---

    public function testEveryDeclaredCoreHandlerAppearsEvenWithoutAnyRow(): void
    {
        $rows = $this->readSheet($this->runCollector(new ScheduledTasksCollector())['entries']['scheduled-tasks.xlsx']);
        $taskKeys = array_column(array_slice($rows, 1), 1);

        foreach (array_keys(CoreTaskHandlers::all()) as $expected) {
            $this->assertContains($expected, $taskKeys);
        }
    }

    public function testTheSheetCarriesNoCadenceOrEnabledColumn(): void
    {
        $rows = $this->readSheet($this->runCollector(new ScheduledTasksCollector())['entries']['scheduled-tasks.xlsx']);
        $headers = implode(' | ', $rows[0]);

        $this->assertStringNotContainsString('Cadence', $headers);
        $this->assertStringNotContainsString('Activé', $headers);
        $this->assertStringNotContainsString('Fréquence', $headers);
    }

    public function testTheLastExecutionAndTheNextPendingInstanceAreReported(): void
    {
        $this->pdo->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, reference, run_at, status, attempts, last_error, executed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['core', 'auto_backup', 'auto', '2026-08-01 03:00:00', 'failed', 2, 'disk full', '2026-08-01 03:00:05']);
        $this->pdo->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, reference, run_at, status) VALUES (?, ?, ?, ?, ?)'
        )->execute(['core', 'auto_backup', 'auto', '2026-09-01 03:00:00', 'pending']);

        $rows = $this->readSheet($this->runCollector(new ScheduledTasksCollector())['entries']['scheduled-tasks.xlsx']);
        $row = null;
        foreach (array_slice($rows, 1) as $candidate) {
            if ($candidate[1] === 'auto_backup') {
                $row = $candidate;
            }
        }

        $this->assertNotNull($row);
        $this->assertSame('2026-08-01 03:00:00', $row[3]);
        $this->assertSame('2026-08-01 03:00:05', $row[4]);
        $this->assertSame('failed', $row[5]);
        $this->assertSame('2', $row[6]);
        $this->assertSame('disk full', $row[7]);
        $this->assertSame('2026-09-01 03:00:00', $row[8]);
        $this->assertSame('auto', $row[9]);
    }

    public function testALastErrorQuotingASecretIsRedacted(): void
    {
        $secret = 'sup3r-s3cret-db-password';
        $this->pdo->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, run_at, status, last_error, executed_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['core', 'auto_backup', '2026-08-01 03:00:00', 'failed', 'access denied using password ' . $secret, '2026-08-01 03:00:05']);

        $xlsx = $this->runCollector(new ScheduledTasksCollector(), [$secret])['entries']['scheduled-tasks.xlsx'];
        $flattened = implode(' ', array_map(static fn(array $row): string => implode(' ', $row), $this->readSheet($xlsx)));

        $this->assertStringNotContainsString($secret, $flattened);
        $this->assertStringContainsString('[REDACTED]', $flattened);
    }

    public function testTheSheetCarriesNoFullExecutionHistory(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->prepare(
                'INSERT INTO scheduled_actions (module_id, task_key, run_at, status, executed_at) VALUES (?, ?, ?, ?, ?)'
            )->execute(['core', 'auto_backup', "2026-08-0{$i} 03:00:00", 'done', "2026-08-0{$i} 03:00:05"]);
        }

        $rows = $this->readSheet($this->runCollector(new ScheduledTasksCollector())['entries']['scheduled-tasks.xlsx']);
        $autoBackupRows = array_filter(array_slice($rows, 1), static fn(array $row): bool => $row[1] === 'auto_backup');

        $this->assertCount(1, $autoBackupRows);
        $this->assertSame('2026-08-05 03:00:05', array_values($autoBackupRows)[0][4]);
    }

    private function insertJournalEntry(string $relativeTime, string $eventType): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO event_log (logged_at, user_account_id, ip_address, category, event_type, level, description, context) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (new \DateTimeImmutable($relativeTime))->format('Y-m-d H:i:s'),
            null,
            '192.0.2.10',
            'core',
            $eventType,
            'info',
            'Description de test',
            '{"k":"v"}',
        ]);
    }
}
