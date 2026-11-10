<?php

declare(strict_types=1);

namespace Tests\Core\Support;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Scheduler\CoreTaskHandlers;
use Core\Support\Collector\BackupsCollector;
use Core\Support\Collector\ConfigurationParametersCollector;
use Core\Support\Collector\DatabaseStructureCollector;
use Core\Support\Collector\EventJournalCollector;
use Core\Support\Collector\RequestTimelinesCollector;
use Core\Support\Collector\OpcacheCollector;
use Core\Support\Collector\ScheduledTasksCollector;
use Core\Support\Collector\UpdateHistoryCollector;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\UsesProductionEngine;

#[\PHPUnit\Framework\Attributes\Group('database')]
class ApplicationCollectorsTest extends TestCase
{
    use UsesProductionEngine;

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

        $connection = $this->createStub(Connection::class);
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
     * INSERT, ever. Runs against the MySQL test database (the shared
     * `Tests\UsesProductionEngine` connection) because
     * Core\Database\DatabaseDumper genuinely speaks MySQL.
     *
     * @group database
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testTheStructureDumpContainsNoInsertStatement(): void
    {
        // A real Connection rather than a mock: getPdo() and
        // dumpCredentials() then describe the same server, as they do on
        // an installed site, and DatabaseDumper opens its own connection
        // from the latter.
        $connection = self::productionEngineConnection();
        $mysql = $connection->getPdo();

        $mysql->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($mysql->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $table) {
            $mysql->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $mysql->exec('SET FOREIGN_KEY_CHECKS = 1');
        $mysql->exec('CREATE TABLE support_dump_probe (id INT PRIMARY KEY, secret_label VARCHAR(50))');
        $mysql->exec("INSERT INTO support_dump_probe (id, secret_label) VALUES (1, 'ROW-THAT-MUST-NOT-BE-EXPORTED')");

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

    /**
     * Seven days since issue #846, not 48 hours: the restore found on
     * Friday happened on Monday. The sheet and its digest share the bound,
     * newest first.
     */
    public function testTheJournalExportCoversTheLastSevenDays(): void
    {
        $this->insertJournalEntry('-2 hours', 'recent_event');
        $this->insertJournalEntry('-6 days', 'still_inside_the_window');
        $this->insertJournalEntry('-8 days', 'too_old_event');

        $entries = $this->runCollector(new EventJournalCollector())['entries'];
        $rows = $this->readSheet($entries['event-journal.xlsx']);
        $types = array_column(array_slice($rows, 1), 4);

        $this->assertSame(['recent_event', 'still_inside_the_window'], $types, 'inside the window, newest first');
        $digest = $entries['event-journal-resume.txt'];
        $this->assertStringContainsString('résumé des 7 derniers jours', $digest);
        $this->assertStringContainsString('Total : 2 entrée(s)', $digest);
        $this->assertStringContainsString('still_inside_the_window', $digest);
        $this->assertStringNotContainsString('too_old_event', $digest);
        $this->assertStringContainsString(
            'Entrée la plus ancienne : ' . (new \DateTimeImmutable('-6 days'))->format('Y-m-d'),
            $digest
        );
    }

    /**
     * An installation keeping its journal fewer than seven days has no week
     * to give: the archive says how far back it goes instead of announcing
     * a span the database no longer holds.
     */
    public function testAShorterJournalRetentionIsSaidRatherThanAWeekAnnounced(): void
    {
        $this->settings->register('journal_retention_days', '730', 'number', 'Rétention du journal', 'D');
        $this->settingRepository->updateValue(null, 'journal_retention_days', '3');
        $this->settings->clearCache();
        $this->insertJournalEntry('-1 day', 'kept_event');

        $result = $this->runCollector(new EventJournalCollector());

        $this->assertStringContainsString(
            'Ce site ne conserve son journal que 3 jour(s)',
            $result['entries']['event-journal-resume.txt']
        );
        $this->assertContains('Le journal n\'est conservé que 3 jour(s) sur cette installation', $result['notes']);
    }

    /**
     * A flooded week: the detail stops at MAX_ROWS in the query itself, the
     * newest kept, and the digest still counts every entry — the counts are
     * the database's, not the rows read.
     */
    public function testAFloodedWeekIsTruncatedInTheDetailAndCountedWholeInTheDigest(): void
    {
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_log (logged_at, category, event_type, level, description) VALUES (?, 'core', ?, 'info', 'x')"
        );
        $start = new \DateTimeImmutable('-1 day');
        for ($i = 0; $i <= EventJournalCollector::MAX_ROWS; $i++) {
            $stmt->execute([$start->modify('+' . $i . ' seconds')->format('Y-m-d H:i:s'), $i === EventJournalCollector::MAX_ROWS ? 'newest' : 'looping_task']);
        }
        $this->pdo->commit();

        $result = $this->runCollector(new EventJournalCollector());
        $rows = $this->readSheet($result['entries']['event-journal.xlsx']);

        $this->assertCount(EventJournalCollector::MAX_ROWS + 1, $rows, 'header plus MAX_ROWS entries');
        $this->assertSame('newest', $rows[1][4]);
        $this->assertStringContainsString('Total : ' . (EventJournalCollector::MAX_ROWS + 1) . ' entrée(s)', $result['entries']['event-journal-resume.txt']);
        $this->assertStringContainsString('Le détail ne reprend que les ' . EventJournalCollector::MAX_ROWS, $result['entries']['event-journal-resume.txt']);
    }

    /** Long contexts: the text budget stops the detail before the row cap does, newest first. */
    public function testLongEntriesAreBoundedByTheirTextNotOnlyByTheirNumber(): void
    {
        $context = str_repeat('x', 1024 * 1024);
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_log (logged_at, category, event_type, level, description, context) VALUES (?, 'core', ?, 'error', 'x', ?)"
        );
        for ($i = 0; $i < 12; $i++) {
            $stmt->execute([(new \DateTimeImmutable('-' . (12 - $i) . ' minutes'))->format('Y-m-d H:i:s'), 'trace_' . $i, $context]);
        }

        $result = $this->runCollector(new EventJournalCollector());
        $rows = $this->readSheet($result['entries']['event-journal.xlsx']);

        $this->assertLessThan(13, count($rows), 'the twelve megabytes were all kept');
        $this->assertSame('trace_11', $rows[1][4], 'the newest first');
        $this->assertStringContainsString('Total : 12 entrée(s)', $result['entries']['event-journal-resume.txt']);
    }

