<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Config\SettingService;
use Core\Maintenance\Remote\GoogleDriveClient;
use Core\Maintenance\Remote\GoogleDriveTarget;
use Core\Maintenance\Remote\RemoteBackupConnection;
use Core\Maintenance\Remote\RemoteBackupTarget;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Maintenance\Remote\RemoteRetention;
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
 * between runs: a notification batch is a list of ids, and here it is a
 * resumable session URI plus an offset, so a send crosses as many runs as
 * it needs without ever restarting.
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

    public const INCLUDE_GALLERY_SETTING = 'backup_remote_include_gallery';
    public const MAX_FAILURES_SETTING = 'backup_remote_max_failures';
    public const LAST_SUCCESS_SETTING = 'backup_remote_last_success';

    public const DEFAULT_MAX_FAILURES = 5;

    public function __construct(
        private readonly ?RemoteBackupTarget $target = null,
        private readonly ?\Closure $now = null
    ) {
    }

    /**
     * Declares what the recurring send is allowed to do and what it last
     * managed to do.
     */
    public static function register(SettingService $settings): void
    {
        $settings->register(self::INCLUDE_GALLERY_SETTING, '0', 'boolean',
            'Envoyer aussi la galerie hors site',
            'La galerie est exclue par défaut : un envoi hebdomadaire de plusieurs gigaoctets remplit un '
            . 'Drive gratuit en trois semaines. Les photos restent alors sur le serveur uniquement.',
            null, null, null, true, 322);
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
        $connection = new RemoteBackupConnection($context->settings, $this->secrets($context));
        if (!$connection->isConnected()) {
            // No destination connected: nothing to send, and nothing
            // wrong. The chain keeps ticking so that connecting one later
            // starts the sends without anybody re-arming it by hand.
            //
            // **But a send may have been under way.** A destination goes
            // from connected to not WHILE a multi-run upload is crossing
            // runs — an operator disconnects, or Google withdraws the
            // grant and `markNeedsReauthorisation()` clears the token —
            // and this branch then fires on the very next run. The
            // half-sent archive it was carrying has to go with the
            // payload it lived in.
            $this->discardArchive($payload, $context);
            $this->scheduleNext($context, []);

            return;
        }

        $target = $this->target ?? new GoogleDriveTarget($connection, new GoogleDriveClient());

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
            'session_url' => $fresh ? '' : (string) ($payload['session_url'] ?? ''),
            'offset' => $fresh ? 0 : (int) ($payload['offset'] ?? 0),
            'offset_is_certain' => $fresh ? true : ($payload['offset_is_certain'] ?? true) !== false,
            'failures' => (int) ($payload['failures'] ?? 0),
        ];

        try {
            // By reference, and that is not a style choice: `send()` opens
            // the resumable session, and a failure two lines later has to
            // report the payload INCLUDING that session. Passing a copy
            // sent `recordFailure()` the pre-send state, whose
            // `session_url` is still empty on a fresh archive — so the
            // next run opened a second session and pushed a multi-gibibyte
            // archive from byte zero again, leaving an orphan session on
            // the destination. Every failure cost one whole upload on
            // exactly the shared hosting this chunked design exists for.
            $this->send($carried, $context, $target);
        } catch (\RuntimeException $e) {
            // RemoteBackupException, BackupException and
            // InsufficientDiskSpaceException are all RuntimeExceptions,
            // and all three mean the same thing to the run that catches
            // them: nothing more left this server, and the next run picks
            // the same archive back up.
            $this->recordFailure($carried, $context, $e->getMessage());
        }
    }

    /**
     * The archive this run sends: the one a previous run left behind, or
     * a new one.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: string, 2: bool} path, remote name, and
     *         whether it was built just now — a fresh archive invalidates
     *         any session the payload carried, since a resumable URI is
     *         bound to the length and the bytes of the file it was opened
     *         for.
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
     * @param array{archive_path: string, remote_name: string, session_url: string, offset: int,
     *     offset_is_certain: bool, failures: int} $carried **by reference**: the
     *     session this run opens has to reach the caller's catch, or a
     *     failure re-arms on a payload that predates `beginUpload()`
     * @throws \Core\Maintenance\BackupException
     */
    private function send(array &$carried, TaskContext $context, RemoteBackupTarget $target): void
    {
        $archivePath = $carried['archive_path'];
        $size = (int) filesize($archivePath);
        $sessionUrl = $carried['session_url'];
        $offset = $carried['offset'];

        if ($sessionUrl === '') {
            $sessionUrl = $target->beginUpload($carried['remote_name'], $size);
            $offset = 0;
            // Straight back into the caller's copy, BEFORE a single byte
            // is sent. Everything below can throw, and the catch in
            // handle() re-arms from this array.
            $carried['session_url'] = $sessionUrl;
            $carried['offset'] = 0;
            $carried['offset_is_certain'] = true;
        } elseif (!$carried['offset_is_certain']) {
            // The previous run died without saying how far it got. Only
            // the destination knows, and its answer beats any guess this
            // application could make from what it had handed over.
            $probe = $target->probeUpload($sessionUrl, $size);
            if ($probe->isComplete()) {
                // Nothing to cancel: this run has written no row yet, and
                // the one it was claimed from is `processing`.
                $this->finish($context, $target, $archivePath, $probe->fileId);

                return;
            }
            $offset = $probe->offset;
            $carried['offset'] = $offset;
            $carried['offset_is_certain'] = true;
        }

        // **Written down BEFORE the chunks go.** If this run dies mid-
        // chunk — the process is killed, the host reboots, the request is
        // cut — the next one must still know that a session exists and
        // that its offset is a guess. Losing the session URI means
        // starting a multi-gibibyte archive again from nothing.
        $this->scheduleAfter($context, 0, [
            'archive_path' => $archivePath,
            'remote_name' => $carried['remote_name'],
            'session_url' => $sessionUrl,
            'offset' => $offset,
            'offset_is_certain' => false,
            'failures' => $carried['failures'],
        ]);

        $deadline = $this->clock() + self::TIME_BUDGET_SECONDS;
        $upload = $target->sendChunks(
            $sessionUrl,
            $archivePath,
            $size,
            $offset,
            fn(): bool => $this->clock() < $deadline
        );

        $this->cancelPending($context);

        if ($upload->isComplete()) {
            $this->finish($context, $target, $archivePath, $upload->fileId);

            return;
        }

        // Still going: replace the pessimistic row with what this run
        // actually achieved, so the next one resumes without a probe. The
        // failure count goes back to zero — bytes moved.
        $this->scheduleAfter($context, 0, [
            'archive_path' => $archivePath,
            'remote_name' => $carried['remote_name'],
            'session_url' => $sessionUrl,
            'offset' => $upload->offset,
            'offset_is_certain' => true,
            'failures' => 0,
        ]);
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
     * The stamp is therefore guarded on its own, like the purge below it:
     * a lost `LAST_SUCCESS_SETTING` makes the age alert pessimistic,
     * which is a wrong reading in the safe direction, and it is journaled
     * rather than swallowed.
     */
    private function finish(
        TaskContext $context,
        RemoteBackupTarget $target,
        string $archivePath,
        string $fileId
    ): void {
        try {
            $context->settings->setInternal(
                self::LAST_SUCCESS_SETTING,
                (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            );
        } catch (\Throwable $e) {
            $context->journal->log('core', 'remote_backup_stamp_failed', 'warning',
                'L\'envoi hors site a abouti mais sa date n\'a pas pu être enregistrée',
                ['error' => $e->getMessage()]);
        }

        // **Deleted here rather than left for a later pass.** It carries
        // the site's master key; IT-04's quota of one portable archive
        // exists so that such a file does not sit on the server it is
        // meant to outlive, and this one was never asked for by a human.
        @unlink($archivePath);

        $context->journal->log(
            'core',
            'remote_backup_sent',
            'info',
            'Sauvegarde envoyée hors site',
            ['remote_id' => $fileId]
        );

        $this->purge($context, $target);
        $this->scheduleNext($context, []);
    }

    /**
     * Brings the destination back inside both bounds.
     *
     * Guarded: an upload that worked is worth recording even if the
     * tidying after it does not, and a purge that throws must not make
     * the next run believe the send failed.
     */
    private function purge(TaskContext $context, RemoteBackupTarget $target): void
    {
        try {
            $retention = new RemoteRetention($context->settings);
            $report = $retention->purge($target, $target->list());
            if ($report['deleted'] > 0 || $report['failed'] > 0) {
                $context->journal->log('core', 'remote_backup_purged', 'info',
                    'Archives distantes supprimées au-delà des bornes de conservation', $report);
            }
        } catch (\Throwable $e) {
            $context->journal->log('core', 'remote_backup_purge_failed', 'info',
                'La purge des archives distantes a échoué', ['error' => $e->getMessage()]);
        }
    }

    /**
     * One more failure in a row — and past the ceiling, the send is
     * abandoned rather than retried for ever.
     *
     * **Abandoning drops the session, not the idea.** The next scheduled
     * run opens a fresh one and builds a fresh archive: a session URI
     * that has been failing for five runs is likelier expired than
     * unlucky, and Google keeps one only about a week anyway.
     *
     * @param array<string, mixed> $payload
     */
    private function recordFailure(array $payload, TaskContext $context, string $reason): void
    {
        $failures = (int) ($payload['failures'] ?? 0) + 1;
        $ceiling = max(1, (int) ($context->settings->get(self::MAX_FAILURES_SETTING)
            ?: self::DEFAULT_MAX_FAILURES));

        $this->cancelPending($context);

        if ($failures >= $ceiling) {
            $archivePath = (string) ($payload['archive_path'] ?? '');
            if ($archivePath !== '') {
                @unlink($archivePath);
            }

            $context->journal->log('core', 'remote_backup_abandoned', 'warning',
                'Envoi hors site abandonné après plusieurs échecs consécutifs',
                ['failures' => $failures, 'error' => $reason]);
            $this->scheduleNext($context, []);

            return;
        }

        $context->journal->log('core', 'remote_backup_failed', 'info',
            'Un envoi hors site a échoué et sera repris', ['failures' => $failures, 'error' => $reason]);

        $this->scheduleAfter($context, 0, array_merge($payload, [
            'failures' => $failures,
            // The run that failed may have committed bytes before it did.
            'offset_is_certain' => false,
        ]));
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
     * not disappear without a line saying so.
     *
     * @param array<string, mixed> $payload
     */
    private function discardArchive(array $payload, TaskContext $context): void
    {
        $archivePath = (string) ($payload['archive_path'] ?? '');
        if ($archivePath === '' || !is_file($archivePath)) {
            return;
        }

        @unlink($archivePath);
        $context->journal->log('core', 'remote_backup_archive_discarded', 'info',
            'Archive hors site supprimée : plus aucune destination n\'est raccordée');
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
            new \Core\Storage\DiskBudget($context->storagePath, $context->settings)
        );

        // The room for the dump AND the archive is reserved by
        // `writeArchive()` itself, in one reading before either starts —
        // so there is no `ensureRoomForDumpAndArchive()` call here, which
        // would charge the same bytes twice against the same budget.
        $passphrase = (new RemotePassphrase($context->settings, $this->secrets($context)))->current();

        $result = $backupService->createPortableBackup(
            $passphrase,
            \Core\Maintenance\VersionFile::read($basePath),
            $this->installationId($context),
            $this->includesGallery($context)
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

    /**
     * Whether the photographs travel too.
     *
     * **Off by default, and the default is the one that matters.** A
     * gallery is measured in gibibytes; sent weekly it fills a free Drive
     * in about three weeks, after which nothing leaves the server at all
     * and the feature has quietly stopped protecting anything. A unit
     * with room to spare can say so.
     */
    private function includesGallery(TaskContext $context): bool
    {
        return (string) ($context->settings->get(self::INCLUDE_GALLERY_SETTING) ?: '0') === '1';
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
