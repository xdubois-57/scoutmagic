<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\Task\RestoreBackupHandler;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;

/**
 * Restoring a backup, actually restored.
 *
 * The existing RestoreBackupHandlerTest covers the outer guard and says
 * plainly why it stops there: everything past the safety backup needs a
 * live database, so source resolution, the restore itself and the
 * rollback were left to a comment. Measured, the handler sat at 15 % —
 * 235 lines, the largest poorly-verified block in the repository, and the
 * one code path in the application that only ever runs on a bad day.
 *
 * This file takes the trade-off the other one declined. It runs against a
 * real MySQL server (skipped where there is none, exactly as the other
 * database-backed tests do) and, crucially, against **its own throwaway
 * database and its own throwaway file tree** — a restore drops and
 * reloads a schema, which is not something to do to the suite's shared
 * fixture halfway through a run.
 *
 * What it pins is the sequence an operator is trusting on the day they
 * use this button:
 *
 * - the state as of the backup comes back — the whole point, and nothing
 *   below matters if this does not hold;
 * - a safety copy of the CURRENT state is taken first, and completed,
 *   before anything is overwritten;
 * - an upload that is not a backup is refused **before** the restore
 *   starts, not halfway through it;
 * - a restore that fails leaves the installation where it was, because
 *   the safety copy is not decoration.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RestoreBackupRoundTripTest extends TestCase
{
    private ?Connection $connection = null;
    private string $database = '';
    private string $storagePath = '';
    private ?\PDO $server = null;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $server = new \PDO(
                sprintf('mysql:host=%s;port=%d', $host, $port),
                $user,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('No MySQL server to restore into: ' . $e->getMessage());
        }

        // Its own database: a restore drops and reloads a schema, and doing
        // that to the suite's shared fixture mid-run would break whatever
        // test happened to come next.
        $this->database = 'scoutmagic_restore_' . bin2hex(random_bytes(6));
        $server->exec('CREATE DATABASE `' . $this->database . '`');
        $this->server = $server;

        $connection = new Connection($host, $port, $this->database, $user, $password);
        $result = $connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database connection not available: ' . $result);
        }
        $this->connection = $connection;

        $this->createSchema();

        // Its own file tree, because restoreFiles() extracts over the
        // PARENT of the storage path — pointing this at the repository
        // would have the test overwrite the code it is running.
        $base = sys_get_temp_dir() . '/scoutmagic_restore_' . bin2hex(random_bytes(6));
        $this->storagePath = $base . '/storage';
        mkdir($this->storagePath . '/backups', 0o777, true);

        // One real file in one of the directories BackupService archives:
        // an empty ZipArchive is never written to disk at all, so without
        // it the safety copy "succeeds" and leaves nothing behind — which
        // is exactly the failure this test exists to catch, and it would
        // have caught the fixture instead.
        mkdir($base . '/public', 0o777, true);
        file_put_contents($base . '/public/index.php', "<?php // fixture\n");
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        }
        if ($this->storagePath !== '') {
            $this->removeTree(dirname($this->storagePath));
        }
    }

    // ── the round trip ────────────────────────────────────────────────

    public function testTheStateAsOfTheBackupComesBack(): void
    {
        $this->setUnitName('Unité du Chêne');
        $backupId = $this->takeServerBackup();

        $this->setUnitName('Unité renommée par erreur');

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);

        $this->assertSame('Unité du Chêne', $this->unitName());
    }

    public function testARowCreatedAfterTheBackupIsGoneAgain(): void
    {
        $backupId = $this->takeServerBackup();

        (new SettingService(new SettingRepository($this->pdo())))
            ->register('ajoute_apres', 'x', 'text', 'Ajouté après la sauvegarde', 'Fixture.');

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);

        $this->assertSame(
            0,
            (int) $this->pdo()->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'ajoute_apres'")->fetchColumn(),
            'A restore is a return to a moment, not a merge with it.'
        );
    }

    // ── the safety copy ───────────────────────────────────────────────

    /**
     * The restore replaces the database — including the `backups` table,
     * including the row recording the safety copy taken moments earlier.
     * So the row is gone the instant it becomes the only thing standing
     * between the operator and a half-restored installation.
     *
     * That matters because the migration runs on a LATER pass (the file
     * tree has just been replaced under a running process, so migrating
     * from here would mix two versions), and that pass rolls back by
     * looking the safety copy up by id.
     */
    public function testTheSafetyCopyIsStillReachableOnTheNextPass(): void
    {
        $backupId = $this->takeServerBackup();

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);

        $payload = $this->resumePayload();
        $this->assertNotSame([], $payload, 'The migration is resumed on a later pass; the pass must exist.');

        $this->assertTrue(
            $this->safetyCopyIsResolvable($payload),
            'The safety copy is on disk, and the resumed pass cannot find it: an operator whose migration '
                . 'then fails is told a manual intervention is needed, beside a usable copy.'
        );
    }

    public function testTheSafetyCopyIsTakenAndCompletedBeforeAnythingIsOverwritten(): void
    {
        $backupId = $this->takeServerBackup();

        // Nothing to restore FROM means the attempt stops before touching
        // the database, so the safety row is still where it was written.
        $this->restore(['source' => 'server', 'backup_id' => $backupId, 'uploaded_temp_path' => null]);

        $this->assertNotSame(
            [],
            glob($this->storagePath . '/maintenance/files_*.zip') ?: [],
            'A safety copy that produced no archive is not a safety copy.'
        );
    }

    // ── refusing before touching anything ─────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function filesThatAreNotBackups(): array
    {
        return [
            'not a zip at all' => ['pas du tout une archive'],
            // What a browser leaves behind when an upload is cut short:
            // the first bytes of a zip and nothing after them. (Not an
            // empty file — PHP deprecates opening one as an archive, and a
            // deprecation in the suite is noise, not a finding.)
            'a zip cut short mid-upload' => ["PK\x03\x04\x14\x00"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('filesThatAreNotBackups')]
    public function testAnUploadThatIsNotABackupIsRefusedAndChangesNothing(string $contents): void
    {
        $this->setUnitName('Unité du Chêne');
        $upload = $this->storagePath . '/upload.zip';
        file_put_contents($upload, $contents);

        $this->restore(['source' => 'upload', 'uploaded_temp_path' => $upload]);

        $this->assertSame('Unité du Chêne', $this->unitName());
    }

    public function testAZipWithoutADatabaseDumpIsRefusedAndChangesNothing(): void
    {
        $this->setUnitName('Unité du Chêne');
        $upload = $this->storagePath . '/upload.zip';
        $zip = new \ZipArchive();
        $zip->open($upload, \ZipArchive::CREATE);
        $zip->addFromString('lisezmoi.txt', 'une archive, mais pas une sauvegarde');
        $zip->close();

        $this->restore(['source' => 'upload', 'uploaded_temp_path' => $upload]);

        $this->assertSame('Unité du Chêne', $this->unitName());
    }

    /**
     * The temporary upload is deleted whether the restore worked or not —
     * a rejected archive left behind is a complete copy of somebody's site
     * sitting in a temp directory.
     */
    public function testARefusedUploadIsNotLeftOnDisk(): void
    {
        $upload = $this->storagePath . '/upload.zip';
        file_put_contents($upload, 'pas une archive');

        $this->restore(['source' => 'upload', 'uploaded_temp_path' => $upload]);

        $this->assertFileDoesNotExist($upload);
    }

    public function testAnUnknownServerBackupIsRefusedAndChangesNothing(): void
    {
        $this->setUnitName('Unité du Chêne');

        $this->restore(['source' => 'server', 'backup_id' => 999999]);

        $this->assertSame('Unité du Chêne', $this->unitName());
    }

    // ── the rollback ──────────────────────────────────────────────────

    /**
     * The safety copy exists for exactly this: a restore that fails
     * halfway must leave the installation where it was, not between two
     * states.
     */
    public function testARestoreThatFailsPutsTheInstallationBackWhereItWas(): void
    {
        $this->setUnitName('Unité du Chêne');

        $upload = $this->storagePath . '/corrompu.zip';
        $zip = new \ZipArchive();
        $zip->open($upload, \ZipArchive::CREATE);
        // A dump the restorer will choke on, inside an otherwise valid
        // archive: refused by the database, not by the zip reader.
        $zip->addFromString('database.sql', 'CECI N\'EST PAS DU SQL;');
        $zip->close();

        $this->restore(['source' => 'upload', 'uploaded_temp_path' => $upload]);

        $this->assertSame('Unité du Chêne', $this->unitName());
    }

    /**
     * `warning` exists in this journal for exactly this class of event —
     * a rejected report, a booking mail that would not send. A restore
     * that failed and rolled back, filed as `info`, sits in the journal
     * beside every page view an operator ever caused.
     */
    public function testAFailedRestoreIsNotFiledAsRoutineInformation(): void
    {
        $upload = $this->storagePath . '/corrompu.zip';
        $zip = new \ZipArchive();
        $zip->open($upload, \ZipArchive::CREATE);
        $zip->addFromString('database.sql', 'CECI N\'EST PAS DU SQL;');
        $zip->close();

        $this->restore(['source' => 'upload', 'uploaded_temp_path' => $upload]);

        $levels = $this->pdo()
            ->query("SELECT level FROM event_log WHERE category = 'core'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertNotEmpty(
            array_intersect(['error', 'warning'], $levels),
            'A restore nobody was watching that failed must not fail quietly.'
        );
    }

    // ── the pass that comes after ─────────────────────────────────────

    /**
     * The migration runs on a LATER pass, and that pass is where a
     * failure still has to be undoable. It is also the pass with no
     * database of its own to look anything up in — see
     * `RestoreBackupHandler::resolveSafetyCopy()`.
     */
    public function testAResumedPassThatMigratesCleanlyFinishesTheRestore(): void
    {
        $this->resume($this->safetyCopyOnDisk());

        $types = $this->journalTypes();
        $this->assertContains('backup_restored', $types);
    }

    /**
     * The decision the fix is made of, on its own: where the resumed pass
     * looks for the safety copy, and in what order.
     *
     * The order is the whole point. A path travels in the payload and
     * outlives the database; a row id is resolved against whatever
     * database the restore has just put in place, which is not the one
     * the id was minted in.
     */
    public function testThePathsCarriedInThePayloadAreWhatTheRollbackUses(): void
    {
        $safety = $this->safetyCopyOnDisk();

        [$dump, $zip] = RestoreBackupHandler::resolveSafetyCopy(
            $safety['safety_db_dump_path'],
            $safety['safety_zip_path'],
            // An id that resolves to nothing, which is the normal state
            // after a restore replaced the `backups` table.
            999999,
            new BackupRepository($this->pdo()),
            new FileRepository($this->pdo()),
            $this->storagePath
        );

        $this->assertSame($safety['safety_db_dump_path'], $dump);
        $this->assertSame($safety['safety_zip_path'], $zip);
    }

    /**
     * A pass queued before this file carried paths at all — an
     * installation upgraded between a restore and its own resume. The row
     * is the only thing it has, and it is checked against the disk like
     * anything else.
     */
    public function testWithoutPathsItStillFindsACopyTheRowCanPointAt(): void
    {
        $backupId = $this->takeServerBackup();

        [$dump, $zip] = RestoreBackupHandler::resolveSafetyCopy(
            null,
            null,
            $backupId,
            new BackupRepository($this->pdo()),
            new FileRepository($this->pdo()),
            $this->storagePath
        );

        // That backup registered a dump but no file archive, so it cannot
        // serve as a safety copy — and saying so is the point.
        $this->assertNull($dump);
        $this->assertNull($zip);
    }

    /**
     * A path in the payload that no longer names a file is not an answer:
     * the fallback has to be tried rather than a missing file handed to
     * the restorer.
     */
    public function testAPathThatNoLongerExistsIsNotTakenForACopy(): void
    {
        [$dump, $zip] = RestoreBackupHandler::resolveSafetyCopy(
            $this->storagePath . '/parti.sql',
            $this->storagePath . '/parti.zip',
            0,
            new BackupRepository($this->pdo()),
            new FileRepository($this->pdo()),
            $this->storagePath
        );

        $this->assertNull($dump);
        $this->assertNull($zip);
    }

    public function testHalfACopyIsNoCopy(): void
    {
        $safety = $this->safetyCopyOnDisk();

        [$dump, $zip] = RestoreBackupHandler::resolveSafetyCopy(
            $safety['safety_db_dump_path'],
            null,
            0,
            new BackupRepository($this->pdo()),
            new FileRepository($this->pdo()),
            $this->storagePath
        );

        $this->assertNull($dump, 'Restoring a database without its files is not putting anything back.');
        $this->assertNull($zip);
    }

    /**
     * The resumed pass, failing after its migration — which is where the
     * safety copy stops being paperwork and becomes the only way back.
     */
    public function testAResumedPassThatFailsPutsTheInstallationBackFromTheCarriedPaths(): void
    {
        $this->setUnitName('Unité du Chêne');
        $safety = $this->safetyCopyOnDisk();
        $this->makeTheTailOfTheResumedPassFail();

        $this->resume($safety);

        $this->assertSame(
            'Unité du Chêne',
            $this->unitName(),
            'The copy is what the operator is trusting; it has to be usable from this pass.'
        );
        $this->assertContains('backup_restore_rolled_back', $this->journalTypes());
    }

    /**
     * The case the fix exists for, end to end: the row recording the
     * safety copy was replaced by the restore, so there is nothing to
     * look up — and the rollback still works, from the payload alone.
     */
    public function testTheRollbackWorksWithNoRowLeftToLookUp(): void
    {
        $this->setUnitName('Unité du Chêne');
        $safety = $this->safetyCopyOnDisk();
        $this->makeTheTailOfTheResumedPassFail();

        // An id that resolves to nothing: the normal state once the
        // restore has replaced the table the id was minted in.
        $this->resume($safety, 999999);

        $this->assertSame('Unité du Chêne', $this->unitName());
        $this->assertContains('backup_restore_rolled_back', $this->journalTypes());
    }

    public function testWithNeitherPathsNorRowTheOperatorIsToldPlainlyAndLoudly(): void
    {
        $this->makeTheTailOfTheResumedPassFail();

        $this->resume(['safety_db_dump_path' => null, 'safety_zip_path' => null], 999999);

        $failed = array_values(array_filter(
            (new JournalRepository($this->pdo()))->search(),
            static fn (array $e): bool => $e['event_type'] === 'backup_restore_failed'
        ));

        $this->assertNotSame([], $failed, 'Silence here is an installation nobody knows is half-migrated.');
        $this->assertSame('warning', $failed[0]['level'], 'It asks for a human; it is not routine information.');
    }

    /**
     * Makes the resumed pass fail after its migration, in a way that is
     * not contrived: this pass runs against a database the restore has
     * just replaced, and the tail of it walks the older backups to purge
     * them — over the `files` table, which a restored-but-not-yet-migrated
     * schema may well not have.
     */
    private function makeTheTailOfTheResumedPassFail(): void
    {
        $backups = new BackupRepository($this->pdo());
        $files = new FileRepository($this->pdo());

        // More than the five the purge keeps, each pointing at a file, so
        // the purge actually reaches for the table.
        for ($i = 0; $i < 7; $i++) {
            $fileId = $files->create(
                'maintenance/vieux_' . $i . '.zip',
                'sauvegarde.zip',
                'application/zip',
                10,
                'admin',
                null,
                null
            );
            $backups->markCompleted($backups->create('database', null), $fileId, $fileId);
        }

        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo()->exec('DROP TABLE files');
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ── harness ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function restore(array $payload): void
    {
        (new RestoreBackupHandler())->handle($payload, $this->context());
    }

    /**
     * @param array<string, mixed> $safety
     */
    private function resume(array $safety, int $safetyBackupId = 1): void
    {
        (new RestoreBackupHandler())->handle(
            ['resume_migration' => true, 'source' => 'server', 'safety_backup_id' => $safetyBackupId] + $safety,
            $this->context()
        );
    }

    /**
     * A safety copy as the initial attempt leaves one: two real files, and
     * the payload keys that point at them.
     *
     * @return array{safety_db_dump_path: string, safety_zip_path: string}
     */
    private function safetyCopyOnDisk(): array
    {
        $service = new BackupService($this->connection, $this->storagePath, dirname($this->storagePath));

        return [
            'safety_db_dump_path' => $service->createDatabaseDump(),
            'safety_zip_path' => $service->createFileBackup(true),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function journalTypes(): array
    {
        return array_map(
            static fn (array $entry): string => (string) $entry['event_type'],
            (new JournalRepository($this->pdo()))->search()
        );
    }

    /**
     * The payload of the resume pass the handler queues after restoring.
     *
     * @return array<string, mixed>
     */
    private function resumePayload(): array
    {
        $raw = $this->pdo()
            ->query("SELECT payload FROM scheduled_actions WHERE task_key = 'restore_backup'")
            ->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether that pass could actually roll back: it needs the safety
     * copy's two files, on disk, from what the payload carries.
     *
     * @param array<string, mixed> $payload
     */
    private function safetyCopyIsResolvable(array $payload): bool
    {
        $carriedDump = (string) ($payload['safety_db_dump_path'] ?? '');
        $carriedZip = (string) ($payload['safety_zip_path'] ?? '');
        if ($carriedDump !== '' && $carriedZip !== '' && is_file($carriedDump) && is_file($carriedZip)) {
            return true;
        }

        $backup = (new BackupRepository($this->pdo()))->findById((int) ($payload['safety_backup_id'] ?? 0));
        if ($backup === null || $backup->dbDumpFileId === null || $backup->fileId === null) {
            return false;
        }

        $files = new FileRepository($this->pdo());
        $dump = $files->findById($backup->dbDumpFileId);
        $zip = $files->findById($backup->fileId);
        if ($dump === null || $zip === null) {
            return false;
        }

        return is_file($this->storagePath . '/' . $dump->relativePath)
            && is_file($this->storagePath . '/' . $zip->relativePath);
    }

    private function takeServerBackup(): int
    {
        $service = new BackupService($this->connection, $this->storagePath, dirname($this->storagePath));
        $dump = $service->createDatabaseDump();

        $backups = new BackupRepository($this->pdo());
        $files = new FileRepository($this->pdo());

        $backupId = $backups->create('database', null);
        $dumpFileId = $files->create(
            // Relative to the storage path, computed rather than guessed:
            // BackupService stages into storage/maintenance/, and a
            // hand-written 'backups/…' resolved to a file that was not
            // there — the restore then failed and rolled back, which is
            // indistinguishable from the real failure this test is for.
            ltrim(substr($dump, strlen($this->storagePath)), '/'),
            'database.sql',
            'application/sql',
            (int) filesize($dump),
            'admin',
            null,
            null
        );
        $backups->markCompleted($backupId, null, $dumpFileId);

        return $backupId;
    }

    private function setUnitName(string $name): void
    {
        // Through the real SettingService rather than an INSERT: the row
        // has a shape (defaults, type, label) and a fixture that invents
        // one drifts away from the schema this very test restores.
        $settings = new SettingService(new SettingRepository($this->pdo()));
        $settings->register('site_name', '', 'text', "Nom de l'unité", 'Fixture.');
        $settings->set('site_name', $name);
    }

    private function unitName(): string
    {
        $stmt = $this->pdo()->query("SELECT setting_value FROM settings WHERE setting_key = 'site_name'");

        return (string) ($stmt !== false ? $stmt->fetchColumn() : '');
    }

    private function pdo(): \PDO
    {
        return $this->connection->getPdo();
    }

    private function context(): TaskContext
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new TaskContext(
            $this->connection,
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo())),
            new SettingService(new SettingRepository($this->pdo())),
            new UserAccountRepository($this->pdo(), $encryption),
            $this->storagePath
        );
    }

    /**
     * The REAL core schema, loaded from schema/core.sql.
     *
     * Hand-written tables were the first attempt and they were wrong
     * within minutes — a column named `logged_at` in the journal, not
     * `created_at`. That is the wrong kind of wrong for this test in
     * particular: a restore round trip is worth something only if what
     * goes round is the schema the application actually has. So the file
     * that defines it is the fixture, foreign keys deferred while the
     * tables appear in declaration order.
     */
    private function createSchema(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/schema/core.sql');
        // Comment lines only: the statements themselves never contain a
        // literal `--` in this file, and stripping inside strings would be
        // a parser rather than a fixture.
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
