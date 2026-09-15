<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Remote\RemoteRetention;
use Core\Maintenance\Task\SendRemoteBackupHandler;
use Core\Storage\Location\Backend\ResumableUploadBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\StorageListing;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use Core\Storage\Location\StoredObject;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\RefusingSettingService;
use Tests\Core\Storage\Location\Backend\RefusingBackend;
use Tests\DatabaseTestHelper;

/**
 * The recurring send, run against a destination that is not there.
 *
 * **Not one byte of this suite crosses the network.** The destination
 * sits behind {@see ResumableUploadBackend} — since IT-05 the same
 * contract a local folder and a bucket keep — and `RecordingBackend`
 * below answers as one would: it holds what it has been given, resumes
 * from it, and refuses on demand, without a Google account, a consent
 * screen or a project. A suite that could only exercise the real thing
 * would exercise nothing.
 *
 * **What survives between runs is no longer in the payload.** It used to
 * be a session URI, a byte offset and a flag saying whether the offset
 * could be trusted; now the destination is asked
 * ({@see ResumableUploadBackend::partialSize()}) and the payload carries
 * only which archive is being sent. So the assertions below read the
 * DESTINATION's state where they used to read the queue row — which is
 * the point of the change, not a detail of it.
 *
 * What each test drives is one run of the handler; a send that spans
 * three runs is three calls, each fed the payload the previous one left
 * in `scheduled_actions`. That is not a simplification of production —
 * it IS production, since a run's only memory of the last one is that
 * row.
 *
 * **No `@group database`, on purpose.** Nothing here builds an archive —
 * every test is fed one a previous run would have left — so nothing here
 * needs `mysqldump` or a server. What does need one is the naming, and
 * that lives in {@see SendRemoteBackupArchiveTest} next door, which
 * builds a real portable archive against MariaDB.
 */
