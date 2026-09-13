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
use Core\Maintenance\Remote\RemoteBackupConnection;
use Core\Maintenance\Remote\RemoteBackupException;
use Core\Maintenance\Remote\RemoteBackupTarget;
use Core\Maintenance\Remote\RemoteConnectionCheck;
use Core\Maintenance\Remote\RemoteFile;
use Core\Maintenance\Remote\RemoteQuota;
use Core\Maintenance\Remote\RemoteRetention;
use Core\Maintenance\Remote\RemoteUpload;
use Core\Maintenance\Task\SendRemoteBackupHandler;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\InMemorySettingService;
use Tests\DatabaseTestHelper;

/**
 * The recurring send, run against a destination that is not there.
 *
 * **Not one byte of this suite crosses the network.** IT-08's whole point
 * was to put a destination behind {@see RemoteBackupTarget}, and this is
 * what that interface was for: `RecordingTarget` below answers exactly as
 * Google would — a session URI, a partial commit, a refusal — without a
 * Google account, a consent screen or a project. A suite that could only
 * exercise the real thing would exercise nothing.
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
    private InMemorySettingService $settings;
    private SecretManager $secrets;
    private SchedulerRepository $scheduler;

    /** Seconds on the fake clock, advanced by the target as it sends. */
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
        // that raccording a destination never erases the SMTP password.
        $this->secrets->writeSecrets(['smtp_password' => 'le-mot-de-passe-smtp']);

        $this->settings = new InMemorySettingService();
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
        $target = new RecordingTarget($this);
        $target->chunkBytes = 100;
        $target->secondsPerChunk = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;

        $payload = $this->payloadFor($archive);
        $this->runOnce($payload, $target);

        $next = $this->pending();
        $this->assertNotNull($next, 'the chain stopped after one run — the rest would never go');
        $this->assertSame(100, $next['payload']['offset']);
        $this->assertTrue($next['payload']['offset_is_certain']);

        $this->runOnce($next['payload'], $target);

        $this->assertSame(
            [0, 100],
            $target->startedAt,
            'a run began at an offset the destination had already taken — those bytes went twice'
        );
        $this->assertSame(1, $target->sessionsOpened, 'the second run opened a new session instead of resuming');
    }

    /**
     * A run that dies without writing anything back leaves an offset that
     * is a guess, and the next run asks the destination rather than
     * trusting it.
     *
     * The guess is not merely stale: a run can hand over eight mebibytes
     * and have the destination commit one, so « what I sent » and « what
     * it holds » are different numbers, and only the second is safe to
     * resume from.
     */
    public function testAnUncertainOffsetIsSettledByTheDestinationNotByThePayload(): void
    {
        $archive = $this->archiveOf(300);
        $target = new RecordingTarget($this);
        $target->chunkBytes = 100;
        $target->secondsPerChunk = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;
        $target->probeAnswer = 40;

        $payload = $this->payloadFor($archive) + [];
        $payload['session_url'] = 'https://upload.example/session-1';
        $payload['offset'] = 250;
        $payload['offset_is_certain'] = false;
        $target->sessions['https://upload.example/session-1'] = 40;

        $this->runOnce($payload, $target);

        $this->assertSame([40], $target->startedAt, 'the run trusted its own guess over the destination');
        $this->assertSame(1, $target->probes);
    }

    /**
     * The probe can answer « it is already there ».
     *
     * A run that commits its last chunk and is killed before it can write
     * that down leaves a payload saying « unfinished ». Sending into a
     * session the destination has already closed is refused, so a handler
     * that did not read the probe's answer would fail that send five
     * times and abandon an archive that had, in fact, arrived.
     */
    public function testAProbeThatFindsTheFileAlreadyThereFinishesTheSend(): void
    {
        $archive = $this->archiveOf(300);
        $target = new RecordingTarget($this);
        $target->probeAnswer = 300;
        $target->sessions['https://upload.example/session-1'] = 300;

        $payload = $this->payloadFor($archive);
        $payload['session_url'] = 'https://upload.example/session-1';
        $payload['offset'] = 250;
        $payload['offset_is_certain'] = false;

        $this->runOnce($payload, $target);

        $this->assertSame([], $target->startedAt, 'bytes were pushed into a session the destination had closed');
        $this->assertFileDoesNotExist($archive, 'an archive that had arrived was kept on the server');
        $this->assertSame(['remote_backup_sent'], $this->events(['remote_backup_sent', 'remote_backup_failed']));
        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame([], $next['payload'], 'the finished session was carried into the next send');
    }

    /**
     * And a certain offset is NOT re-probed — the round trip is a request
     * to Google for a number this application already knows.
     */
    public function testACertainOffsetIsNotProbed(): void
    {
        $archive = $this->archiveOf(300);
        $target = new RecordingTarget($this);
        $target->chunkBytes = 100;
        $target->secondsPerChunk = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;

        $payload = $this->payloadFor($archive);
        $payload['session_url'] = 'https://upload.example/session-1';
        $payload['offset'] = 100;
        $payload['offset_is_certain'] = true;
        $target->sessions['https://upload.example/session-1'] = 100;

        $this->runOnce($payload, $target);

        $this->assertSame(0, $target->probes);
        $this->assertSame([100], $target->startedAt);
    }

    /**
     * **The session is written down before the first byte moves.** A run
     * killed mid-chunk — the host reboots, the process is reaped — leaves
     * nothing behind otherwise, and the next run would rebuild a
     * multi-gibibyte archive to send it to a session that was already
     * most of the way there.
     */
    public function testTheSessionIsRecordedBeforeAnyByteIsSent(): void
    {
        $archive = $this->archiveOf(300);
        $target = new RecordingTarget($this);
        $target->chunkBytes = 100;
        $target->secondsPerChunk = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;

        // Observed from inside sendChunks(): what a run that never
        // returns would have left in the table.
        $target->onSend = function () use (&$duringSend): void {
            $duringSend = $this->pending();
        };

        $this->runOnce($this->payloadFor($archive), $target);

        $this->assertNotNull($duringSend, 'nothing was queued before the send — a killed run loses the session');
        $this->assertSame('https://upload.example/session-1', $duringSend['payload']['session_url']);
        $this->assertFalse(
            $duringSend['payload']['offset_is_certain'],
            'the row written before the send claims to know an offset the run had not reached yet'
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
        $target = new RecordingTarget($this);
        // Eight seconds a chunk against a twenty-second budget: three
        // chunks fit (0, 8, 16 are all under 20) and the fourth does not.
        $target->chunkBytes = 100;
        $target->secondsPerChunk = 8.0;

        $this->runOnce($this->payloadFor($archive), $target);

        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame(300, $next['payload']['offset'], 'the run sent past the budget it was given');
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
        $target = new RecordingTarget($this);

        $this->runOnce($this->payloadFor($archive), $target);

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

        $this->runOnce($this->payloadFor($archive), new RecordingTarget($this));

        $this->assertFileDoesNotExist($archive, 'a portable archive carrying the master key was left on disk');
    }

    /** And kept while the send is still going — it is what it resumes on. */
    public function testTheLocalArchiveSurvivesAnUnfinishedRun(): void
    {
        $archive = $this->archiveOf(1000);
        $target = new RecordingTarget($this);
        $target->chunkBytes = 100;
        $target->secondsPerChunk = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;

        $this->runOnce($this->payloadFor($archive), $target);

        $this->assertFileExists($archive);
    }

    /** And kept after a failure, so the retry does not rebuild it. */
    public function testTheLocalArchiveSurvivesAFailure(): void
    {
        $archive = $this->archiveOf(1000);
        $target = new RecordingTarget($this);
        $target->failSendWith = 'Google a refusé la tranche.';

        $this->runOnce($this->payloadFor($archive), $target);

        $this->assertFileExists($archive, 'a failed run threw away the archive it had just built');
        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame($archive, $next['payload']['archive_path']);
        $this->assertSame(1, $next['payload']['failures']);
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
        $target = new RecordingTarget($this);
        $target->failSendWith = 'Google a refusé la tranche.';

        $payload = $this->payloadFor($archive);
        $this->runOnce($payload, $target);
        $next = $this->pending();
        $this->assertNotNull($next);

        $this->runOnce($next['payload'], $target);

        $this->assertFileDoesNotExist($archive);
        $this->assertSame(
            ['remote_backup_abandoned'],
            $this->events(['remote_backup_abandoned']),
            'the site kept retrying past its own ceiling'
        );
    }

    /** And the run after an abandonment starts from a wholly new session. */
    public function testTheRunAfterAnAbandonmentOpensAFreshSession(): void
    {
        $this->settings->values[SendRemoteBackupHandler::MAX_FAILURES_SETTING] = '1';
        $archive = $this->archiveOf(1000);
        $target = new RecordingTarget($this);
        $target->failSendWith = 'Google a refusé la tranche.';

        $this->runOnce($this->payloadFor($archive), $target);

        $next = $this->pending();
        $this->assertNotNull($next);
        $this->assertSame([], $next['payload'], 'the abandoned session was carried into the next send');
    }

    // ---------------------------------------------------------------
    // Retention
    // ---------------------------------------------------------------

    /** A landed archive brings the destination back inside its bounds. */
    public function testASuccessfulSendPurgesTheDestination(): void
    {
        $this->settings->values[RemoteRetention::KEEP_SETTING] = '2';
        $target = new RecordingTarget($this);
        $target->remote = [
            new RemoteFile('new', 'scoutmagic-2026-03-03.zip', 10, '2026-03-03T00:00:00Z'),
            new RemoteFile('mid', 'scoutmagic-2026-02-02.zip', 10, '2026-02-02T00:00:00Z'),
            new RemoteFile('old', 'scoutmagic-2026-01-01.zip', 10, '2026-01-01T00:00:00Z'),
        ];

        $this->runOnce($this->payloadFor($this->archiveOf(50)), $target);

        $this->assertSame(['old'], $target->deleted);
    }

    /**
     * And a purge that fails does not turn a delivered archive into a
     * failed send — the bytes are there either way, and the next run
     * would otherwise send them all again.
     */
    public function testAFailingPurgeDoesNotUndoASuccessfulSend(): void
    {
        $target = new RecordingTarget($this);
        $target->failListWith = 'Google ne répond plus.';

        $this->runOnce($this->payloadFor($this->archiveOf(50)), $target);

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
        $this->settings->values[RemoteBackupConnection::STATE_SETTING]
            = RemoteBackupConnection::STATE_DISCONNECTED;
        $target = new RecordingTarget($this);

        (new SendRemoteBackupHandler($target, fn(): float => $this->clock))->handle([], $this->context());

        $this->assertSame(0, $target->sessionsOpened);
        $this->assertSame([], glob($this->storagePath . '/maintenance/*') ?: []);
        $this->assertNotNull($this->pending(), 'the chain died on a site that had simply not raccordé yet');
    }

    // ---------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------

    /**
     * Marks the site as raccordé to a destination, exactly as
     * `RemoteBackupConnection::saveConnection()` would.
     */
    private function connect(): void
    {
        (new RemoteBackupConnection($this->settings, $this->secrets))
            ->saveConnection('un-jeton-de-rafraichissement', 'unite@example.org', 'dossier-distant');
    }

    /** A file of `$bytes` standing in for a portable archive. */
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
            'remote_name' => 'scoutmagic-2026-03-03-120000-g1.zip',
        ];
    }

    /**
     * One run of the handler, on the payload a previous one left.
     *
     * @param array<string, mixed> $payload
     */
    private function runOnce(array $payload, RecordingTarget $target): void
    {
        (new SendRemoteBackupHandler($target, fn(): float => $this->clock))
            ->handle($payload, $this->context());
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
            new JournalService(new JournalRepository($this->pdo)),
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
 * A destination that behaves like Google without being Google.
 *
 * It commits a bounded number of bytes per run and then reports "not
 * finished", which is what a resumable upload under a time budget
 * actually looks like; the handler's `hasTimeLeft()` closure is consulted
 * for real, against a clock this double advances.
 */
final class RecordingTarget implements RemoteBackupTarget
{
    /** Bytes committed per chunk; 0 means "the whole remainder at once". */
    public int $chunkBytes = 0;

    /**
     * Seconds each chunk costs on the test's clock.
     *
     * This is what makes the handler's time budget real rather than
     * decorative: `sendChunks()` below stops when `hasTimeLeft()` says so
     * and at no other point, so a test that wants a run to stop half way
     * buys that with time, exactly as a slow link would.
     */
    public float $secondsPerChunk = 0.0;

    /**
     * Offsets the handler asked each run to start from.
     *
     * @var list<int>
     */
    public array $startedAt = [];

    /**
     * Names `beginUpload()` was asked for, in order.
     *
     * @var list<string>
     */
    public array $names = [];

    public int $sessionsOpened = 0;
    public int $probes = 0;

    /**
     * session URI => bytes the destination holds.
     *
     * @var array<string, int>
     */
    public array $sessions = [];

    public int $probeAnswer = 0;

    public string $failSendWith = '';
    public string $failListWith = '';

    /** @var RemoteFile[] */
    public array $remote = [];

    /** @var list<string> */
    public array $deleted = [];

    public ?\Closure $onSend = null;

    public function __construct(private readonly SendRemoteBackupHandlerTest $test)
    {
    }

    public function upload(string $localPath, string $remoteName): string
    {
        return 'witness';
    }

    public function beginUpload(string $remoteName, int $size): string
    {
        $this->sessionsOpened++;
        $this->names[] = $remoteName;
        $url = 'https://upload.example/session-' . $this->sessionsOpened;
        $this->sessions[$url] = 0;

        return $url;
    }

    public function probeUpload(string $sessionUrl, int $size): RemoteUpload
    {
        $this->probes++;

        return $this->probeAnswer >= $size
            ? RemoteUpload::completed($sessionUrl, 'file-' . $this->probes)
            : RemoteUpload::inProgress($sessionUrl, $this->probeAnswer);
    }

    public function sendChunks(
        string $sessionUrl,
        string $localPath,
        int $size,
        int $offset,
        \Closure $hasTimeLeft
    ): RemoteUpload {
        $this->startedAt[] = $offset;
        if ($this->onSend !== null) {
            ($this->onSend)();
        }

        if ($this->failSendWith !== '') {
            throw RemoteBackupException::of($this->failSendWith);
        }

        $chunk = $this->chunkBytes > 0 ? $this->chunkBytes : max(1, $size - $offset);
        while ($offset < $size && $hasTimeLeft()) {
            $offset = min($size, $offset + $chunk);
            $this->test->advanceClock($this->secondsPerChunk);
        }
        $this->sessions[$sessionUrl] = $offset;

        return $offset >= $size
            ? RemoteUpload::completed($sessionUrl, 'file-' . count($this->startedAt))
            : RemoteUpload::inProgress($sessionUrl, $offset);
    }

    public function list(): array
    {
        if ($this->failListWith !== '') {
            throw RemoteBackupException::of($this->failListWith);
        }

        return $this->remote;
    }

    public function delete(string $remoteId): void
    {
        $this->deleted[] = $remoteId;
    }

    public function quota(): ?RemoteQuota
    {
        return null;
    }

    public function testConnection(): RemoteConnectionCheck
    {
        return RemoteConnectionCheck::success('unite@example.org', null);
    }
}
