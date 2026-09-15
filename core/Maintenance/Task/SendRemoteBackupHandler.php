<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Config\SettingService;
use Core\Exception\UserFacingMessage;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Remote\RemoteBackupException;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Maintenance\Remote\RemoteRetention;
use Core\Storage\Location\Backend\ResumableUploadBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\StorageLocationRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\SecretManager;

/**
 * Sends the site off-site, a few chunks per run.
 *
 * **Why this exists rather than uploading `auto_backup`.** The scheduled
 * backup on the server is deliberately NOT encrypted (ARCHITECTURE.md
 * §8.15: nobody is present at three in the morning to type a password).
 * Handing that file to a third party would deposit every member's name,
 * address and photograph in somebody else's cloud in clear. So when a
 * destination is connected, what leaves is a **portable** archive in the
 * IT-06 format, encrypted with the generated phrase
 * ({@see RemotePassphrase}) — the same artefact an operator would carry
 * away by hand, and unreadable to Google.
 *
 * **The shape is `SendNotificationsHandler`'s, deliberately reused.** A
 * budget bounds how much one run does; whatever is left reschedules
 * immediately. It is the only shape that works on shared hosting, where
 * `max_execution_time` is thirty to a hundred and twenty seconds and a
 * backup is measured in gibibytes. The difference is what survives
 * between runs: a notification batch is a list of ids, and here it is the
 * archive itself, sitting on the disk under a name the destination already
 * holds some of — so a send crosses as many runs as it needs without ever
 * restarting.
 *
 * **Nothing about the transfer itself survives in the payload any more,
 * and that is IT-05's doing.** This handler used to carry a resumable
 * session URI and a byte offset between runs, plus a flag saying whether
 * the offset could be trusted — the machinery of a task that was also the
 * only thing in the application that knew Google's protocol. All of it
 * moved behind {@see ResumableUploadBackend}: the destination keeps its
 * own note of the session, and `partialSize()` asks it where the transfer
 * actually stands. So a database restored to last week can no longer move
 * an upload backwards, the same D12 reading that governs a location's
 * safety copy, and what is left here is the one thing genuinely this
 * task's own — which archive is being sent, and how many runs in a row
 * have failed.
 *
 * **The local archive is deleted the moment the send succeeds.** It is
 * not a portable backup the operator asked for, and IT-04's quota of one
 * exists precisely so a key-bearing archive does not linger on the server
 * it is meant to outlive. It does occupy the disk while it is being sent,
 * which is why `DiskBudget` is asked for room before it is built.
 */
class SendRemoteBackupHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'send_remote_backup';
    public const REFERENCE = 'auto';

    /**
     * Twenty seconds, the same figure `SendNotificationsHandler` works
     * to. Not invented here: a second number for the same constraint
     * would be a second number to keep right.
     */
    public const TIME_BUDGET_SECONDS = 20;

    /** How often a site with a connected destination sends, in hours. */
    public const INTERVAL_HOURS = 24;

    public const MAX_FAILURES_SETTING = 'backup_remote_max_failures';
    public const LAST_SUCCESS_SETTING = 'backup_remote_last_success';

    public const DEFAULT_MAX_FAILURES = 5;

    /**
     * How much is read from the local archive and appended at a time.
     *
     * 8 MiB: large enough that a gibibyte is not ten thousand round trips,
     * small enough to sit inside any `memory_limit` this application runs
     * under, including the 128 MB shared hosting still ships. The same
     * figure `Core\Storage\Location\Protection\ProtectedCopier` reads
     * its slices in, for the same reason and deliberately not a second
     * number for the same constraint.
     */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    public function __construct(
        /**
         * Both null in production, and both overridable so a test can run
         * this whole chain without a Google account or a database: the
         * destination decides WHERE, the backend is WHAT WRITES there.
         */
        private readonly ?ResumableUploadBackend $backend = null,
        private readonly ?RemoteBackupDestination $destination = null,
        private readonly ?\Closure $now = null,
        /**
         * Overridable so a test can exercise a send that crosses several
         * runs without writing tens of mebibytes to a temporary disk to
         * do it. Production never passes it.
         */
        private readonly int $chunkBytes = self::CHUNK_BYTES
    ) {
    }

    /**
     * Declares what the recurring send is allowed to do and what it last
     * managed to do.
     */
    public static function register(SettingService $settings): void
    {
        // There was a third setting here — « Envoyer aussi la galerie hors
        // site » — and D10 removed the question rather than the answer. No
        // archive carries a directory declared as a storage location any
        // more, so a site that ticked the box would have been promised
        // photographs in an archive that no longer holds any. What
        // protects a location is its own copy (IT-04), which is
        // configured on the location and not on the send.
        $settings->register(self::MAX_FAILURES_SETTING, (string) self::DEFAULT_MAX_FAILURES, 'number',
            'Échecs consécutifs avant abandon',
            'Après ce nombre d\'échecs de suite, l\'envoi en cours est abandonné et le suivant repart d\'une '
            . 'session neuve.', null, null, null, true, 323);
        $settings->register(self::LAST_SUCCESS_SETTING, '', 'text',
            'Dernier envoi hors site réussi', 'La date du dernier envoi arrivé à son terme.',
            null, null, null, false, 324);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $destination = $this->destination ?? $this->destinationFor($context);

        if (!$destination->isConfigured()) {
            // No destination chosen: nothing to send, and nothing wrong.
            // The chain keeps ticking so that choosing one later starts
            // the sends without anybody re-arming it by hand.
            //
            // **But a send may have been under way.** A destination goes
            // from chosen to not WHILE a multi-run upload is crossing
            // runs — an administrator re-points it, or deletes the row —
            // and this branch then fires on the very next run. The
            // half-sent archive it was carrying has to go with the payload
            // it lived in: it holds `master.key` and the phrase that opens
            // it sits in `secrets.enc` beside it, and nothing else would
            // ever remove it.
            $this->discardArchive($payload, $context);
            $this->scheduleNext($context, []);

            return;
        }

        try {
            $backend = $this->backend ?? $destination->backend();
        } catch (\RuntimeException $e) {
            // **Every way building a backend can fail, not just the one
            // this feature declares.** `RemoteBackupException` covers a
            // destination that cannot resume an interrupted upload; but
            // `backend()` reaches `StorageBackendFactory`, which reads the
            // location's encrypted column — so a secret that no longer
            // decrypts raises `Security\DecryptionException`, and a type
            // this build cannot open raises `StorageLocationException`.
            // Neither is a `RemoteBackupException`.
            //
            // Escaping here is not merely an unreported failure. It is
            // `recordFailure()` that climbs the counter and, at the
            // ceiling, DELETES the archive — a portable backup carrying
            // `master.key`. A resumed run that dies on this line leaves
            // that file on the disk, re-attempts it every night, and never
            // reaches the ceiling that would remove it. The master-key
            // rotation that makes a secret unreadable is the very scenario
            // D7 cites for moving these credentials.
            //
            // Not widened at the send site below, deliberately: the
            // backend is already built by then, so this family of failures
            // cannot arise there.
            $this->recordFailure(
                $payload,
                $context,
                UserFacingMessage::from(
                    $e,
                    // `DecryptionException` is not a `UserFacingException`, and
                    // its message names the cipher rather than the remedy.
                    'La destination hors site n\'a pas pu être ouverte : ses identifiants sont illisibles. '
                    . 'Reraccordez le compte depuis Configuration > Stockage.'
                )
            );

            return;
        }

        if ($backend === null) {
            // Unreachable while `isConfigured()` above said yes, and a
            // refusal rather than a silent no-op: a destination that
            // resolves to nothing between two lines is a state worth
            // meeting as an error rather than as a quiet skipped send.
            $this->recordFailure($payload, $context, 'La destination hors site n\'a pas pu être ouverte.');

            return;
        }

        // **The archive first, and on its own.** Everything after this
        // point must be able to say « keep the file, resume on it », and
        // it can only say that once it knows the file's name — which is
        // exactly what building it produces. A single try around both
        // halves would report a failed send with the payload it was
        // *called* with, so a send that died on its first chunk would
        // rebuild a multi-gibibyte archive next run and leave the one it
        // had just written orphaned on the disk.
        try {
            [$archivePath, $remoteName, $fresh] = $this->resolveArchive($payload, $context);
        } catch (\RuntimeException $e) {
            $this->recordFailure($payload, $context, $e->getMessage());

            return;
        }

        $carried = [
            'archive_path' => $archivePath,
            'remote_name' => $remoteName,
            'failures' => (int) ($payload['failures'] ?? 0),
        ];

        try {
            if ($fresh) {
                // A new archive under a name a previous attempt may have
                // started: whatever the destination is holding describes
                // different bytes, and resuming onto it would splice two
                // archives together. The names differ by the second, so
                // this is rare — and « rare » is exactly the case that
                // gets shipped broken.
                $backend->discardPartial($remoteName);
            }

            $this->send($carried, $context, $backend);
        } catch (\RuntimeException $e) {
            // RemoteBackupException, StorageLocationException,
            // BackupException and InsufficientDiskSpaceException are all
            // RuntimeExceptions, and all of them mean the same thing to
            // the run that catches them: nothing more left this server,
            // and the next run picks the same archive back up.
            $this->recordFailure($carried, $context, $e->getMessage(), $backend);
        }
    }

    /**
     * The archive this run sends: the one a previous run left behind, or
     * a new one.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: string, 2: bool} path, remote name, and
     *         whether it was built just now — a fresh archive invalidates
     *         anything the destination holds under that name, since what
     *         is there describes different bytes.
     * @throws \Core\Maintenance\BackupException
     */
    private function resolveArchive(array $payload, TaskContext $context): array
    {
        $archivePath = (string) ($payload['archive_path'] ?? '');
        $remoteName = (string) ($payload['remote_name'] ?? '');

        if ($archivePath !== '' && $remoteName !== '' && is_file($archivePath)) {
            return [$archivePath, $remoteName, false];
        }

        return [$this->buildArchive($context), $this->remoteName($context), true];
    }

    /**
     * Pushes as much of the archive as the budget allows.
     *
     * **Where the transfer stands is asked of the destination, never
     * remembered.** `partialSize()` is the resume offset, and it describes
     * bytes that are certainly stored rather than bytes that were merely
     * sent — which is the one thing local bookkeeping could never promise.
     * The run that died mid-chunk therefore resumes from the truth, and a
     * database restored to last week cannot move it.
     *
     * @param array{archive_path: string, remote_name: string, failures: int} $carried
     * @throws \RuntimeException
     */
    private function send(array $carried, TaskContext $context, ResumableUploadBackend $backend): void
    {
        $archivePath = $carried['archive_path'];
        $remoteName = $carried['remote_name'];
        $size = (int) filesize($archivePath);

        $offset = $backend->partialSize($remoteName);
        if ($offset > $size) {
            // What is stored is longer than the archive it claims to be,
            // so it describes a file that changed under it. Nothing can be
            // salvaged and keeping it would resume into the middle of
            // something else.
            $backend->discardPartial($remoteName);
            $offset = 0;
        }
        if ($offset === 0) {
            $backend->beginPartial($remoteName, $size);
        }

        // **Written down BEFORE a single byte goes.** If this run dies
        // mid-chunk — the process is killed, the host reboots, the request
        // is cut — the next one must still find the archive and the name
        // it is being sent under. Losing those means building a
        // multi-gibibyte archive again from nothing, where the destination
        // is already holding most of one.
        $this->scheduleAfter($context, 0, $carried);

        $handle = @fopen($archivePath, 'rb');
        if ($handle === false) {
            throw RemoteBackupException::of('L\'archive à envoyer n\'a pas pu être lue sur ce serveur.');
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw RemoteBackupException::of('La reprise de la lecture de l\'archive a échoué.');
            }

            $deadline = $this->clock() + self::TIME_BUDGET_SECONDS;
            while ($offset < $size) {
                // Checked between chunks, never inside one: a chunk is a
                // single request and cannot be interrupted politely, so
                // the budget bounds how many are STARTED and the overshoot
                // is at most one — which is what CHUNK_BYTES is sized for.
                if ($this->clock() >= $deadline) {
                    $this->pause($context, $carried);

                    return;
                }

                $chunk = (string) fread($handle, $this->chunkBytes);
                if ($chunk === '') {
                    throw RemoteBackupException::of('La lecture de l\'archive à envoyer s\'est interrompue.');
                }

                // The whole chunk is stored when this returns, or it
                // throws — {@see ResumableUploadBackend::appendToPartial()}
                // — which is what lets this advance by what it wrote
                // instead of asking again between every slice.
                $backend->appendToPartial($remoteName, $chunk);
                $offset += strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        $backend->promotePartial($remoteName, 'application/zip');

        // The cancel goes INSIDE finish(), not here. Once the destination
        // has the last byte, every remaining step is post-delivery
        // bookkeeping and none of it may reach recordFailure() — and
        // `cancelPending()` is a write to the most contended table on the
        // site.
        $this->finish($context, $backend, $archivePath, $remoteName);
    }

    /**
     * Out of budget with bytes still to go: replace the pessimistic row
     * with what this run actually achieved.
     *
     * The failure count goes back to zero, because bytes moved. Not an
     * error, and emphatically not a failed send: on shared hosting this is
     * the ORDINARY outcome of a run, and treating it as anything else
     * would abandon every archive larger than twenty seconds of upstream.
     *
     * @param array{archive_path: string, remote_name: string, failures: int} $carried
     */
    private function pause(TaskContext $context, array $carried): void
    {
        $this->cancelPending($context);
        $this->scheduleAfter($context, 0, array_merge($carried, ['failures' => 0]));
    }

    /**
     * The repository and the factory this task needs, built from the
     * scheduler's own context.
     *
     * Built here rather than injected because a scheduled handler is
     * constructed once at bootstrap and may never run: an S3 client or a
     * decrypted secret assembled on every request, for a task that fires
     * once a day, is work nobody asked for.
     */
    private function destinationFor(TaskContext $context): RemoteBackupDestination
    {
        $repository = new StorageLocationRepository($context->connection->getPdo(), $context->encryption);

        return new RemoteBackupDestination(
            $context->settings,
            $repository,
            new StorageBackendFactory($repository, $context->storagePath)
        );
    }

    /**
     * The archive landed: record it, stop carrying it locally, and bring
     * the destination back inside its bounds.
     *
     * **Nothing in here may throw, and that is the point.** Every line
     * below runs AFTER the destination has taken the last byte, so it is
     * bookkeeping about something that already happened. It used to sit
     * inside the `try` in {@see handle()} whose catch calls
     * {@see recordFailure()} — so a settings table that refused one write
     * turned a delivered archive into a failed send: the failure counter
     * climbed, and at the ceiling the abandonment branch deleted the
     * local archive and journaled « abandonné » for a backup sitting
     * safely on the destination, uncounted, with the next run about to
     * upload the whole thing again.
     *
     * **Every step is guarded, not just the interesting one.** The first
     * version of this guarded the settings stamp and left the journal
     * write and the re-arm bare — so the docblock above was a claim the
     * method did not keep. `JournalRepository::insert()` and the
     * scheduler's writes both go to the database, and a `PDOException`
     * is a `RuntimeException`: a deadlock or a lost connection on the
     * contended `scheduled_actions` table would have escaped into
     * {@see handle()}'s catch, AFTER the local archive was deleted —
     * re-arming a failure whose `archive_path` no longer exists, so the
     * next run rebuilt and re-uploaded gibibytes, and the ceiling
     * journaled a delivered backup as abandoned.
     *
     * Each failure is journaled where there is somewhere to journal it,
     * and swallowed where there is not: the journal is the reporting
     * channel, so a journal that is down cannot report that the journal
     * is down.
     *
     * A re-arm that fails is the one loss with no in-process remedy, and
     * it has an out-of-process one: `public/index.php` seeds this chain
     * on every request ({@see \Core\Scheduler\SchedulerService::seed()}),
     * so the next page view puts it back.
     */
    private function finish(
        TaskContext $context,
        ResumableUploadBackend $backend,
        string $archivePath,
        string $remoteName
    ): void {
        // The pessimistic row this run wrote before sending: a write to
        // `scheduled_actions`, and therefore a thing that can fail. Left
        // behind, the next run picks it up, finds the archive gone and
        // builds a fresh one — wasteful, not wrong, which is why this is
        // absorbed like the rest.
        $this->quietly(
            $context,
            function () use ($context): void {
                $this->cancelPending($context);
            },
            'remote_backup_rearm_failed',
            'L\'envoi hors site a abouti mais la file des tâches n\'a pas pu être mise à jour'
        );

        $this->quietly(
            $context,
            static function () use ($context): void {
                $context->settings->setInternal(
                    self::LAST_SUCCESS_SETTING,
                    (new \DateTimeImmutable())->format('Y-m-d H:i:s')
                );
            },
            'remote_backup_stamp_failed',
            'L\'envoi hors site a abouti mais sa date n\'a pas pu être enregistrée'
        );

        // **Deleted here rather than left for a later pass.** It carries
        // the site's master key; IT-04's quota of one portable archive
        // exists so that such a file does not sit on the server it is
        // meant to outlive, and this one was never asked for by a human.
        $deleted = $this->deleteArchive($context, $archivePath);

        $this->quietly($context, static function () use ($context, $remoteName, $deleted): void {
            $context->journal->log(
                'core',
                'remote_backup_sent',
                'info',
                'Sauvegarde envoyée hors site',
                // The key, not a provider's own file id: the entry is
                // read by somebody looking in their own destination
                // folder, and the name is what they will find there.
                ['remote_name' => $remoteName, 'local_copy_removed' => $deleted]
            );
        });

        $this->purge($context, $backend);

        $this->quietly(
            $context,
            function () use ($context): void {
                $this->scheduleNext($context, []);
            },
            'remote_backup_rearm_failed',
            'L\'envoi hors site a abouti mais la prochaine échéance n\'a pas pu être inscrite'
        );
    }

    /**
     * Runs one piece of post-delivery bookkeeping, absorbing whatever it
     * throws.
     *
     * @param \Closure(): void $step
     * @param string|null $eventType null for the journal write itself —
     *        reporting a journal failure through the journal is circular,
     *        so that one is absorbed in silence rather than pretending to
     *        have somewhere to go.
     */
    private function quietly(
        TaskContext $context,
        \Closure $step,
        ?string $eventType = null,
        string $description = ''
    ): void {
        try {
            $step();
        } catch (\Throwable $e) {
            if ($eventType === null) {
                return;
            }

            try {
                $context->journal->log('core', $eventType, 'warning', $description, ['error' => $e->getMessage()]);
            } catch (\Throwable) {
                // The reporting channel is what failed. There is nowhere
                // left to say so, and saying it by throwing would undo a
                // delivery that actually happened.
            }
        }
    }

    /**
     * Brings the destination back inside both bounds.
     *
     * **Guarded by {@see quietly()} like every other post-delivery step,
     * and not by a `catch` of its own.** It used to roll its own, whose
     * `catch` then called `journal->log()` bare — so a lock-wait on the
     * journal table while reporting a failed purge threw out of here,
     * out of `finish()`, and into `handle()`'s catch, which recorded a
     * delivered archive as a failed send. One guard, written once, is
     * what stops that from being reinvented slightly wrong each time.
     */
    private function purge(TaskContext $context, ResumableUploadBackend $backend): void
    {
        $this->quietly(
            $context,
            static function () use ($context, $backend): void {
                $retention = new RemoteRetention($context->settings);
                $report = $retention->purge($backend, $retention->listArchives($backend));
                if ($report['deleted'] > 0 || $report['failed'] > 0) {
                    $context->journal->log('core', 'remote_backup_purged', 'info',
                        'Archives distantes supprimées au-delà des bornes de conservation', $report);
                }
            },
            'remote_backup_purge_failed',
            'La purge des archives distantes a échoué'
        );
    }

    /**
     * One more failure in a row — and past the ceiling, the send is
     * abandoned rather than retried for ever.
     *
     * **Abandoning drops the archive, not the idea.** The next scheduled
     * run builds a fresh one, which the destination meets under a new name
     * and therefore as a new transfer: a send that has been failing for
     * five runs is likelier to have an expired session behind it than to
     * be unlucky, and Google keeps one only about a week anyway.
     *
     * @param array<string, mixed> $payload
     */
    private function recordFailure(
        array $payload,
        TaskContext $context,
        string $reason,
        ?ResumableUploadBackend $backend = null
    ): void {
        $failures = (int) ($payload['failures'] ?? 0) + 1;
        $ceiling = max(1, (int) ($context->settings->get(self::MAX_FAILURES_SETTING)
            ?: self::DEFAULT_MAX_FAILURES));

        $this->cancelPending($context);

        if ($failures >= $ceiling) {
            $archivePath = (string) ($payload['archive_path'] ?? '');
            if ($archivePath !== '' && is_file($archivePath)) {
                // Same reporting as everywhere else: a key-bearing file
                // that refuses to go must say so rather than be claimed
                // gone.
                $this->deleteArchive($context, $archivePath);
            }

            // **The destination keeps its own note, and nothing else can
            // reach it.** `beginPartial()` writes a `.scoutmagic-part-*`
            // object beside the archive; every backend hides its own
            // internal prefix from `list()`, so `RemoteRetention` cannot
            // see that note and no sweep will ever remove it. Abandoning
            // without this leaks one orphaned object per abandoned send,
            // for ever, into a folder the operator owns.
            //
            // AFTER `deleteArchive()`, never before: the local file
            // carries `master.key`, and a destination that refuses to
            // answer must not be able to keep it on the disk.
            $discarded = $this->discardPartial($payload, $backend);

            $context->journal->log(
                'core',
                'remote_backup_abandoned',
                'warning',
                'Envoi hors site abandonné après plusieurs échecs consécutifs',
                ['failures' => $failures, 'error' => $reason, 'partial_discarded' => $discarded]
            );
            $this->scheduleNext($context, []);

            return;
        }

        $context->journal->log('core', 'remote_backup_failed', 'info',
            'Un envoi hors site a échoué et sera repris', ['failures' => $failures, 'error' => $reason]);

        // What is carried forward is the archive and the count, and
        // nothing about how far the transfer got: the destination is asked
        // that on the next run, which is the whole point of
        // {@see ResumableUploadBackend::partialSize()}. The run that failed
        // may well have committed bytes before it did — under the old
        // payload this is exactly where that had to be guessed at.
        $this->scheduleAfter($context, 0, array_merge($payload, ['failures' => $failures]));
    }

    /**
     * Throws away the note the destination is holding for a transfer no
     * run will resume, and tells the caller whether it went.
     *
     * Returns false, rather than throwing, when the destination refuses:
     * this is reached from the abandonment branch, whose remaining work
     * — the journal line and rearming the chain — matters more than the
     * note, and whose local half has already run. A destination that is
     * unreachable now is also one that cannot be cleaned by any caller,
     * so the alternative to a false here is an exception escaping a
     * failure handler.
     *
     * Silent on the three earlier failure paths, which pass no backend:
     * two of them fail *because* no backend could be built, and the
     * third fails before the archive has a name, so there is nothing
     * there to discard and nothing to discard it with.
     *
     * @param array<string, mixed> $payload
     */
    private function discardPartial(array $payload, ?ResumableUploadBackend $backend): bool
    {
        $remoteName = (string) ($payload['remote_name'] ?? '');
        if ($backend === null || $remoteName === '') {
            return false;
        }

        try {
            $backend->discardPartial($remoteName);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Throws away a local archive no run will ever send.
     *
     * **Nothing else would.** This file is a portable archive — it
     * carries `master.key` and `secrets.enc`, opened by a phrase sitting
     * in `secrets.enc` right beside it — and it is deliberately never
     * registered in `BackupRepository`, so {@see
     * \Core\Alert\Check\PortableBackupLingerCheck} cannot see it: that
     * check queries the `backups` table, it does not walk the disk.
     * Dropping the payload without this would leave the site's own keys
     * in `storage/maintenance/` for ever, which is exactly what
     * SECURITY.md §5 says this feature does not do.
     *
     * Journaled rather than silent: a file holding the master key does
     * not disappear without a line saying so — and, when it turns out not
     * to disappear at all, that is the line that says so instead.
     *
     * @param array<string, mixed> $payload
     */
    private function discardArchive(array $payload, TaskContext $context): void
    {
        $archivePath = (string) ($payload['archive_path'] ?? '');
        if ($archivePath === '' || !is_file($archivePath)) {
            return;
        }

        $this->deleteArchive($context, $archivePath, 'Archive hors site supprimée : aucune destination raccordée');
    }

    /**
     * Removes a local archive and reports what actually happened.
     *
     * **The report branches on the return value, and that is the whole
     * point of this method existing.** Both callers used to journal the
     * deletion unconditionally — so a failed `unlink()` (a permission
     * mismatch between the process that wrote the archive and the one
     * discarding it, a read-only mount) produced a line saying « archive
     * supprimée » over a file still sitting there with `master.key` in
     * it. That claim is worse than no claim here than almost anywhere
     * else in this codebase: this archive is deliberately never
     * registered in `BackupRepository`, so
     * {@see \Core\Alert\Check\PortableBackupLingerCheck} cannot see it
     * either, and the journal line is the ONLY thing that could ever
     * surface a leftover.
     *
     * **The verdict is `is_file()` after the attempt, not `unlink()`'s
     * return value.** What the caller is reporting is whether the file is
     * gone, and those are different questions: an archive a previous run
     * or a sweeper already removed makes `unlink()` answer false while
     * being exactly as absent as one this call deleted. Keying on the
     * return would raise a warning about a leftover that does not exist.
     *
     * The failure entry names the path, because the operator's next move
     * is to go and delete it by hand. A filesystem path is not personal
     * data (AGENTS.md § security checklist), and without it the warning
     * says there is a problem without saying where.
     *
     * @return bool whether the file is gone
     */
    private function deleteArchive(TaskContext $context, string $archivePath, string $onSuccess = ''): bool
    {
        @unlink($archivePath);

        if (!is_file($archivePath)) {
            if ($onSuccess !== '') {
                $this->quietly($context, static function () use ($context, $onSuccess): void {
                    $context->journal->log('core', 'remote_backup_archive_discarded', 'info', $onSuccess);
                });
            }

            return true;
        }

        $this->quietly($context, static function () use ($context, $archivePath): void {
            $context->journal->log('core', 'remote_backup_archive_undeletable', 'warning',
                'Une archive hors site contenant les clés du site n\'a pas pu être supprimée du serveur',
                ['path' => $archivePath]);
        });

        return false;
    }

    /**
     * Builds the portable archive this run will send.
     *
     * @throws \Core\Maintenance\BackupException when the dump or the
     *         archive fails, or the disk budget refuses it the room
     */
    private function buildArchive(TaskContext $context): string
    {
        $basePath = dirname($context->storagePath);
        $backupService = new \Core\Maintenance\BackupService(
            $context->connection,
            $context->storagePath,
            $basePath,
            new \Core\Storage\DiskBudget($context->storagePath, $context->settings),
            \Core\Storage\Location\DeclaredStorageDirectories::fromDatabase(
                $context->connection->getPdo(),
                $context->encryption,
                $context->storagePath
            )
        );

        // The room for the dump AND the archive is reserved by
        // `writeArchive()` itself, in one reading before either starts —
        // so there is no `ensureRoomForDumpAndArchive()` call here, which
        // would charge the same bytes twice against the same budget.
        $passphrase = (new RemotePassphrase($context->settings, $this->secrets($context)))->current();

        $result = $backupService->createPortableBackup(
            $passphrase,
            \Core\Maintenance\VersionFile::read($basePath),
            $this->installationId($context)
        );

        @unlink($result['dbDumpPath']);

        return $result['zipPath'];
    }

    /**
     * The name the archive takes in the operator's Drive.
     *
     * **The passphrase generation is in it**, and that is the point:
     * regenerating makes every archive already sent unreadable, and
     * nothing re-encrypts them. Without the number in the name, an
     * operator facing a folder of archives has no way to tell which
     * phrase opens which — they would try the current one, fail, and
     * conclude the backup was broken.
     */
    private function remoteName(TaskContext $context): string
    {
        $generation = (new RemotePassphrase($context->settings, $this->secrets($context)))->generation();

        // Spelled by RemoteRetention, which is also what recognises the
        // name again when it decides what to purge. One pair, one class:
        // a name this side and a pattern that side would be two things to
        // keep in step, and the purge is where getting it wrong deletes a
        // unit's only off-site copy.
        return RemoteRetention::nameFor(new \DateTimeImmutable(), $generation);
    }

    private function installationId(TaskContext $context): ?string
    {
        $id = (string) ($context->settings->get('installation_id') ?: '');

        return $id !== '' ? $id : null;
    }

    private function secrets(TaskContext $context): SecretManager
    {
        return new SecretManager(
            $context->storagePath . '/keys/master.key',
            $context->storagePath . '/config/secrets.enc'
        );
    }

    private function clock(): float
    {
        return $this->now !== null ? (float) ($this->now)() : microtime(true);
    }

    /** @param array<string, mixed> $payload */
    private function scheduleNext(TaskContext $context, array $payload): void
    {
        $this->scheduleAfter($context, self::INTERVAL_HOURS * 3600, $payload);
    }

    /**
     * Queue the next run of this chain.
     *
     * **`rearmAfter()`, never `scheduleAfter()`.** The guarded twin is
     * what keeps a chain at one row: every caller below cancels the row
     * it is replacing first, so the guard finds nothing and queues; if
     * anything ever leaves a pending row behind, the guard collapses the
     * duplicate instead of running two uploads against one resumable
     * session (SchedulerService::rearmAfter(), and what an unguarded
     * re-arm once cost `sync_mailboxes`).
     *
     * @param array<string, mixed> $payload
     */
    private function scheduleAfter(TaskContext $context, int $delaySeconds, array $payload): void
    {
        $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $scheduler->rearmAfter('core', self::TASK_KEY, self::REFERENCE, $delaySeconds, $payload);
    }

    private function cancelPending(TaskContext $context): void
    {
        $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $scheduler->cancelPending('core', self::TASK_KEY, self::REFERENCE);
    }
}