    public function testAWeekOfJournalSaysNothingAboutRetentionWhenItIsKept(): void
    {
        $this->insertJournalEntry('-1 day', 'kept_event');

        $digest = $this->runCollector(new EventJournalCollector())['entries']['event-journal-resume.txt'];

        $this->assertStringNotContainsString('ne conserve son journal', $digest);
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
        // Six days ago: inside the journal's seven-day window (#846), which
        // 48 hours was not.
        foreach (['-6 days', '-8 days'] as $when) {
            $this->pdo->prepare(
                'INSERT INTO scheduled_actions (module_id, task_key, run_at, status, executed_at) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                'core', 'auto_backup',
                (new \DateTimeImmutable($when))->format('Y-m-d H:i:s'), 'done',
                (new \DateTimeImmutable($when))->format('Y-m-d H:i:s'),
            ]);
        }

        $row = $this->taskRow('auto_backup');

        $this->assertSame('13', $row[10], 'runs inside the seven-day window, and not the one eight days ago');
        $this->assertSame('0', $row[11], 'no failures');
        $this->assertSame('0', $row[12], 'nothing pending');
        $this->assertSame('14', $row[13], 'every instance ever recorded');
    }

    /** The columns say the window the journal says. */
    public function testTheTaskCountsAnnounceTheJournalsWindow(): void
    {
        $header = $this->readSheet(
            $this->runCollector(new ScheduledTasksCollector())['entries']['scheduled-tasks.xlsx']
        )[0];

        $this->assertSame(['Exécutions sur 7 jours', 'Échecs sur 7 jours'], [$header[10], $header[11]]);
    }

    // --- backups.xlsx (#846) ---

    /**
     * Every row, failed ones included, and for each file what the database
     * says, what is on disk now and what the last check said — apart.
     */
    public function testTheBackupInventorySeparatesTheLastCheckFromWhatIsOnDiskNow(): void
    {
        mkdir($this->storagePath . '/backups', 0700, true);
        file_put_contents($this->storagePath . '/backups/present.zip', 'PK');
        $present = $this->fileRow('backups/present.zip', 2);
        $gone = $this->fileRow('backups/supprimee.zip', 4096);
        $dump = $this->fileRow('backups/supprimee.sql', 512);
        $first = $this->backupRow('full_config', 'completed', $present, null, 'intact', null, '2026-10-01 03:00:00');
        // Checked intact, deleted since: restored rows of an archive retention removed.
        $restored = $this->backupRow('portable', 'completed', $gone, $dump, 'intact', null, '2026-10-02 03:00:00');
        $failed = $this->backupRow('database', 'failed', null, null, 'unknown', 'Écriture impossible', '2026-10-03 03:00:00');

        $result = $this->runCollector(new BackupsCollector());
        $rows = $this->readSheet($result['entries']['backups.xlsx']);
        $byId = array_column(array_slice($rows, 1), null, 0);

        $this->assertCount(3, $byId, 'failed backups are listed too');
        $this->assertSame(
            [(string) $failed, (string) $restored, (string) $first],
            array_map('strval', array_keys($byId)),
            'newest first'
        );
        $this->assertSame(
            ['portable', 'completed', 'Sur ce serveur', (string) $gone, 'présente', 'absente', '4096'],
            [$byId[(string) $restored][1], $byId[(string) $restored][3], $byId[(string) $restored][6],
                $byId[(string) $restored][7], $byId[(string) $restored][8], $byId[(string) $restored][9],
                $byId[(string) $restored][10]]
        );
        $this->assertSame('absente', $byId[(string) $restored][13], 'the dump is gone too');
        $this->assertSame('intact', $byId[(string) $restored][15], 'the last check, as stored');
        $this->assertSame(['présente', '—'], [$byId[(string) $first][9], $byId[(string) $first][13]], 'no dump file to look for');
        $this->assertContains('1 sauvegarde(s) dont un fichier est absent du disque', $result['notes']);
        $this->assertContains('3 sauvegarde(s) enregistrée(s)', $result['notes']);
    }

    /** No path, no content, no secret — only ids and what is known about them. */
    public function testTheBackupInventoryCarriesNoPathContentOrSecret(): void
    {
        mkdir($this->storagePath . '/backups', 0700, true);
        file_put_contents($this->storagePath . '/backups/archive.zip', 'contenu-secret-de-l-archive');
        $this->backupRow('full_config', 'failed', $this->fileRow('backups/archive.zip', 27), null, 'unknown', 'mot de passe hunter2-tres-secret refusé', '2026-10-01 03:00:00');

        $result = $this->runCollector(new BackupsCollector(), ['hunter2-tres-secret']);
        $sheet = implode("\n", array_map(static fn(array $row): string => implode('|', $row), $this->readSheet($result['entries']['backups.xlsx'])));

        $this->assertSame(['backups.xlsx'], array_keys($result['entries']));
        $this->assertStringNotContainsString('archive.zip', $sheet);
        $this->assertStringNotContainsString($this->storagePath, $sheet);
        $this->assertStringNotContainsString('contenu-secret', $sheet);
        $this->assertStringNotContainsString('hunter2', $sheet);
    }

    private function fileRow(string $relativePath, int $size): int
    {
        $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, ?, 'application/zip', ?, 'superadmin')"
        )->execute([$relativePath, basename($relativePath), $size]);

        return (int) $this->pdo->lastInsertId();
    }

    private function backupRow(
        string $type,
        string $status,
        ?int $fileId,
        ?int $dumpId,
        string $integrity,
        ?string $error,
        string $createdAt
    ): int {
        $this->pdo->prepare(
            'INSERT INTO backups (type, status, file_id, db_dump_file_id, integrity_status, error_message, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$type, $status, $fileId, $dumpId, $integrity, $error, $createdAt]);

        return (int) $this->pdo->lastInsertId();
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

    /**
     * A `debug_request_timeline` row as public/index.php writes it: the
     * cumulative checkpoints of one request.
     *
     * @param array<int, array{label: string, t_ms: float, sql: int, sql_ms: float}> $timeline
     */
    private function insertTimelineEntry(string $relativeTime, string $path, array $timeline, string $role = 'chief'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO event_log (logged_at, user_account_id, ip_address, category, event_type, level, description, context) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (new \DateTimeImmutable($relativeTime))->format('Y-m-d H:i:s'),
            null,
            '192.0.2.10',
            'core',
            'debug_request_timeline',
            'info',
            'Chronologie détaillée de requête (fenêtre de mesure) : GET ' . $path,
            json_encode(['method' => 'GET', 'path' => $path, 'role' => $role, 'timeline' => array_map(
                static fn(array $entry): array => $entry + ['mem_mb' => 4.0],
                $timeline
            )]),
        ]);
    }

    // --- request-timelines.csv ---

    public function testAWindowThatRecordedNothingIsSaidNotShippedEmpty(): void
    {
        $this->insertJournalEntry('-1 hour', 'login_success');

        $result = $this->runCollector(new RequestTimelinesCollector());

        $this->assertSame('no_measurement_recorded', $result['unavailable']);
        $this->assertSame([], $result['entries']);
    }

    public function testEverySegmentOfARecordedRequestIsOneLineWithItsOwnDeltas(): void
    {
        $this->insertTimelineEntry('-10 minutes', '/chefs/staffs', [
            ['label' => 'request_parsed', 't_ms' => 1.0, 'sql' => 0, 'sql_ms' => 0.0],
            ['label' => 'services_ready', 't_ms' => 21.0, 'sql' => 30, 'sql_ms' => 8.0],
            ['label' => 'controller_dispatch_done', 't_ms' => 145.5, 'sql' => 53, 'sql_ms' => 30.5],
        ]);

        $result = $this->runCollector(new RequestTimelinesCollector());
        $csv = $result['entries']['request-timelines.csv'];
        $lines = array_values(array_filter(explode("\n", $csv)));

        $this->assertSame('horodatage;methode;chemin;role;total_ms;total_sql;segment;segment_ms;segment_sql;segment_sql_ms;memoire_mo', $lines[0]);
        $this->assertCount(3, $lines, 'a header and one line per segment between two checkpoints');
        $this->assertStringContainsString(';GET;/chefs/staffs;chief;145.5;53;services_ready;20.0;30;8.0;4', $lines[1]);
        $this->assertStringContainsString(';GET;/chefs/staffs;chief;145.5;53;controller_dispatch_done;124.5;23;22.5;4', $lines[2]);
    }

    public function testTheSummaryNamesTheSlowestPathFirstAndItsHeaviestSegment(): void
    {
        $fast = [
            ['label' => 'request_parsed', 't_ms' => 1.0, 'sql' => 0, 'sql_ms' => 0.0],
            ['label' => 'services_ready', 't_ms' => 20.0, 'sql' => 30, 'sql_ms' => 8.0],
            ['label' => 'controller_dispatch_done', 't_ms' => 30.0, 'sql' => 35, 'sql_ms' => 9.0],
        ];
        $slow = [
            ['label' => 'request_parsed', 't_ms' => 1.0, 'sql' => 0, 'sql_ms' => 0.0],
            ['label' => 'services_ready', 't_ms' => 20.0, 'sql' => 30, 'sql_ms' => 8.0],
            ['label' => 'controller_dispatch_done', 't_ms' => 160.0, 'sql' => 53, 'sql_ms' => 30.0],
        ];
        $this->insertTimelineEntry('-3 minutes', '/', $fast);
        $this->insertTimelineEntry('-2 minutes', '/chefs/staffs', $slow);
        $this->insertTimelineEntry('-1 minute', '/chefs/staffs', $slow);

        $result = $this->runCollector(new RequestTimelinesCollector());
        $summary = $result['entries']['request-timelines-resume.txt'];
        $lines = array_values(array_filter(explode("\n", $summary), static fn(string $l): bool => str_contains($l, ' — ')));

        $this->assertStringStartsWith('GET /chefs/staffs — 2 requête(s), médiane 160.0 ms, maximum 160.0 ms, 53 instruction(s) SQL en médiane, segment le plus lourd : controller_dispatch_done (140.0 ms)', $lines[0]);
        $this->assertStringStartsWith('GET / — 1 requête(s), médiane 30.0 ms', $lines[1]);
        $this->assertStringContainsString('Temps serveur uniquement', $summary);
        $this->assertContains('2 chemin(s) mesuré(s), 3 requête(s)', $result['notes']);
    }

    public function testTimelinesOlderThanTheJournalWindowAreLeftOut(): void
    {
        $timeline = [
            ['label' => 'request_parsed', 't_ms' => 1.0, 'sql' => 0, 'sql_ms' => 0.0],
            ['label' => 'services_ready', 't_ms' => 20.0, 'sql' => 30, 'sql_ms' => 8.0],
        ];
        $this->insertTimelineEntry('-49 hours', '/vieux', $timeline);
        $this->insertTimelineEntry('-1 hour', '/recent', $timeline);

        $csv = $this->runCollector(new RequestTimelinesCollector())['entries']['request-timelines.csv'];

        $this->assertStringContainsString('/recent', $csv);
        $this->assertStringNotContainsString('/vieux', $csv);
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

    // --- scheduler-settings.txt (A3) ---

    /**
     * A task that "never ran" and a task whose schedule is switched off
     * look identical on scheduled-tasks.xlsx. These settings tell them
     * apart, and none of them appeared anywhere in the archive before.
     */
    public function testTheSettingsDrivingBackgroundBehaviourAreReported(): void
    {
        $settings = $this->runCollector(new ScheduledTasksCollector())['entries']['scheduler-settings.txt'];

        foreach ([
            'backup_auto_frequency',
            'auto_update_enabled',
            'auto_update_level',
            'dev_update_branch',
            'notifications_retention_days',
            'offline_cache_staleness_days',
        ] as $key) {
            $this->assertStringContainsString($key, $settings);
        }
    }

    /**
     * An unset key is written out as such rather than omitted: a missing
     * row is indistinguishable from a key nobody thought to include, and
     * "(non défini)" is itself the answer — the code's own default
     * applies, rather than anything an admin chose.
     */
    public function testAnUnsetSettingIsPrintedRatherThanOmitted(): void
    {
        $settings = $this->runCollector(new ScheduledTasksCollector())['entries']['scheduler-settings.txt'];

        $this->assertStringContainsString('(non défini)', $settings);
    }

    public function testAConfiguredSettingIsPrintedWithItsValue(): void
    {
        $this->settings->register('auto_update_level', 'patch', 'text', 'niveau', 'test', null, null, null, false, 900);
        $this->settings->setInternal('auto_update_level', 'minor');

        $settings = $this->runCollector(new ScheduledTasksCollector())['entries']['scheduler-settings.txt'];

        $this->assertMatchesRegularExpression('/auto_update_level\s+minor\s/', $settings);
    }
}
