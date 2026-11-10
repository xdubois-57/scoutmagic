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
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Task\RestoreBackupHandler;
use Core\Maintenance\Task\SendRemoteBackupHandler;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

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
 * real MySQL server (reached through `UsesProductionEngine`, so skipped only
 * where none was promised) and, crucially, against **its own throwaway
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
    use UsesProductionEngine;

    private ?Connection $connection = null;
    private string $database = '';
    private string $storagePath = '';
    private ?\PDO $server = null;

    protected function setUp(): void
    {
        // Connected to `TEST_DB_NAME` only to create and drop the database
        // below; nothing is read or written there.
        $server = self::productionEngineConnection()->getPdo();

        // Its own database, per test: a restore drops and reloads a
        // schema, and doing that to the suite's shared fixture mid-run
        // would break whatever test happened to come next. Created here
        // rather than taken from `productionEngine()` because a restore
        // replaces the tables that one would empty between tests, and
        // because what it holds is this file's own subject — see
        // createSchema().
        $this->database = 'scoutmagic_restore_' . bin2hex(random_bytes(6));
        $server->exec('CREATE DATABASE `' . $this->database . '`');
        $this->server = $server;

        $this->connection = self::productionEngineConnection($this->database);

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

    // ── « Configuration seule » (#847, part 2) ────────────────────────

    /**
     * The settings and the modules' enabled state come back; the members,
     * everything else, the schema's own version and the off-site
     * destination stay as they are today; a task queued against today's
     * data is kept.
     */
    public function testAConfigurationRestoreBringsBackOnlyTheConfiguration(): void
    {
        $settings = new SettingService(new SettingRepository($this->pdo()));
        $settings->register('backup_remote_location_id', '', 'text', 'Destination', 'Fixture.');
        $this->setUnitName('Unité du Chêne');
        $settings->set('backup_remote_location_id', '3');
        $this->pdo()->exec("INSERT INTO module_registry (module_id, enabled, installed_version) VALUES ('news', 1, '1.0.0')");
        $backupId = $this->takeEncryptedServerBackup('full_config');

        $this->setUnitName('Unité renommée par erreur');
        $settings->set('backup_remote_location_id', '9');
        $this->pdo()->exec("UPDATE module_registry SET enabled = 0, installed_version = '2.0.0' WHERE module_id = 'news'");
        $this->pdo()->exec("INSERT INTO members (desk_id) VALUES ('APRES-LA-SAUVEGARDE')");
        $this->pdo()->exec(
            "INSERT INTO scheduled_actions (module_id, task_key, run_at, status, created_at)
             VALUES ('core', 'send_digest', NOW(), 'pending', NOW())"
        );

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);
        $this->runResumedPasses();

        $this->assertSame('Unité du Chêne', $this->unitName());
        $this->assertSame(
            ['1', '2.0.0'],
            array_map('strval', (array) $this->pdo()->query(
                "SELECT enabled, installed_version FROM module_registry WHERE module_id = 'news'"
            )->fetch(\PDO::FETCH_NUM)),
            'the enabled state comes back, the installed version never'
        );
        $this->assertSame(
            '9',
            (string) $this->pdo()
                ->query("SELECT setting_value FROM settings WHERE setting_key = 'backup_remote_location_id'")
                ->fetchColumn(),
            'the off-site destination is this server\'s, kept as it is today'
        );
        $this->assertSame(
            1,
            (int) $this->pdo()->query("SELECT COUNT(*) FROM members WHERE desk_id = 'APRES-LA-SAUVEGARDE'")->fetchColumn(),
            'a configuration restore emptied the site'
        );
        $this->assertSame(
            'pending',
            (string) $this->pdo()->query("SELECT status FROM scheduled_actions WHERE task_key = 'send_digest'")->fetchColumn()
        );
    }

    /** A module the restore switches off loses its queued tasks, counted in the journal. */
    public function testAModuleSwitchedOffByAConfigurationRestoreLosesItsQueuedTasks(): void
    {
        $this->pdo()->exec("INSERT INTO module_registry (module_id, enabled, installed_version) VALUES ('news', 0, '1.0.0')");
        $backupId = $this->takeEncryptedServerBackup('full_config');
        $this->pdo()->exec("UPDATE module_registry SET enabled = 1 WHERE module_id = 'news'");
        $this->pdo()->exec(
            "INSERT INTO scheduled_actions (module_id, task_key, run_at, status, created_at)
             VALUES ('news', 'publish_scheduled', NOW(), 'pending', NOW())"
        );

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);

        $this->assertSame(
            'canceled',
            (string) $this->pdo()->query("SELECT status FROM scheduled_actions WHERE task_key = 'publish_scheduled'")->fetchColumn()
        );
        $journal = (new JournalRepository($this->pdo()))->search();
        $canceled = array_values(array_filter($journal, static fn(array $e): bool => $e['event_type'] === 'restore_tasks_canceled'));
        $this->assertCount(1, $canceled);
        $this->assertSame(['news/publish_scheduled' => 1], json_decode((string) $canceled[0]['context'], true)['tasks']);
    }

    /** A « configuration » dump that carries the rows of another table is not guessed at. */
    public function testAConfigurationDumpCarryingOtherDataIsRefused(): void
    {
        $this->setUnitName('Unité du Chêne');
        $dump = $this->storagePath . '/backups/config.sql';
        file_put_contents($dump, implode("\n", [
            'CREATE TABLE `settings` (`id` int, `module_id` varchar(50), `setting_key` varchar(100), `setting_value` text);',
            "INSERT INTO `settings` VALUES (1,NULL,'site_name','Autre nom');",
            "INSERT INTO `members` VALUES (1,'X');",
        ]));

        try {
            (new \Core\Maintenance\ConfigOnlyRestore($this->pdo()))->apply($dump);
            $this->fail('the dump was applied');
        } catch (\Core\Maintenance\BackupException $e) {
            $this->assertStringContainsString('contient d\'autres données que la configuration', $e->getMessage());
        }

        $this->assertSame('Unité du Chêne', $this->unitName());
    }

    public function testARestoreCannotReviveEitherCurrentOrArchivedSessions(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $account = (new UserAccountRepository($this->pdo(), $encryption))->create('same@example.com', true);
        $generation = \Tests\SessionGenerationFixture::forPdo($this->pdo());
        \Core\Security\AuthSession::login($account->id, $account->email, 'superadmin', $generation->current());
        $archivedBrowser = $_SESSION;
        $backupId = $this->takeServerBackup();

        $generation->rotate();
        \Core\Security\AuthSession::login($account->id, $account->email, 'superadmin', $generation->current());
        $currentBrowser = $_SESSION;
        $this->restore(['source' => 'server', 'backup_id' => $backupId]);

        // The scheduler has not run the deferred migration pass yet.
        $betweenPasses = new \Core\Security\SessionRevalidator(
            new UserAccountRepository($this->pdo(), $encryption),
            new \Core\Security\RoleResolver(new \Core\Import\MemberYearRepository($this->pdo()), $encryption, $this->pdo()),
            \Tests\SessionGenerationFixture::forPdo($this->pdo())
        );
        $survivedBetweenPasses = [];
        foreach ([$currentBrowser, $archivedBrowser] as $browser) {
            $_SESSION = $browser;
            $survivedBetweenPasses[] = $betweenPasses->revalidate(static fn() => new \Core\ScoutYear\AuthorizationYears(1, [1]));
        }
        $this->assertSame([false, false], $survivedBetweenPasses);
        $this->runResumedPasses();

        $currentGeneration = \Tests\SessionGenerationFixture::forPdo($this->pdo());
        $revalidator = new \Core\Security\SessionRevalidator(
            new UserAccountRepository($this->pdo(), $encryption),
            new \Core\Security\RoleResolver(new \Core\Import\MemberYearRepository($this->pdo()), $encryption, $this->pdo()),
            $currentGeneration
        );
        $survived = [];
        foreach ([$currentBrowser, $archivedBrowser] as $browser) {
            $_SESSION = $browser;
            $survived[] = $revalidator->revalidate(static fn() => new \Core\ScoutYear\AuthorizationYears(1, [1]));
        }
        \Core\Security\AuthSession::login($account->id, $account->email, 'superadmin', $currentGeneration->current());
        $survived[] = $revalidator->revalidate(static fn() => new \Core\ScoutYear\AuthorizationYears(1, [1]));

        $this->assertSame([false, false, true], $survived);
        $_SESSION = [];
    }

    /**
     * An encrypted archive of this server restores with nobody typing its
     * password (issue #619, IT-03): the site kept the one it generated.
     */
    public function testAnEncryptedArchiveOfThisServerRestoresWithItsKeptPassword(): void
    {
        $this->setUnitName('Unité du Chêne');
        $backupId = $this->takeEncryptedServerBackup();

        $this->setUnitName('Unité renommée par erreur');

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);

        $this->assertSame('Unité du Chêne', $this->unitName());
    }

    /**
     * An encrypted archive whose password is no longer kept is refused
     * before the database is touched — not restored, then rolled back
     * when the files will not extract.
     */
    public function testAnEncryptedArchiveWithoutItsKeptPasswordIsRefusedBeforeAnythingIsRestored(): void
    {
        $backupId = $this->takeEncryptedServerBackup();
        \Core\Maintenance\BackupPasswords::forStorage($this->storagePath)->forget($backupId);
        $context = $this->context();

        // Asked of the resolution step itself: through handle(), the
        // rollback that follows any failure restores the event log too, and
        // with it every trace of which step refused.
        $resolve = new \ReflectionMethod(RestoreBackupHandler::class, 'resolveSource');

        $this->expectException(\Core\Maintenance\BackupException::class);
        $this->expectExceptionMessage('plus conservé sur ce serveur');
        $resolve->invoke(
            new RestoreBackupHandler(),
            ['source' => 'server', 'backup_id' => $backupId],
            $this->pdo(),
            $this->storagePath,
            $context->encryption
        );
    }

    /**
     * **A database-only archive is encrypted like the others since IT-04**,
     * so without its kept password it is refused before the database is
     * touched — not restored, then rolled back at extraction.
     */
    public function testADatabaseArchiveWithoutItsKeptPasswordIsRefusedBeforeAnythingIsRestored(): void
    {
        $backupId = $this->takeEncryptedServerBackup('database');
        \Core\Maintenance\BackupPasswords::forStorage($this->storagePath)->forget($backupId);

        $this->expectException(\Core\Maintenance\BackupException::class);
        $this->expectExceptionMessage('plus conservé sur ce serveur');
        $this->resolveServerSource($backupId);
    }

    /**
     * #847: an archive the backup declares and that is gone is refused —
     * restoring the database alone would put back a date's database with
     * today's files.
     */
    public function testADeclaredArchiveThatIsGoneIsRefusedRatherThanSkipped(): void
    {
        $backupId = $this->takeEncryptedServerBackup('full_no_gallery');
        $backup = (new BackupRepository($this->pdo()))->findById($backupId);
        unlink($this->storagePath . '/' . (new FileRepository($this->pdo()))->findById((int) $backup?->fileId)?->relativePath);

        $this->expectException(\Core\Maintenance\BackupException::class);
        $this->expectExceptionMessage('L\'archive de cette sauvegarde n\'est plus sur ce serveur');
        $this->resolveServerSource($backupId);
    }

    /**
     * **An archive written in clear on a host that cannot encrypt restores
     * with no password** (IT-04): none was issued, none is kept, and the
     * archive itself — not its type — says there is nothing to decrypt.
     */
    public function testAnArchiveWrittenInClearRestoresWithoutAnyPassword(): void
    {
        $this->setUnitName('Unité du Chêne');
        $backupId = $this->takeEncryptedServerBackup('full_config', false);

        $this->assertNull($this->resolveServerSource($backupId)[2], 'a password was asked of a clear archive');

        $this->setUnitName('Unité renommée par erreur');
        $this->restore(['source' => 'server', 'backup_id' => $backupId]);

        $this->assertSame('Unité du Chêne', $this->unitName());
    }

    /**
     * The resolution step alone — through handle(), the rollback after a
     * failure restores the event log too, and every trace of which step
     * refused.
     *
     * @return array<int, mixed>
     */
    private function resolveServerSource(int $backupId): array
    {
        $resolve = new \ReflectionMethod(RestoreBackupHandler::class, 'resolveSource');
        $source = $resolve->invoke(
            new RestoreBackupHandler(),
            ['source' => 'server', 'backup_id' => $backupId],
            $this->pdo(),
            $this->storagePath,
            $this->context()->encryption
        );
        $this->assertIsArray($source);

        return $source;
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

    // ── today's state (#847, part 3) ──────────────────────────────────

    /**
     * The list of backups is this server's, not the restored date's: a
     * backup taken after the restored one stays, the copy taken just
     * before the restore is listed under its own type, and the backup
     * restored from is still there.
     */
    public function testTheBackupListAfterARestoreIsTodays(): void
    {
        $backupId = $this->takeServerBackup();
        $laterId = $this->takeServerBackup();

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);
        $this->runResumedPasses();

        $types = $this->pdo()->query('SELECT id, type FROM backups ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertArrayHasKey($backupId, $types);
        $this->assertArrayHasKey($laterId, $types, 'a backup taken after the restored one must not vanish');
        $this->assertContains(RestoreBackupHandler::SAFETY_TYPE, $types, 'the copy taken before the restore');
        $this->assertContains('restore_state_kept', $this->journalTypes());
        $restored = (new JournalRepository($this->pdo()))->findLatestEvent('core', 'backup_restored');
        $this->assertIsArray($restored);
        $this->assertMatchesRegularExpression(
            '~^\d{1,2} \p{L}+ \d{4} à \d{2}:\d{2}$~u',
            (string) (json_decode((string) $restored['context'], true)['restored_from'] ?? ''),
            'the date the site went back to, for the message after the restore'
        );

        // And each row still points at its files, and those exist: the
        // backup restored from, the later one and the safety copy each
        // declare a dump.
        $files = new FileRepository($this->pdo());
        foreach ((new BackupRepository($this->pdo()))->findAllNewestFirst() as $backup) {
            $this->assertNotNull($backup->dbDumpFileId, 'backup ' . $backup->id . ' lost its dump');
            foreach ([$backup->fileId, $backup->dbDumpFileId] as $fileId) {
                if ($fileId !== null) {
                    $file = $files->findById($fileId);
                    $this->assertNotNull($file, 'backup ' . $backup->id . ' lost its files row');
                    $this->assertFileExists($this->storagePath . '/' . $file->relativePath);
                }
            }
        }
        $this->assertSame([], glob($this->storagePath . '/temp/restore_state_*') ?: []);
    }

    /** The incident that motivated the restore stays in the journal, before the restore's own lines. */
    public function testTheJournalKeepsTodaysLinesAndTheRestoresOwn(): void
    {
        $backupId = $this->takeServerBackup();
        $this->context()->journal->log('core', 'incident_du_jour', 'error', 'Le problème qui motive la restauration');

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);
        $this->runResumedPasses();

        $ids = $this->pdo()->query('SELECT event_type, MIN(id) FROM event_log GROUP BY event_type')
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertArrayHasKey('incident_du_jour', $ids);
        $this->assertArrayHasKey('backup_restored', $ids);
        $this->assertArrayHasKey('restore_tasks_reset', $ids, 'a line the restore wrote before the put-back');
        $this->assertLessThan($ids['restore_tasks_reset'], $ids['incident_du_jour']);
        $this->assertLessThan($ids['backup_restored'], $ids['restore_tasks_reset']);
    }

    /** The off-site destination chosen after the backup is still the one in use. */
    public function testTheOffSiteDestinationStaysAsToday(): void
    {
        $this->declareDrive(2, 'dossier-hors-site');
        $settings = new SettingService(new SettingRepository($this->pdo()));
        $settings->register(RemoteBackupDestination::LOCATION_SETTING, '', 'text', 'Destination', 'Fixture.');
        $backupId = $this->takeServerBackup();
        $settings->set(RemoteBackupDestination::LOCATION_SETTING, '2');

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);
        $this->runResumedPasses();

        $this->assertSame(
            '2',
            (string) $this->pdo()->query(
                "SELECT setting_value FROM settings WHERE setting_key = '"
                    . RemoteBackupDestination::LOCATION_SETTING . "'"
            )->fetchColumn()
        );
        // And a copy of the restored state leaves now, not at the next slot.
        $this->assertSame(
            1,
            (int) $this->pdo()->query(
                "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = '" . SendRemoteBackupHandler::TASK_KEY . "'
                 AND status = 'pending' AND run_at <= NOW() + INTERVAL 1 MINUTE"
            )->fetchColumn()
        );
    }

    /** A restore that fails sends nothing: the site is as it was. */
    public function testNoOffSiteSendFollowsAFailedRestore(): void
    {
        $settings = new SettingService(new SettingRepository($this->pdo()));
        $settings->register(RemoteBackupDestination::LOCATION_SETTING, '2', 'text', 'Destination', 'Fixture.');
        $backupId = $this->takeServerBackup();

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);
        @unlink((string) $this->resumePayload()['restore_state_hold']);
        $this->runResumedPasses();

        $this->assertSame(
            0,
            (int) $this->pdo()->query(
                "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = '" . SendRemoteBackupHandler::TASK_KEY . "'
                 AND status = 'pending'"
            )->fetchColumn()
        );
    }

    /**
     * Nothing of the restored date keeps running: its chains are re-armed
     * fresh, with an empty payload, and its one-shot tasks are dropped —
     * all of it said in the journal.
     */
    public function testTheTasksOfTheRestoredDateStartFromACleanState(): void
    {
        $this->pdo()->exec(
            "INSERT INTO scheduled_actions (module_id, task_key, reference, payload, run_at, status) VALUES
             ('core', 'chaine_du_jour', 'auto', '{\"curseur\": 42}', '2030-01-01 03:00:00', 'processing'),
             ('core', 'tache_ponctuelle', NULL, NULL, '2030-01-01 03:00:00', 'pending')"
        );
        $backupId = $this->takeServerBackup();

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);
        $this->runResumedPasses();

        $live = $this->pdo()->query(
            "SELECT task_key, reference, payload, run_at, status FROM scheduled_actions
             WHERE status IN ('pending', 'processing') AND task_key <> '" . SendRemoteBackupHandler::TASK_KEY . "'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $live);
        $this->assertSame('chaine_du_jour', $live[0]['task_key']);
        $this->assertSame('pending', $live[0]['status']);
        $this->assertNull($live[0]['payload']);
        $this->assertSame('2030-01-01 03:00:00', $live[0]['run_at']);
        $this->assertContains('restore_tasks_reset', $this->journalTypes());
    }

    /**
     * The id of the running restore is not handed to another row: the
     * scheduler marks that id done once the pass returns, and a resume
     * pass or a chain under it would be marked done without ever running.
     */
    public function testTheRunningRestoreKeepsItsOwnRow(): void
    {
        $backupId = $this->takeServerBackup();
        $this->pdo()->exec(
            "INSERT INTO scheduled_actions (module_id, task_key, run_at, status)
             VALUES ('core', 'restore_backup', NOW(), 'processing')"
        );
        $runningId = (int) $this->pdo()->lastInsertId();

        $this->restore(['source' => 'server', 'backup_id' => $backupId, 'scheduled_action_id' => $runningId]);

        $row = $this->pdo()->query("SELECT task_key, status, payload FROM scheduled_actions WHERE id = {$runningId}")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('processing', $row['status']);
        $this->assertNull($row['payload'], 'this is the running row, not the resume pass');
        $this->assertNotSame([], $this->resumePayload());
    }

    /**
     * The put-back cannot fail quietly: without it the list would be the
     * restored date's and the safety copy gone from it. The restore rolls
     * back, the journal says why, and the copy is listed.
     */
    public function testAPutBackThatFailsRollsTheRestoreBack(): void
    {
        $this->setUnitName('Unité du Chêne');
        $backupId = $this->takeServerBackup();
        $this->setUnitName('Unité d\'aujourd\'hui');

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);
        @unlink((string) $this->resumePayload()['restore_state_hold']);
        $this->runResumedPasses();

        $this->assertSame('Unité d\'aujourd\'hui', $this->unitName());
        $types = $this->journalTypes();
        $this->assertContains('restore_state_keep_failed', $types);
        $this->assertContains('backup_restore_rolled_back', $types);
        $this->assertNotContains('backup_restored', $types);
        $this->assertSame(
            1,
            (int) $this->pdo()->query(
                "SELECT COUNT(*) FROM backups WHERE type = '" . RestoreBackupHandler::SAFETY_TYPE . "'"
            )->fetchColumn()
        );
    }

    /** A send under way stops before the safety copy: its local archive goes, and the journal says so. */
    public function testAnOffSiteSendUnderWayIsInterrupted(): void
    {
        $archive = $this->storagePath . '/maintenance/hors_site_en_cours.zip';
        @mkdir(dirname($archive), 0o777, true);
        file_put_contents($archive, 'archive portable');
        $this->pdo()->prepare(
            "INSERT INTO scheduled_actions (module_id, task_key, reference, payload, run_at, status)
             VALUES ('core', ?, 'auto', ?, NOW(), 'pending')"
        )->execute([
            SendRemoteBackupHandler::TASK_KEY,
            json_encode(['archive_path' => $archive, 'remote_name' => 'copie.zip', 'location_id' => 0]),
        ]);
        $backupId = $this->takeServerBackup();

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);

        $this->assertFileDoesNotExist($archive);
        $this->runResumedPasses();
        $this->assertContains('remote_backup_interrupted', $this->journalTypes());
    }

    /**
     * A restore that fails rolls back to a dump taken before its safety
     * copy's row existed — the row comes back, so the copy stays listed
     * and purgeable.
     */
    public function testARestoreThatFailsKeepsItsSafetyCopyListed(): void
    {
        $upload = $this->storagePath . '/corrompu.zip';
        $zip = new \ZipArchive();
        $zip->open($upload, \ZipArchive::CREATE);
        $zip->addFromString('database.sql', 'CECI N\'EST PAS DU SQL;');
        $zip->close();

        $this->restore(['source' => 'upload', 'uploaded_temp_path' => $upload]);

        $this->assertContains('backup_restore_rolled_back', $this->journalTypes());
        $safety = $this->pdo()->query(
            "SELECT file_id, db_dump_file_id FROM backups WHERE type = '" . RestoreBackupHandler::SAFETY_TYPE . "'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($safety, 'the safety copy is on the disk and must be in the list');
        $this->assertNotNull($safety['file_id']);
        $this->assertSame([], glob($this->storagePath . '/temp/restore_state_*') ?: []);
    }

    // ── the storage bindings (#832) ───────────────────────────────────

    /**
     * The ticket's case, end to end: the Drive folder changed after the
     * backup, the files are only in the new one, and the backup brings
     * back the old folder id. After the restore and its resumed pass, the
     * location points where the files are — and the journal says what was
     * kept.
     */
    public function testAStorageLocationChangedAfterTheBackupKeepsItsCurrentBinding(): void
    {
        $this->declareDrive(2, 'dossier-ancien');
        $backupId = $this->takeServerBackup();
        $this->pdo()->prepare('UPDATE storage_locations SET config = ? WHERE id = 2')
            ->execute([$this->driveConfig('dossier-actuel')]);

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);
        $payload = $this->resumePayload();
        $hold = (string) ($payload['storage_bindings_hold'] ?? '');
        $this->assertFileExists($hold, 'today\'s bindings must be on disk before the database is replaced');

        $this->runResumedPasses();

        $config = (string) $this->pdo()->query('SELECT config FROM storage_locations WHERE id = 2')->fetchColumn();
        $this->assertStringContainsString('dossier-actuel', $config);
        $this->assertContains('storage_bindings_reconciled', $this->journalTypes());
        $this->assertContains('backup_restored', $this->journalTypes());
        $this->assertFileDoesNotExist($hold);
    }

    /** Created after the backup: its files must stay reachable. */
    public function testAStorageLocationCreatedAfterTheBackupSurvivesTheRestore(): void
    {
        $backupId = $this->takeServerBackup();
        $this->declareDrive(7, 'dossier-recent');

        $this->restore(['source' => 'server', 'backup_id' => $backupId]);
        $this->runResumedPasses();

        $row = $this->pdo()->query('SELECT config, is_default, reconciliation_note FROM storage_locations WHERE id = 7')
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertStringContainsString('dossier-recent', (string) $row['config']);
        $this->assertSame(0, (int) $row['is_default']);
        $this->assertNotNull($row['reconciliation_note']);
    }

    /**
     * A restore that fails rolls back to the database the bindings were
     * read from: nothing to reconcile, and nothing left on the disk.
     */
    public function testARestoreThatFailsLeavesNoBindingsBehind(): void
    {
        $this->declareDrive(2, 'dossier-actuel');

        $upload = $this->storagePath . '/corrompu.zip';
        $zip = new \ZipArchive();
        $zip->open($upload, \ZipArchive::CREATE);
        $zip->addFromString('database.sql', 'CECI N\'EST PAS DU SQL;');
        $zip->close();

        $this->restore(['source' => 'upload', 'uploaded_temp_path' => $upload]);

        $this->assertSame([], glob($this->storagePath . '/temp/storage_bindings_*.json') ?: []);
        $config = (string) $this->pdo()->query('SELECT config FROM storage_locations WHERE id = 2')->fetchColumn();
        $this->assertStringContainsString('dossier-actuel', $config);
    }

    /**
     * The migration works under a time budget and, when it runs out,
     * schedules another pass — on a slow machine, more than one. The
     * bindings are put back on the last one only, so run them all.
     */
    private function runResumedPasses(): void
    {
        for ($pass = 0; $pass < 20; $pass++) {
            $payload = $this->resumePayload();
            if ($payload === []) {
                return;
            }
            $this->pdo()->exec("DELETE FROM scheduled_actions WHERE task_key = 'restore_backup'");
            (new RestoreBackupHandler())->handle($payload, $this->context());
        }
        $this->fail('The restore never finished its resumed passes.');
    }

    private function declareDrive(int $id, string $folder): void
    {
        $this->pdo()->prepare(
            "INSERT INTO storage_locations (id, type, label, is_default, config, secret_encrypted)
             VALUES (?, 'google_drive', ?, 0, ?, ?)"
        )->execute([
            $id,
            'Drive ' . $id,
            $this->driveConfig($folder),
            $this->context()->encryption->encrypt('grant', 'storage_locations.secret'),
        ]);
    }

    private function driveConfig(string $folder): string
    {
        return (string) json_encode(['client_id' => 'c', 'folder_id' => $folder, 'connected_at' => '2026-09-01']);
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
     * Since IT-03b (issue #619) the safety copy is encrypted like every
     * archive, and the rollback finds its password by the copy's id in
     * secrets.enc — which no restore replaces. A rollback that could not
     * open its own copy would journal `backup_restore_rollback_failed`.
     */
    public function testARestoreThatFailsRollsBackFromAnEncryptedSafetyCopy(): void
    {
        $this->initialiseSecrets();
        $this->setUnitName('Unité du Chêne');

        $upload = $this->storagePath . '/corrompu.zip';
        $zip = new \ZipArchive();
        $zip->open($upload, \ZipArchive::CREATE);
        $zip->addFromString('database.sql', 'CECI N\'EST PAS DU SQL;');
        $zip->close();

        $this->restore(['source' => 'upload', 'uploaded_temp_path' => $upload]);

        $this->assertSame('Unité du Chêne', $this->unitName());
        $this->assertContains('backup_restore_rolled_back', $this->journalTypes());
        $this->assertNotContains('backup_restore_rollback_failed', $this->journalTypes());

        // And the copy on disk really is encrypted.
        $copies = glob($this->storagePath . '/maintenance/files_*.zip') ?: [];
        $this->assertNotSame([], $copies);
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($copies[0]));
        $this->assertSame(\ZipArchive::EM_AES_256, $archive->statIndex(0)['encryption_method']);
        $archive->close();
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

        // More than the purge keeps of the family a restore's own purge
        // touches — 'auto_restore', the safety copy this pass takes — each
        // pointing at a file, so the purge actually reaches for the table.
        // The family matters since IT-04: a purge triggered by an
        // operational backup no longer walks the manual ones, so seven
        // 'database' rows here would leave the tail with nothing to do and
        // this test with nothing to observe.
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
            $backups->markCompleted($backups->create('auto_reset', null), $fileId, $fileId);
        }

        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo()->exec('DROP TABLE files');
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * The restore's own safety copy is DECLARED before anything else runs.
     *
     * A behavioural assertion is not available here, and the reason is
     * itself worth writing down: the pass rolls back from that very copy
     * when it fails, which restores the database and takes the `backups`
     * row and the queue row with it. So by the time a test can look,
     * whatever it wanted to observe has been consumed by the mechanism it
     * was observing.
     *
     * What matters is therefore pinned in two halves. That
     * `rememberInPayload()` makes a copy visible to
     * `Core\Maintenance\BackupSafetyNet` is proved behaviourally in
     * `Tests\Core\Maintenance\BackupSafetyNetTest`. That THIS handler
     * calls it, on its own task, in the same breath as marking the copy
     * complete, is proved here — textually, in the manner of
     * `Tests\Core\Storage\DiskBudgetWiringTest` and for the same reason:
     * a call site that goes missing fails nothing, logs nothing, and
     * leaves the only rollback point of a running restore deletable from
     * a list of dates.
     */
    public function testTheSafetyCopyIsDeclaredInTheRunningTasksOwnPayload(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/core/Maintenance/Task/RestoreBackupHandler.php'
        );

        // The completion call moved behind Core\Maintenance\BackupIntegrity
        // in IT-05, so that no site can complete a backup without recording
        // the digests that let it be verified later. What this test pins is
        // unchanged: the copy is declared in the running task's payload the
        // moment it exists.
        $marked = strpos($source, '->complete($safetyBackupId');
        $declared = strpos($source, 'rememberInPayload(');

        $this->assertIsInt($marked, 'The safety copy is no longer completed under that name.');
        $this->assertIsInt(
            $declared,
            'RestoreBackupHandler no longer declares its safety copy in its own payload. Until it does, that copy '
                . 'is a completed row like any other — listed with a working « Supprimer » button — for the whole '
                . 'time the restore runs, and it is the only thing the rollback can restore from.'
        );
        $this->assertGreaterThan($marked, $declared, 'It can only be declared once it exists.');

        // In the same breath, not merely somewhere later: everything
        // between those two points is time the copy spends deletable, and
        // step 2 of the restore begins a few lines below.
        $between = substr($source, $marked, $declared - $marked);
        $this->assertStringNotContainsString(
            'try {',
            $between,
            'The declaration has drifted past the start of the restore itself.'
        );

        $this->assertStringContainsString('$payload[\'scheduled_action_id\']', $source);
        $this->assertStringContainsString('\'safety_backup_id\' => $safetyBackupId', $source);
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
            'safety_zip_path' => $service->createFileBackup(),
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
            ->query("SELECT payload FROM scheduled_actions WHERE task_key = 'restore_backup' AND status = 'pending'")
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

        // A full backup's row with its dump alone: what these tests restore
        // is the database. Not 'database', an export that is never
        // restored onto this server since #847.
        $backupId = $backups->create('full_no_gallery', null);
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

    /** A secrets file to keep archive passwords in, as every installed site has. */
    private function initialiseSecrets(): void
    {
        $secrets = new \Core\Security\SecretManager(
            $this->storagePath . '/keys/master.key',
            $this->storagePath . '/config/secrets.enc'
        );
        @mkdir($this->storagePath . '/config', 0o700, true);
        $secrets->generateMasterKey();
        $secrets->writeSecrets([]);
    }

    /**
     * An archive of the given scope, encrypted with a generated, kept
     * password — or, with `$encrypted` false, written in clear with no
     * password kept, as a host that cannot encrypt writes it (IT-04).
     */
    private function takeEncryptedServerBackup(string $scope = 'full_config', bool $encrypted = true): int
    {
        $this->initialiseSecrets();

        $backups = new BackupRepository($this->pdo());
        $files = new FileRepository($this->pdo());
        $backupId = $backups->create($scope, null);
        $password = $encrypted
            ? \Core\Maintenance\BackupPasswords::forStorage($this->storagePath)->issue($backupId)
            : null;

        $service = new BackupService($this->connection, $this->storagePath, dirname($this->storagePath));
        $result = $service->createFullBackup($scope, $password);

        $register = fn(string $path, string $name, string $mime): int => $files->create(
            ltrim(substr($path, strlen($this->storagePath)), '/'),
            $name,
            $mime,
            (int) filesize($path),
            'admin',
            null,
            null
        );
        $backups->markCompleted(
            $backupId,
            $register($result['zipPath'], 'backup.zip', 'application/zip'),
            $register($result['dbDumpPath'], 'database.sql', 'application/sql')
        );

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
