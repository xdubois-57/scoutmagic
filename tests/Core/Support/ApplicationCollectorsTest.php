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
use Core\Support\Collector\OpcacheCollector;
use Core\Support\Collector\ScheduledTasksCollector;
use Core\Support\Collector\UpdateHistoryCollector;
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
            [
                'Horodatage (heure locale du serveur)',
                'Compte utilisateur', 'Adresse IP', 'Catégorie', 'Type', 'Niveau', 'Description', 'Contexte',
            ],
            array_slice($rows[0], 0, 8)
        );
        $this->assertSame('login_success', $rows[1][4]);
        $this->assertSame('192.0.2.10', $rows[1][2]);
    }

    /**
     * A DB DATETIME carries no zone. Reading these rows against the UTC
     * stamps in collection-status.json, or against a server log, is the
     * whole reason the sheet exists — so each cell states its own offset
     * rather than leaving the reader to guess which clock it is on.
     */
    public function testEveryJournalTimestampCarriesItsUtcOffset(): void
    {
        $this->insertJournalEntry('-1 hour', 'login_success');

        $rows = $this->readSheet($this->runCollector(new EventJournalCollector())['entries']['event-journal.xlsx']);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $rows[1][0]
        );
    }

    /**
     * The journal is dominated by whatever runs most often — two scheduled
     * tasks were 475 of 884 rows on the archive that prompted this — so
     * the shape of the 48 hours is stated before anyone starts scrolling.
     */
    public function testTheJournalShipsWithADigestOfWhatItContains(): void
    {
        $this->insertJournalEntry('-1 hour', 'login_success');
        $this->insertJournalEntry('-2 hours', 'scheduler_task_done');
        $this->insertJournalEntry('-3 hours', 'scheduler_task_done');

        $digest = $this->runCollector(new EventJournalCollector())['entries']['event-journal-resume.txt'];

        $this->assertStringContainsString('Total : 3 entrée(s)', $digest);
        $this->assertStringContainsString('scheduler_task_done', $digest);
        // Most frequent first: the noisiest type is the one worth seeing.
        $this->assertLessThan(
            strpos($digest, 'login_success'),
            (int) strpos($digest, 'scheduler_task_done'),
            'the digest must list the most frequent event type first'
        );
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

    /**
     * The column that would have named the busy-loop. "Last run" showed a
     * tidy `done` for a task that had re-armed itself 277 times in ten
     * hours doing nothing; a count over the journal's own window makes the
     * difference between a daily task and a runaway one obvious.
     */
    public function testHowOftenATaskRanIsCountedNotJustWhetherItRan(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->pdo->prepare(
                'INSERT INTO scheduled_actions (module_id, task_key, run_at, status, executed_at) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                'core', 'auto_backup',
                (new \DateTimeImmutable("-{$i} hours"))->format('Y-m-d H:i:s'), 'done',
                (new \DateTimeImmutable("-{$i} hours"))->format('Y-m-d H:i:s'),
            ]);
        }
        // Outside the window, so it must not be counted.
        $this->pdo->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, run_at, status, executed_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            'core', 'auto_backup',
            (new \DateTimeImmutable('-60 hours'))->format('Y-m-d H:i:s'), 'done',
            (new \DateTimeImmutable('-60 hours'))->format('Y-m-d H:i:s'),
        ]);

        $row = $this->taskRow('auto_backup');

        $this->assertSame('12', $row[10], 'runs inside the 48 h window');
        $this->assertSame('0', $row[11], 'no failures');
        $this->assertSame('0', $row[12], 'nothing pending');
        $this->assertSame('13', $row[13], 'every instance ever recorded');
    }

    public function testAQueueNothingIsDrainingIsVisibleAsPending(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->pdo->prepare(
                'INSERT INTO scheduled_actions (module_id, task_key, run_at, status) VALUES (?, ?, ?, ?)'
            )->execute(['core', 'auto_backup', '2026-08-01 03:00:00', 'pending']);
        }

        $this->assertSame('3', $this->taskRow('auto_backup')[12]);
    }

    /**
     * @return array<int, string>
     */
    private function taskRow(string $taskKey): array
    {
        $rows = $this->readSheet($this->runCollector(new ScheduledTasksCollector())['entries']['scheduled-tasks.xlsx']);
        foreach (array_slice($rows, 1) as $candidate) {
            if ($candidate[1] === $taskKey) {
                return $candidate;
            }
        }

        $this->fail("No row for task '{$taskKey}' in scheduled-tasks.xlsx");
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

    // --- update-history.xlsx ---

    /**
     * The question the archive could not answer: an error logged at 19:57
     * belongs to whatever was installed at 19:57, which on an
     * auto-updating installation may be four versions back. Rebuilding
     * that from the event journal meant picking 46 rows out of 884.
     */
    public function testTheVersionTimelineIsReportedNewestFirst(): void
    {
        $this->insertUpdate('1.0.31', '1.0.32', 'completed', '2026-08-01 03:00:00', '2026-08-01 03:00:20');
        $this->insertUpdate('1.0.32', '1.0.33', 'completed', '2026-08-02 03:00:00', '2026-08-02 03:01:30');

        $rows = $this->readSheet($this->runCollector(new UpdateHistoryCollector())['entries']['update-history.xlsx']);

        $this->assertSame('1.0.33', $rows[1][4], 'newest install first');
        $this->assertSame('1.0.32', $rows[2][4]);
        // A duration says whether an install is genuinely stuck.
        $this->assertSame('1 min 30 s', $rows[1][2]);
        $this->assertSame('20 s', $rows[2][2]);
    }

    /**
     * An update that failed and restored itself leaves no trace a reader
     * would otherwise notice — the site looks healthy afterwards.
     */
    public function testARolledBackUpdateIsVisibleWithItsReason(): void
    {
        $this->insertUpdate('1.0.32', '1.0.33', 'rolled_back', '2026-08-02 03:00:00', '2026-08-02 03:02:00', 'migration échouée');

        $rows = $this->readSheet($this->runCollector(new UpdateHistoryCollector())['entries']['update-history.xlsx']);

        $this->assertSame('rolled_back', $rows[1][5]);
        $this->assertStringContainsString('migration échouée', $rows[1][7]);
    }

    public function testAnUpdateErrorQuotingASecretIsRedacted(): void
    {
        $secret = 'sup3r-s3cret-db-password';
        $this->insertUpdate('1.0.32', '1.0.33', 'failed', '2026-08-02 03:00:00', null, 'access denied using ' . $secret);

        $xlsx = $this->runCollector(new UpdateHistoryCollector(), [$secret])['entries']['update-history.xlsx'];
        $flattened = implode(' ', array_map(static fn(array $row): string => implode(' ', $row), $this->readSheet($xlsx)));

        $this->assertStringNotContainsString($secret, $flattened);
        $this->assertStringContainsString('[REDACTED]', $flattened);
    }

    public function testTheTimelineIsBoundedAndSaysSo(): void
    {
        for ($i = 0; $i < UpdateHistoryCollector::MAX_ROWS + 5; $i++) {
            $this->insertUpdate('dev-a', 'dev-b', 'completed', (new \DateTimeImmutable("-{$i} hours"))->format('Y-m-d H:i:s'), null);
        }

        $result = $this->runCollector(new UpdateHistoryCollector());
        $rows = $this->readSheet($result['entries']['update-history.xlsx']);

        $this->assertCount(UpdateHistoryCollector::MAX_ROWS + 1, $rows, 'header plus the cap');
        $this->assertNotEmpty(
            array_filter($result['notes'], static fn(string $n): bool => str_contains($n, 'tronqué')),
            'a bounded collector must say what it dropped'
        );
    }

    // --- opcache.json ---

    /**
     * phpinfo.html already carries the ini values; what it cannot say is
     * how long this installation can keep running code an update has
     * already replaced. That window returned 500 on every route for 54
     * seconds on a real site.
     */
    public function testTheOpcacheReportStatesTheStaleCodeWindow(): void
    {
        $result = $this->runCollector(new OpcacheCollector());

        if ($result['unavailable'] !== null) {
            // OPcache is off for CLI by default, which is itself a valid
            // outcome: the collector must say why rather than throw.
            $this->assertStringContainsString('opcache', $result['unavailable']);

            return;
        }

        $report = json_decode($result['entries']['opcache.json'], true);
        $this->assertIsArray($report);
        $this->assertArrayHasKey('stale_window_seconds', $report);
        $this->assertArrayHasKey('note', $report);
        $this->assertArrayNotHasKey('scripts', $report, 'the per-file inventory would dwarf the archive');
    }

    private function insertUpdate(
        string $from,
        string $to,
        string $status,
        string $startedAt,
        ?string $completedAt,
        ?string $error = null
    ): void {
        $this->pdo->prepare(
            'INSERT INTO update_history (version_from, version_to, status, started_at, completed_at, error_message) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$from, $to, $status, $startedAt, $completedAt, $error]);
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