final class SendRemoteBackupHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $basePath;
    private string $storagePath;
    private RefusingSettingService $settings;
    private ?BreakableJournalService $journal = null;
    private SecretManager $secrets;
    private SchedulerRepository $scheduler;
    private StorageLocationRepository $locations;
    private RemoteBackupDestination $destination;

    /** The key every archive in this suite is sent under. */
    private const REMOTE_NAME = 'scoutmagic-2026-03-03-120000-g1.zip';

    /** Seconds on the fake clock, advanced by the destination as it accepts. */
    private float $clock = 1000.0;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->scheduler = new SchedulerRepository($this->pdo);

        // `storage/` INSIDE a base path, because that is what the handler
        // assumes: it derives the site's root as `dirname(storagePath)` to
        // hand to `BackupService`, and a storage directory sitting loose in
        // the system temp folder would make the one test that really builds
        // an archive walk /tmp.
        $this->basePath = sys_get_temp_dir() . '/remote_send_' . uniqid();
        $this->storagePath = $this->basePath . '/storage';
        foreach (['storage/keys', 'storage/config', 'storage/maintenance', 'core', 'public', 'modules'] as $dir) {
            @mkdir($this->basePath . '/' . $dir, 0755, true);
        }
        file_put_contents($this->basePath . '/core/App.php', '<?php // app');
        file_put_contents($this->basePath . '/public/index.php', '<?php // entry');

        $this->secrets = new SecretManager(
            $this->storagePath . '/keys/master.key',
            $this->storagePath . '/config/secrets.enc'
        );
        $this->secrets->generateMasterKey();
        // `secrets.enc` has to exist before anything reads it: SecretManager
        // refuses to write one key over a file it cannot read, precisely so
        // that connecting a destination never erases the SMTP password.
        $this->secrets->writeSecrets(['smtp_password' => 'le-mot-de-passe-smtp']);

        $this->settings = new RefusingSettingService();
        $this->locations = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->destination = new RemoteBackupDestination(
            $this->settings,
            $this->locations,
            new StorageBackendFactory($this->locations, $this->storagePath)
        );
        $this->connect();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }

    // ---------------------------------------------------------------
    // Resuming
    // ---------------------------------------------------------------

    /**
     * **The whole reason this task is chunked.** A site on shared hosting
     * gets thirty seconds a run and has gibibytes to send; a run that had
     * to restart from zero would never finish, and each attempt would
     * spend the whole budget re-sending what the destination already
     * holds.
     */
    public function testASecondRunResumesWhereTheFirstStoppedAndResendsNothing(): void
    {
        $archive = $this->archiveOf(300);
        $backend = new RecordingBackend($this);
        $backend->chunkBytes = 100;
        $backend->secondsPerChunk = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;

        $payload = $this->payloadFor($archive);
        $this->runOnce($payload, $backend);

        $next = $this->pending();
        $this->assertNotNull($next, 'the chain stopped after one run — the rest would never go');
        $this->assertSame(100, $backend->partials[self::REMOTE_NAME], 'the destination did not keep what arrived');
        $this->assertSame($archive, $next['payload']['archive_path']);

        $this->runOnce($next['payload'], $backend);

        $this->assertSame(
            [0, 100],
            $backend->startedAt,
            'a run began at an offset the destination had already taken — those bytes went twice'
        );
        $this->assertSame(1, $backend->sessionsOpened, 'the second run opened a new transfer instead of resuming');
    }

    /**
     * **Where the transfer stands is the destination's to say, and the
     * payload no longer carries a second opinion.**
     *
     * A run can hand over eight mebibytes and have the destination commit
     * one, so « what I sent » and « what it holds » are different numbers
     * and only the second is safe to resume from. Under the old payload
     * that difference had to be guessed at and carried in a flag; now it
     * is asked, once per run, of the only thing that knows.
     */
    public function testWhereToResumeComesFromTheDestinationAndNotFromThePayload(): void
    {
        $archive = $this->archiveOf(300);
        $backend = new RecordingBackend($this);
        $backend->chunkBytes = 100;
        $backend->secondsPerChunk = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;
        // A previous run handed over 250 bytes; the destination kept 40.
        $backend->partials[self::REMOTE_NAME] = 40;

        $this->runOnce($this->payloadFor($archive), $backend);

        $this->assertSame([40], $backend->startedAt, 'the run resumed past bytes the destination never took');
        $this->assertSame(0, $backend->sessionsOpened, 'a transfer already under way was started again');
        $this->assertSame(1, $backend->asked, 'the destination was asked more than once in one run');
    }

    /**
     * The destination can answer « I have all of it ».
     *
     * A run that hands over its last chunk and is killed before it can
     * promote leaves an archive on disk and a transfer that is complete.
     * A handler that did not believe the answer would push into a
     * finished transfer, fail five times, and abandon an archive that had
     * in fact arrived.
     */
    public function testADestinationThatAlreadyHoldsTheWholeArchiveFinishesTheSend(): void
    {
        $archive = $this->archiveOf(300);
        $backend = new RecordingBackend($this);
        $backend->partials[self::REMOTE_NAME] = 300;

        $this->runOnce($this->payloadFor($archive), $backend);

        $this->assertSame([], $backend->startedAt, 'bytes were pushed into a transfer that was already finished');
        $this->assertFileDoesNotExist($archive, 'an archive that had arrived was kept on the server');
        $this->assertSame(['remote_backup_sent'], $this->events(['remote_backup_sent', 'remote_backup_failed']));
        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame([], $next['payload'], 'the finished session was carried into the next send');
    }

    /**
     * **The archive is written down before the first byte moves.** A run
     * killed mid-chunk — the host reboots, the process is reaped — leaves
     * nothing behind otherwise, and the next run would rebuild a
     * multi-gibibyte archive to send to a destination that was already
     * most of the way through the last one.
     */
    public function testTheArchiveIsRecordedBeforeAnyByteIsSent(): void
    {
        $archive = $this->archiveOf(300);
        $backend = new RecordingBackend($this);
        $backend->chunkBytes = 100;
        $backend->secondsPerChunk = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;

        // Observed from inside the first append: what a run that never
        // returns would have left in the table.
        $backend->onSend = function () use (&$duringSend): void {
            $duringSend = $this->pending();
        };

        $this->runOnce($this->payloadFor($archive), $backend);

        $this->assertNotNull($duringSend, 'nothing was queued before the send — a killed run rebuilds the archive');
        $this->assertSame($archive, $duringSend['payload']['archive_path']);
        $this->assertSame(
            self::REMOTE_NAME,
            $duringSend['payload']['remote_name'],
            'the row written before the send does not say which key the bytes are going under'
        );
    }

    // ---------------------------------------------------------------
    // The budget
    // ---------------------------------------------------------------

    /**
     * The run stops when its budget does, and what is left is queued for
     * now rather than for tomorrow.
     */
    public function testTheRunStopsOnItsBudgetAndQueuesTheRemainderImmediately(): void
    {
        $archive = $this->archiveOf(1000);
        $backend = new RecordingBackend($this);
        // Eight seconds a chunk against a twenty-second budget: three
        // chunks fit (0, 8, 16 are all under 20) and the fourth does not.
        $backend->chunkBytes = 100;
        $backend->secondsPerChunk = 8.0;

        $this->runOnce($this->payloadFor($archive), $backend);

        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame(
            300,
            $backend->partials[self::REMOTE_NAME],
            'the run sent past the budget it was given'
        );
        $this->assertSame($archive, $next['payload']['archive_path'], 'the remainder lost the archive it resumes on');
        $this->assertLessThanOrEqual(
            time() + 5,
            strtotime((string) $next['run_at']),
            'the remainder was pushed to the next interval instead of being resumed at once'
        );
    }

    /**
     * A finished send re-arms for the interval, not for now — otherwise
     * the site rebuilds and re-sends an archive in a loop.
     */
    public function testAFinishedSendWaitsTheWholeIntervalBeforeTheNextOne(): void
    {
        $archive = $this->archiveOf(50);
        $backend = new RecordingBackend($this);

        $this->runOnce($this->payloadFor($archive), $backend);

        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame([], $next['payload'], 'the next send inherited the finished one\'s session');
        $this->assertGreaterThan(
            time() + (SendRemoteBackupHandler::INTERVAL_HOURS - 1) * 3600,
            strtotime((string) $next['run_at'])
        );
    }

    // ---------------------------------------------------------------
    // The local archive
    // ---------------------------------------------------------------

    /**
     * **Deleted the moment it lands.** It is a portable archive: it
     * carries the master key and the sealed secrets, and IT-04's quota of
     * one exists precisely so such a file does not linger on the server it
     * is meant to outlive.
     */
    public function testTheLocalArchiveIsDeletedOnceTheSendCompletes(): void
    {
        $archive = $this->archiveOf(50);

        $this->runOnce($this->payloadFor($archive), new RecordingBackend($this));

        $this->assertFileDoesNotExist($archive, 'a portable archive carrying the master key was left on disk');
    }

    /** And kept while the send is still going — it is what it resumes on. */
    public function testTheLocalArchiveSurvivesAnUnfinishedRun(): void
    {
        $archive = $this->archiveOf(1000);
        $backend = new RecordingBackend($this);
        $backend->chunkBytes = 100;
        $backend->secondsPerChunk = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;

        $this->runOnce($this->payloadFor($archive), $backend);

        $this->assertFileExists($archive);
    }

    /** And kept after a failure, so the retry does not rebuild it. */
    public function testTheLocalArchiveSurvivesAFailure(): void
    {
        $archive = $this->archiveOf(1000);
        $backend = new RecordingBackend($this);
        $backend->failSendWith = 'Google a refusé la tranche.';

        $this->runOnce($this->payloadFor($archive), $backend);

        $this->assertFileExists($archive, 'a failed run threw away the archive it had just built');
        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame($archive, $next['payload']['archive_path']);
        $this->assertSame(1, $next['payload']['failures']);
    }

    /**
     * **A failure must not throw away what the destination already
     * holds.**
     *
     * This is where the old payload was at its most dangerous: it carried
     * the session and the offset, and a failure that re-armed on the
     * payload it was *called* with lost both — so the next run opened a
     * SECOND transfer and pushed a multi-gibibyte archive from byte zero
     * again, leaving an orphan session behind. Every failure cost one
     * whole upload, on exactly the shared hosting this chunked design
     * exists for. Asking the destination removes the question: what it
     * holds is what it holds, whatever this site remembers.
     */
    public function testAFailureKeepsWhatTheDestinationAlreadyHolds(): void
    {
        $archive = $this->archiveOf(1000);
        $backend = new RecordingBackend($this);
        $backend->chunkBytes = 100;
        // One chunk lands, the next is refused.
        $backend->failSendWith = 'La destination a refusé la tranche.';
        $backend->failAfterChunks = 1;

        $this->runOnce($this->payloadFor($archive), $backend);

        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame($archive, $next['payload']['archive_path']);
        $this->assertSame(1, $next['payload']['failures']);
        $this->assertSame(
            100,
            $backend->partials[self::REMOTE_NAME],
            'a failed run threw away the bytes the destination had already taken'
        );

        // And the retry really does continue on it rather than opening
        // another: one transfer for the whole archive.
        $backend->failSendWith = '';
        $this->runOnce($next['payload'], $backend);
        $this->assertSame([0, 100], $backend->startedAt);
        $this->assertSame(1, $backend->sessionsOpened, 'the retry opened a second transfer on the same archive');
    }

    /**
     * **Bookkeeping after a delivery must not turn it into a failure.**
     *
     * `finish()` stamps the success date through `SettingService`, which
     * can throw. That call used to sit inside the `try` whose catch calls
     * `recordFailure()` — so a settings table refusing one write counted
     * a delivered archive as a failed send, and at the ceiling the
     * abandonment branch deleted the local archive and journaled
     * « abandonné » for a backup sitting safely on the destination, with
     * the next run about to upload the whole thing again.
     */
    public function testAFailedSuccessStampDoesNotTurnADeliveredArchiveIntoAFailure(): void
    {
        $archive = $this->archiveOf(50);
        $this->settings->refuseKey = SendRemoteBackupHandler::LAST_SUCCESS_SETTING;
        $backend = new RecordingBackend($this);

        $this->runOnce($this->payloadFor($archive), $backend);

        $this->assertSame(
            ['remote_backup_sent', 'remote_backup_stamp_failed'],
            $this->events(['remote_backup_sent', 'remote_backup_stamp_failed', 'remote_backup_failed',
                'remote_backup_abandoned']),
            'an archive the destination had taken was recorded as a failed send'
        );

        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame([], $next['payload'], 'the finished send was queued for retry');
        $this->assertGreaterThan(
            time() + (SendRemoteBackupHandler::INTERVAL_HOURS - 1) * 3600,
            strtotime((string) $next['run_at']),
            'a delivered archive was re-armed for an immediate retry'
        );
    }

    /**
     * **The docblock says nothing in `finish()` may throw; this is what
     * makes that true of the journal write too.**
     *
     * The first guard covered only the settings stamp. But
     * `JournalRepository::insert()` writes to the database, and a
     * `PDOException` is a `RuntimeException` — a deadlock or a lost
     * connection would have escaped into `handle()`'s catch AFTER the
     * local archive was deleted, re-arming a failure whose
     * `archive_path` no longer exists. The next run would rebuild and
     * re-upload gibibytes, and the ceiling would journal a delivered
     * backup as abandoned.
     */
    public function testAJournalFailureDoesNotUndoADeliveredArchive(): void
    {
        $archive = $this->archiveOf(50);
        $this->journalBreakingOn('remote_backup_sent');

        $this->runOnce($this->payloadFor($archive), new RecordingBackend($this));

        $this->assertFileDoesNotExist($archive);
        $this->assertSame([], $this->events(['remote_backup_failed', 'remote_backup_abandoned']),
            'a delivered archive was recorded as a failed send because the journal was down');

        $next = $this->pending();
        $this->assertNotNull($next, 'the chain died on a journal failure');
        $this->assertSame([], $next['payload'], 'the finished send was queued for retry');
    }

    /**
     * And of the re-arm — the one loss with no in-process remedy, which
     * still must not become a recorded failure.
     *
     * `scheduled_actions` is the most contended table on the site: cron
     * writes it and so does every page view. The chain itself is put
     * back by the seed in `public/index.php` on the next request; what
     * must not happen is a delivered archive going down the failure
     * path on its way there.
     */
    public function testARearmFailureDoesNotUndoADeliveredArchiveEither(): void
    {
        $archive = $this->archiveOf(50);
        $backend = new RecordingBackend($this);
        // Broken DURING the send, not before it: the queue has to survive
        // long enough for this run to write its pessimistic row and for
        // the destination to take the bytes, so that what fails is the
        // POST-delivery bookkeeping and nothing else. `journal_entries`
        // is a different table and stays readable, which is how the
        // assertions below can see what happened.
        $backend->onSend = function (): void {
            $this->pdo->exec('DROP TABLE scheduled_actions');
        };

        $this->runOnce($this->payloadFor($archive), $backend);

        $this->assertFileDoesNotExist($archive, 'a delivered archive was kept because the re-arm failed');
        $this->assertSame(
            // Twice, because two distinct writes to that table failed —
            // the cancel of this run's own pessimistic row, and the
            // re-arm of the next send.
            ['remote_backup_rearm_failed', 'remote_backup_rearm_failed', 'remote_backup_sent'],
            $this->events(['remote_backup_sent', 'remote_backup_rearm_failed', 'remote_backup_failed',
                'remote_backup_abandoned']),
            'the delivery was not recorded, or was recorded as a failure'
        );
    }

    /**
     * **The purge's own failure report must not undo the delivery
     * either.**
     *
     * `purge()` used to roll its own `catch`, whose body then called
     * `journal->log()` bare — so a lock-wait on the journal table while
     * reporting a failed purge threw out of `purge()`, out of `finish()`,
     * and into `handle()`'s catch. A delivered archive recorded as a
     * failed send, by the error handler of the error handler.
     */
    public function testAJournalFailureWhileReportingAFailedPurgeDoesNotUndoTheDelivery(): void
    {
        $archive = $this->archiveOf(50);
        $backend = new RecordingBackend($this);
        $backend->failListWith = 'Google ne répond plus.';
        $this->journalBreakingOn('remote_backup_purge_failed');

        $this->runOnce($this->payloadFor($archive), $backend);

        $this->assertFileDoesNotExist($archive);
        $this->assertSame(
            ['remote_backup_sent'],
            $this->events(['remote_backup_sent', 'remote_backup_failed', 'remote_backup_abandoned']),
            'a delivered archive was recorded as a failed send by the purge\'s own error handler'
        );
    }

    /**
     * **The journal says whether the archive actually went, not that it
     * did.**
     *
     * Both call sites used to journal the deletion unconditionally, so a
     * failed `unlink()` produced « archive supprimée » over a file still
     * holding `master.key`. That claim matters more here than almost
     * anywhere: the archive is never registered in `BackupRepository`,
     * so `PortableBackupLingerCheck` cannot see it either, and this line
     * is the only thing that could ever surface a leftover.
     *
     * The permission-failure branch has no test of its own, and that is
     * stated rather than faked: this suite runs as root, and root
     * unlinks regardless of permissions. What is covered is that the
     * claim is derived from the outcome instead of asserted — including
     * the case where something else removed the file first, which
     * `unlink()` reports as a failure and which must NOT raise a warning
     * about a leftover that does not exist.
     */
    public function testTheDeliveryReportsWhetherTheLocalCopyIsActuallyGone(): void
    {
        $archive = $this->archiveOf(50);
        $backend = new RecordingBackend($this);
        // Swept away mid-send, as a stray clean-up would: `unlink()` then
        // answers false for a file that is nonetheless gone.
        $backend->onSend = function () use ($archive): void {
            unlink($archive);
        };

        $this->runOnce($this->payloadFor($archive), $backend);

        $this->assertSame(
            [],
            $this->events(['remote_backup_archive_undeletable']),
            'a warning was raised about a leftover archive that was not there'
        );
        $entry = $this->journalContextOf('remote_backup_sent');
        $this->assertTrue($entry['local_copy_removed'] ?? null, 'the entry does not record what became of the copy');
    }

    // ---------------------------------------------------------------
    // Giving up
    // ---------------------------------------------------------------

    /**
     * Past the ceiling the send is abandoned — and abandoning means the
     * archive goes too, or a site that cannot reach its destination
     * accumulates one master-key-bearing archive per attempt.
     */
    public function testPastTheCeilingTheSendIsAbandonedAndTheArchiveWithIt(): void
    {
        $this->settings->values[SendRemoteBackupHandler::MAX_FAILURES_SETTING] = '2';
        $archive = $this->archiveOf(1000);
        $backend = new RecordingBackend($this);
        $backend->failSendWith = 'Google a refusé la tranche.';

        $payload = $this->payloadFor($archive);
        $this->runOnce($payload, $backend);
        $next = $this->pending();
        $this->assertNotNull($next);

        $this->runOnce($next['payload'], $backend);

        $this->assertFileDoesNotExist($archive);
        $this->assertSame(
            ['remote_backup_abandoned'],
            $this->events(['remote_backup_abandoned']),
            'the site kept retrying past its own ceiling'
        );
    }

    /**
     * And abandoning discards what the DESTINATION is holding, not only
     * the local archive.
     *
     * `beginPartial()` leaves a note beside the archive under the
     * backend's own internal prefix, and every backend hides that prefix
     * from `list()` — so `RemoteRetention` cannot see the note and no
     * sweep will ever reach it. Without this, one orphaned object is
     * leaked into the operator's folder per abandoned send, permanently.
     */
    public function testAbandoningAlsoDiscardsWhatTheDestinationIsHolding(): void
    {
        $this->settings->values[SendRemoteBackupHandler::MAX_FAILURES_SETTING] = '2';
        $archive = $this->archiveOf(1000);
        $backend = new RecordingBackend($this);
        $backend->failSendWith = 'Google a refus\u00e9 la tranche.';

        $payload = $this->payloadFor($archive);
        $this->runOnce($payload, $backend);
        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertNotSame([], $backend->partials, 'nothing was begun, so this test proves nothing');

        $this->runOnce($next['payload'], $backend);

        $this->assertSame([], $backend->partials, 'the abandoned transfer was left on the destination');
    }

    /** And the run after an abandonment starts from a wholly new session. */
    public function testTheRunAfterAnAbandonmentOpensAFreshSession(): void
    {
        $this->settings->values[SendRemoteBackupHandler::MAX_FAILURES_SETTING] = '1';
        $archive = $this->archiveOf(1000);
        $backend = new RecordingBackend($this);
        $backend->failSendWith = 'Google a refusé la tranche.';

        $this->runOnce($this->payloadFor($archive), $backend);

        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame([], $next['payload'], 'the abandoned session was carried into the next send');
    }

    // ---------------------------------------------------------------
    // A destination that moved under a transfer
    // ---------------------------------------------------------------

    /**
     * A destination RE-POINTED mid-transfer takes its orphan with it.
     *
     * The run carries the id it started on; the site now names another
     * location. Nothing below will look at the first one again, and every
     * backend hides its own internal prefix from `list()`, so no retention
     * sweep can see the partial either. Without the carried id there is
     * nothing left that knows where those bytes are.
     */
    public function testRePointingTheDestinationDiscardsThePartialLeftOnTheOldOne(): void
    {
        [$oldId, $oldBackend, $partial] = $this->localDestinationHoldingAPartial('ancien');
        $this->assertFileExists($partial, 'no partial was left, so this test proves nothing');

        $this->destination->choose($this->declareLocal('nouveau'));

        $payload = $this->payloadFor($this->archiveOf(1000));
        $payload['location_id'] = $oldId;
        $this->runOnce($payload, new RecordingBackend($this));

        $this->assertFileDoesNotExist($partial, 'the orphan was left on the destination that moved');
        $this->assertSame(0, $oldBackend->partialSize(self::REMOTE_NAME));
    }

    /**
     * And a destination UNSET mid-transfer does the same.
     *
     * This branch already threw away the local archive, which carries
     * `master.key`; the half-sent copy at the far end was staying behind.
     */
    public function testUnsettingTheDestinationDiscardsThePartialItWasHolding(): void
    {
        [, $oldBackend, $partial] = $this->localDestinationHoldingAPartial('ancien');
        $this->assertFileExists($partial, 'no partial was left, so this test proves nothing');

        $oldId = $this->destination->locationId();
        $this->destination->choose(0);

        $payload = $this->payloadFor($this->archiveOf(1000));
        $payload['location_id'] = $oldId;
        $this->runOnce($payload, new RecordingBackend($this));

        $this->assertFileDoesNotExist($partial, 'the orphan outlived the destination that held it');
        $this->assertSame(0, $oldBackend->partialSize(self::REMOTE_NAME));
    }

    /**
     * And a destination whose secret no longer decrypts does not take the
     * run down with it.
     *
     * `backendFor()` is reached from cleanup paths that run beside an
     * archive carrying `master.key`. An exception escaping `handle()`
     * leaves `SchedulerRunner` marking the task failed WITHOUT re-arming
     * it with the payload — so that archive stops being referenced by
     * anything, and never reaches the ceiling that would delete it. The
     * unreachable destination has to read as « nothing left to clean ».
     */
    public function testADestinationWhoseSecretNoLongerDecryptsDoesNotStopTheCleanup(): void
    {
        $archive = $this->archiveOf(1000);

        // The Drive location from connect(), read back with a master key
        // that never encrypted it.
        $rotatedId = $this->destination->locationId();
        $this->destination->choose($this->declareLocal('nouveau'));

        $rotated = new RemoteBackupDestination(
            $this->settings,
            $this->locations,
            new StorageBackendFactory(
                new StorageLocationRepository(
                    $this->pdo,
                    new EncryptionService(str_repeat('z', 32), str_repeat('y', 32))
                ),
                $this->storagePath
            )
        );
        $this->assertNull($rotated->backendFor($rotatedId), 'an unreadable secret escaped as an exception');

        // And the whole run survives it, archive still on disk for the
        // next one rather than orphaned by a failed task.
        $payload = $this->payloadFor($archive);
        $payload['location_id'] = $rotatedId;
        $this->runOnce($payload, new RecordingBackend($this));

        $this->assertNotNull($this->pending(), 'the chain died on a destination it could not open');
    }

    /**
     * A local location, chosen, already holding a partial under the name
     * this test suite sends under.
     *
     * Local rather than Drive on purpose: the point is to watch a REAL
     * backend\'s partial disappear from the disk, which a double could
     * only claim.
     *
     * @return array{0: int, 1: \Core\Storage\Location\Backend\LocalStorageBackend, 2: string}
     */
    private function localDestinationHoldingAPartial(string $label): array
    {
        $id = $this->declareLocal($label);
        $this->destination->choose($id);

        $backend = (new StorageBackendFactory($this->locations, $this->storagePath))
            ->create($this->locations->findById($id));
        self::assertInstanceOf(\Core\Storage\Location\Backend\LocalStorageBackend::class, $backend);

        $backend->beginPartial(self::REMOTE_NAME, 1000);
        $backend->appendToPartial(self::REMOTE_NAME, str_repeat('a', 10));

        return [
            $id,
            $backend,
            $this->storagePath . '/' . $label . '/' . self::REMOTE_NAME . '.scoutmagic-part',
        ];
    }

    private function declareLocal(string $label): int
    {
        return $this->locations->create(
            StorageLocationType::Local,
            $label,
            new \Core\Storage\Location\Config\LocalLocationConfig($label),
            null
        );
    }

    // ---------------------------------------------------------------
    // Retention
    // ---------------------------------------------------------------

    /** A landed archive brings the destination back inside its bounds. */
    public function testASuccessfulSendPurgesTheDestination(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '2';
        $backend = new RecordingBackend($this);
        $backend->remote = [
            new StoredObject('scoutmagic-2026-03-03.zip', 10, null, '2026-03-03T00:00:00Z'),
            new StoredObject('scoutmagic-2026-02-02.zip', 10, null, '2026-02-02T00:00:00Z'),
            new StoredObject('scoutmagic-2026-01-01.zip', 10, null, '2026-01-01T00:00:00Z'),
        ];

        $this->runOnce($this->payloadFor($this->archiveOf(50)), $backend);

        $this->assertSame(['scoutmagic-2026-01-01.zip'], $backend->deleted);
    }

    /**
     * And a purge that fails does not turn a delivered archive into a
     * failed send — the bytes are there either way, and the next run
     * would otherwise send them all again.
     */
    public function testAFailingPurgeDoesNotUndoASuccessfulSend(): void
    {
        $backend = new RecordingBackend($this);
        $backend->failListWith = 'Google ne répond plus.';

        $this->runOnce($this->payloadFor($this->archiveOf(50)), $backend);

        $this->assertSame(
            ['remote_backup_purge_failed', 'remote_backup_sent'],
            $this->events(['remote_backup_sent', 'remote_backup_purge_failed', 'remote_backup_failed']),
            'a delivered archive was journaled as a failed send because the tidying after it threw'
        );
        $this->assertNotSame(
            '',
            (string) $this->settings->get(SendRemoteBackupHandler::LAST_SUCCESS_SETTING),
            'a send that arrived was not recorded because the tidying after it did not'
        );
    }

    // ---------------------------------------------------------------
    // Not connected
    // ---------------------------------------------------------------

    /**
     * A site with no destination sends nothing and builds nothing — but
     * the chain keeps ticking, so that connecting one later starts the
     * sends without anyone re-arming anything by hand.
     */
    public function testASiteWithNoDestinationKeepsTheChainAliveAndBuildsNothing(): void
    {
        $this->destination->choose(0);
        $backend = new RecordingBackend($this);

        $this->runOnce([], $backend);

        $this->assertSame(0, $backend->sessionsOpened);
        $this->assertSame([], glob($this->storagePath . '/maintenance/*') ?: []);
        $this->assertNotNull($this->pending(), 'the chain died on a site that had simply not connected one yet');
    }

    /**
     * **A destination can vanish mid-send, and the archive must go with
     * it.**
     *
     * A multi-run upload crosses runs; between two of them an
     * administrator can re-point the off-site backup somewhere else, or
     * clear it altogether, so the very next run takes the "no destination"
     * branch. The half-sent archive carries `master.key` and the phrase
     * that opens it sits in `secrets.enc` beside it, and nothing else
     * would ever remove it: it is never registered in `BackupRepository`,
     * so `PortableBackupLingerCheck` — a query over `backups`, not a walk
     * of the disk — cannot see it either.
     */
    public function testDroppingTheDestinationMidSendTakesTheHalfSentArchiveWithIt(): void
    {
        $archive = $this->archiveOf(1000);
        $backend = new RecordingBackend($this);
        $backend->chunkBytes = 100;
        $backend->secondsPerChunk = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;

        $this->runOnce($this->payloadFor($archive), $backend);
        $inFlight = $this->pending();
        $this->assertNotNull($inFlight);
        $this->assertFileExists($archive, 'the send did not get as far as carrying an archive');

        // The destination is dropped between the two runs.
        $this->destination->choose(0);

        $this->runOnce($inFlight['payload'], $backend);

        $this->assertFileDoesNotExist(
            $archive,
            'an archive carrying the site\'s master key was left in storage/ with nothing able to remove it'
        );
        $this->assertSame(
            ['remote_backup_archive_discarded'],
            $this->events(['remote_backup_archive_discarded']),
            'a file holding the master key disappeared without a line saying so'
        );
        $this->assertNotNull($this->pending(), 'the chain died when the destination went away');
    }

    // ---------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------

    /**
     * **A destination whose secret no longer decrypts is a failure, not a
     * crash — and the difference is a key-bearing archive left on disk.**
     *
     * `backend()` reaches `StorageBackendFactory`, which reads the
     * location's encrypted column; a master key that has been rotated
     * makes that read raise `Security\\DecryptionException`, which is not
     * a `RemoteBackupException`. Caught too narrowly, it escapes past
     * `recordFailure()` — and `recordFailure()` is what climbs the counter
     * and, at the ceiling, DELETES the archive. The archive is a portable
     * backup: it carries `master.key`. So the run dies, the file stays,
     * the same thing happens every night, and the ceiling that would have
     * removed it is never reached.
     *
     * That rotation is precisely the scenario D7 cites for moving these
     * credentials out of `secrets.enc` in the first place.
     */
    public function testADestinationWhoseSecretNoLongerDecryptsIsRecordedRatherThanThrown(): void
    {
        $archive = $this->archiveOf(64);

        // The same rows, read with a different key: what a master-key
        // rotation leaves behind.
        $rotated = new RemoteBackupDestination(
            $this->settings,
            new StorageLocationRepository(
                $this->pdo,
                new EncryptionService(str_repeat('z', 32), str_repeat('y', 32))
            ),
            new StorageBackendFactory(
                new StorageLocationRepository(
                    $this->pdo,
                    new EncryptionService(str_repeat('z', 32), str_repeat('y', 32))
                ),
                $this->storagePath
            )
        );

        // No backend handed in: this is the path that builds one.
        (new SendRemoteBackupHandler(null, $rotated, fn (): float => $this->clock, 1024))
            ->handle($this->payloadFor($archive), $this->context());

        $next = $this->pending();
        $this->assertNotNull($next, 'the run died instead of recording a failure');
        $this->assertSame(1, $next['payload']['failures']);
        $this->assertSame($archive, $next['payload']['archive_path']);
        $this->assertFileExists($archive, 'the archive was thrown away on the first failure');
    }

    /**
     * Declares a destination and points the off-site backup at it, exactly
     * as the two screens would.
     */
    private function connect(): void
    {
        $id = $this->locations->create(
            StorageLocationType::GoogleDrive,
            'Google Drive',
            new GoogleDriveLocationConfig('client-1', 'dossier-1', '2026-03-01T00:00:00+00:00'),
            (string) json_encode([
                'client_secret' => 's',
                'refresh_token' => 'un-jeton-de-rafraichissement',
                'account' => 'unite@example.org',
            ])
        );
        $this->destination->choose($id);
    }

    /** A file of `$bytes` standing in for a portable archive. */
    /**
     * The decoded context of one journal entry.
     *
     * @return array<string, mixed>
     */
    private function journalContextOf(string $eventType): array
    {
        foreach ((new JournalRepository($this->pdo))->search() as $entry) {
            if ($entry['event_type'] === $eventType) {
                $decoded = is_string($entry['context'] ?? null) ? json_decode((string) $entry['context'], true) : null;

                return is_array($decoded) ? $decoded : [];
            }
        }

        return [];
    }

    /** Makes the journal refuse one event type, as a broken table would. */
    private function journalBreakingOn(string $eventType): void
    {
        $this->journal = new BreakableJournalService(new JournalRepository($this->pdo));
        $this->journal->breakOn = $eventType;
    }

    private function archiveOf(int $bytes): string
    {
        $path = $this->storagePath . '/maintenance/backup_' . uniqid() . '.zip';
        file_put_contents($path, str_repeat('x', $bytes));

        return $path;
    }

    /**
     * The payload a previous run would have left for an archive that is
     * already built — so a test about sending is not also a test about
     * `mysqldump`.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(string $archive): array
    {
        return [
            'archive_path' => $archive,
            'remote_name' => self::REMOTE_NAME,
        ];
    }

    /**
     * One run of the handler, on the payload a previous one left.
     *
     * @param array<string, mixed> $payload
     */
    private function runOnce(array $payload, RecordingBackend $backend): void
    {
        $backend->beginRun();
        (new SendRemoteBackupHandler(
            $backend,
            $this->destination,
            fn (): float => $this->clock,
            $backend->chunkBytes > 0 ? $backend->chunkBytes : SendRemoteBackupHandler::CHUNK_BYTES
        ))->handle($payload, $this->context());
    }

    /**
     * The single pending row of this chain, payload decoded.
     *
     * @return array{run_at: string, payload: array<string, mixed>}|null
     */
    private function pending(): ?array
    {
        $row = $this->scheduler->findByModuleAndKey(
            'core',
            SendRemoteBackupHandler::TASK_KEY,
            SendRemoteBackupHandler::REFERENCE
        );
        if ($row === null) {
            return null;
        }

        $payload = is_string($row['payload'] ?? null) ? json_decode((string) $row['payload'], true) : [];

        return [
            'run_at' => (string) $row['run_at'],
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    /**
     * The journal entries a test names, sorted.
     *
     * Filtered rather than whole, so an unrelated entry does not turn a
     * green assertion red while a missing one still does — and sorted
     * rather than in journal order, because what these tests claim is
     * which entries were written, not which came first.
     *
     * @param list<string> $ofInterest
     * @return list<string>
     */
    private function events(array $ofInterest): array
    {
        $types = [];
        foreach ((new JournalRepository($this->pdo))->search() as $entry) {
            if (in_array($entry['event_type'], $ofInterest, true)) {
                $types[] = (string) $entry['event_type'];
            }
        }
        sort($types);

        return $types;
    }

    private function context(): TaskContext
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            $this->journal ??= new BreakableJournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    public function advanceClock(float $seconds): void
    {
        $this->clock += $seconds;
    }
}

/**
 * A destination that behaves like a resumable one without being any.
 *
 * It **holds what it has been given**, which is what makes the resume
 * assertions mean something: a run that started over would be visible as
 * bytes going twice, not as a mock expectation nobody wrote. The
 * handler's own budget is consulted for real, against a clock this double
 * advances as it accepts each chunk — so a test that wants a run to stop
 * half way buys that with time, exactly as a slow link would.
 */
final class RecordingBackend extends RefusingBackend implements ResumableUploadBackend
{
    /**
     * Bytes the handler is told to read at a time; 0 leaves it on its own
     * constant.
     *
     * This is a test-only seam on the HANDLER (which chunks at 8 MiB in
     * production), not on this double: without it, every test about a
     * send that crosses runs would have to write tens of mebibytes to a
     * temporary disk to produce a second chunk.
     */
    public int $chunkBytes = 0;

    /**
     * Seconds each chunk costs on the test's clock.
     *
     * What makes the handler's time budget real rather than decorative:
     * it stops when the deadline says so and at no other point.
     */
    public float $secondsPerChunk = 0.0;

    /**
     * The offset each run began appending from.
     *
     * @var list<int>
     */
    public array $startedAt = [];

    /**
     * Keys `beginPartial()` was asked for, in order.
     *
     * @var list<string>
     */
    public array $names = [];

    /** How many transfers were OPENED — a resume must not add to this. */
    public int $sessionsOpened = 0;

    /** How many times the destination was asked where it stood. */
    public int $asked = 0;

    /**
     * Bytes held per key, and what has been promoted.
     *
     * @var array<string, int>
     */
    public array $partials = [];

    /** @var array<string, int> */
    public array $stored = [];

    public string $failSendWith = '';

    /** Chunks to accept before {@see $failSendWith} bites. */
    public int $failAfterChunks = 0;

    public string $failListWith = '';

    /** @var list<StoredObject> */
    public array $remote = [];

    /** @var list<string> */
    public array $deleted = [];

    public ?\Closure $onSend = null;

    private bool $sentThisRun = false;

    private int $acceptedThisRun = 0;

    public function __construct(private readonly SendRemoteBackupHandlerTest $test)
    {
    }

    /** Called by the test before each run, never by the handler. */
    public function beginRun(): void
    {
        $this->sentThisRun = false;
        $this->acceptedThisRun = 0;
    }

    public function beginPartial(string $key, int $totalBytes): void
    {
        $this->sessionsOpened++;
        $this->names[] = $key;
        $this->partials[$key] = 0;
    }

    public function partialSize(string $key): int
    {
        $this->asked++;

        return $this->partials[$key] ?? 0;
    }

    public function appendToPartial(string $key, string $chunk): void
    {
        if (!$this->sentThisRun) {
            $this->startedAt[] = $this->partials[$key] ?? 0;
            $this->sentThisRun = true;
            if ($this->onSend !== null) {
                ($this->onSend)();
            }
        }

        if ($this->failSendWith !== '' && $this->acceptedThisRun >= $this->failAfterChunks) {
            throw new StorageLocationException($this->failSendWith);
        }
        $this->acceptedThisRun++;

        $this->partials[$key] = ($this->partials[$key] ?? 0) + strlen($chunk);
        $this->test->advanceClock($this->secondsPerChunk);
    }

    public function promotePartial(string $key, string $mimeType): void
    {
        $this->stored[$key] = $this->partials[$key] ?? 0;
        unset($this->partials[$key]);
    }

    public function discardPartial(string $key): void
    {
        unset($this->partials[$key]);
    }

    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): StorageListing
    {
        if ($this->failListWith !== '') {
            throw new StorageLocationException($this->failListWith);
        }

        return new StorageListing($this->remote);
    }

    public function delete(string $key): void
    {
        $this->deleted[] = $key;
    }
}

/**
 * A journal with one event type that refuses to be written.
 *
 * The journal is a database write like any other, and `PDOException`
 * extends `RuntimeException` — so what this double stands in for is an
 * ordinary deadlock on a busy site, not an exotic failure.
 */
final class BreakableJournalService extends JournalService
{
    public string $breakOn = '';

    /** @param array<string, mixed> $context */
    public function log(
        string $category,
        string $type,
        string $level,
        string $description,
        array $context = [],
        ?int $userId = null
    ): void {
        if ($this->breakOn !== '' && $type === $this->breakOn) {
            throw new \RuntimeException('le journal est indisponible');
        }

        parent::log($category, $type, $level, $description, $context, $userId);
    }
}
