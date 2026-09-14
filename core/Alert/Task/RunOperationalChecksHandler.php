<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Task;

use Core\Alert\Check\BackupAgeCheck;
use Core\Alert\Check\BackupIntegrityCheck;
use Core\Alert\Check\DevelopmentModeCheck;
use Core\Alert\Check\DiskUsageCheck;
use Core\Alert\Check\AuthenticationLaneCheck;
use Core\Alert\Check\DeferredMailBacklogCheck;
use Core\Alert\Check\MailDeliveryCheck;
use Core\Alert\Check\PortableBackupLingerCheck;
use Core\Alert\Check\RemoteBackupAgeCheck;
use Core\Alert\Check\RemoteQuotaCheck;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Storage\Location\Backend\QuotaReportingBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\StorageLocationRepository;
use Core\Alert\OperationalAlertRepository;
use Core\Alert\OperationalAlertService;
use Core\Journal\JournalRepository;
use Core\Maintenance\BackupRepository;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Security\SecretManager;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Storage\DiskBudget;

/**
 * The daily operational pass: disk, backup age, backup integrity, e-mail
 * delivery, and whether development mode has been left on.
 *
 * Self-reschedules at the end of every run rather than being a first-class
 * recurring task, because `Core\Scheduler` has no such concept — the same
 * shape as `Task\AutoBackupHandler` and
 * `Core\Notification\Task\PurgeNotificationsHandler`.
 *
 * **Two checks are deliberately absent from this list**, and both for the
 * same kind of reason. `CronSilenceCheck` cannot live here: if the cron
 * has stopped, this task never runs, and an alert about the cron that
 * lives inside the cron never fires. `HttpsCheck` cannot either: a scheme
 * belongs to a request, and this runs on the CLI where there is none. Both
 * are evaluated on an ordinary web request instead, in `public/index.php`.
 *
 * Daily, not hourly. Every one of these is a slow-moving fact — a disk
 * fills over weeks, a backup ages by a day per day — and the notification
 * only ever goes out on a transition, so a faster pass would buy nothing
 * and would walk `storage/` twenty-four times as often.
 */
class RunOperationalChecksHandler implements TaskHandlerInterface
{
    public const REFERENCE = 'daily';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $service = new OperationalAlertService(
            new OperationalAlertRepository($pdo),
            $context->notifications,
            $context->journal
        );

        $service->run([
            new DiskUsageCheck(new DiskBudget($context->storagePath, $context->settings)),
            new BackupAgeCheck(new BackupRepository($pdo)),
            // Counts what Core\Maintenance\Task\VerifyBackupIntegrityHandler
            // wrote; it re-reads nothing itself, which is what keeps this
            // pass as cheap as its own docblock claims.
            new BackupIntegrityCheck(new BackupRepository($pdo)),
            // Fires on a backup that SUCCEEDED and was never taken away:
            // the archive holds the master key, so a copy left in
            // storage/ is the site's encryption sitting next to what it
            // protects (Core\Alert\Check\PortableBackupLingerCheck).
            new PortableBackupLingerCheck(new BackupRepository($pdo)),
            // « Could we get the site back if the hosting account itself
            // were gone » — a different question from BackupAgeCheck's,
            // and one a site backing up perfectly to its own disk fails
            // completely (Core\Alert\Check\RemoteBackupAgeCheck).
            new RemoteBackupAgeCheck($this->remoteDestination($context), $context->settings),
            new RemoteQuotaCheck($this->remoteBackend($context)),
            new MailDeliveryCheck(new JournalRepository($pdo)),
            // The two the outbound chantier adds (D9, ARCHITECTURE.md
            // §8.106). Both are built here rather than inside the check,
            // the same reason RemoteQuotaCheck's backend is: a check has to
            // stay something a test can hand a double to.
            new AuthenticationLaneCheck(
                new LaneChainRepository($pdo),
                new MailProviderDirectory(
                    new MailProviderRepository($pdo),
                    new ProviderConnections($this->mailSecrets($context)),
                    $context->settings
                ),
                new SendCounterRepository($pdo)
            ),
            new DeferredMailBacklogCheck(new DeferredMailRepository($pdo, $context->encryption)),
            new DevelopmentModeCheck($context->settings),
        ]);

        $this->scheduleNext($context);
    }

    /**
     * Which location the off-site backup sends to, for the two checks
     * that measure it.
     *
     * Built here rather than inside a check so that the check itself
     * stays a thing a test can hand a double to, and so the ONE network
     * call this daily pass makes is visible at the place the pass is
     * assembled.
     */
    private function remoteDestination(TaskContext $context): RemoteBackupDestination
    {
        $repository = new StorageLocationRepository($context->connection->getPdo(), $context->encryption);

        return new RemoteBackupDestination(
            $context->settings,
            $repository,
            new StorageBackendFactory($repository, $context->storagePath)
        );
    }

    /**
     * The destination to ask about free space, or null when there is
     * nothing to ask.
     *
     * **Null means "nothing to measure", never "the grant is gone".**
     * Null is what makes the check re-arm, and re-arming is « all clear »
     * — so returning it for a destination that has stopped accepting
     * anything would put the quota alert out on exactly the site that
     * needs it. A backend is therefore built for that site too; every
     * call it makes fails, and {@see RemoteQuotaCheck} turns that into
     * *inconclusive*, which leaves a standing alert where it was.
     *
     * Null is also the honest answer for a destination that simply cannot
     * say — a bucket, a folder on this server's own disk — which is what
     * the capability check below is reading, not a Drive-shaped guess.
     */
    private function remoteBackend(TaskContext $context): ?QuotaReportingBackend
    {
        try {
            $backend = $this->remoteDestination($context)->backend();
        } catch (\Throwable) {
            // A destination that cannot even be built is not a quota
            // reading and not an alert of this kind: the send that fails
            // reports it, once, in the operator's own terms.
            return null;
        }

        return $backend instanceof QuotaReportingBackend ? $backend : null;
    }

    /**
     * The connection values the relays live in, or none.
     *
     * Read defensively because this pass must survive an installation
     * that has no `secrets.enc` yet — a fresh checkout, or a restore in
     * progress. Without secrets every relay resolves to an empty host and
     * so counts as unusable, which is the honest reading: nothing can be
     * reached through a relay whose address nobody can read.
     *
     * @return array<string, mixed>
     */
    private function mailSecrets(TaskContext $context): array
    {
        try {
            return $this->secrets($context)->readSecrets();
        } catch (\Throwable) {
            return [];
        }
    }

    private function secrets(TaskContext $context): SecretManager
    {
        return new SecretManager(
            $context->storagePath . '/keys/master.key',
            $context->storagePath . '/config/secrets.enc'
        );
    }

    /**
     * Tomorrow, at a jittered hour.
     *
     * The jitter is the same reasoning as `Task\CheckStableUpdateHandler`'s:
     * without it every installation on earth would run its pass at the same
     * second. Here nothing outbound depends on it, so the spread is a
     * courtesy to shared hosting rather than to an API — but the cost is a
     * single `random_int()` and the habit is worth keeping.
     *
     * Re-checks for an already-pending occurrence before scheduling, so a
     * run that overlaps one never leaves two due at once — the same guard
     * every self-rescheduling handler here carries.
     */
    private function scheduleNext(TaskContext $context): void
    {
        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));

        $existing = $schedulerService->find('core', 'operational_checks', self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $next = (new \DateTimeImmutable('tomorrow 04:00'))->modify('+' . random_int(0, 3600) . ' seconds');
        $schedulerService->rearm('core', 'operational_checks', self::REFERENCE, $next);
    }
}
