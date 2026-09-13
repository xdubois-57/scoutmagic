<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Task;

use Core\Notification\NotificationRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\MassMail\Repository\AudienceRepository;

/**
 * RGPD retention for mail-merge audiences (imported personal data):
 * - an audience whose referencing email(s) are all 'sent' is deleted
 *   `merge_retention_months` (18, read-only setting) after the most
 *   recent send;
 * - an orphan audience (imported but never attached to any email — an
 *   abandoned dialog, or replaced by a re-import) is deleted after
 *   ORPHAN_RETENTION_DAYS;
 * - an audience still referenced by any draft/test/sending email is
 *   never touched, however old.
 * Deleting an audience nulls mass_mail_emails.audience_id and
 * mass_mail_recipients.audience_row_id — the sent email and its tracking
 * history survive, only the merge values are gone. The external-address
 * suppression list is deliberately NOT purged here: an unsubscribe must
 * outlive any retention window.
 *
 * The « Nouvel email » notifications of sent merges go on the same horizon,
 * for the same reason and in the same pass — see the call below. That purge
 * is what lets one of them carry a substituted subject at all (issue #292).
 *
 * Self-reschedules daily (same pattern as Modules\Registration\Task\
 * PurgeRegistrationRequestsHandler), bootstrapped once from
 * public/index.php.
 */
class PurgeMergeAudiencesHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'daily';
    private const INTERVAL_SECONDS = 86400;
    private const DEFAULT_RETENTION_MONTHS = 18;
    private const ORPHAN_RETENTION_DAYS = 7;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $repository = new AudienceRepository($pdo, $context->encryption);

        $months = (int) ($context->settings->get(
            'merge_retention_months',
            'mass_mail'
        ) ?: self::DEFAULT_RETENTION_MONTHS);
        if ($months <= 0) {
            $months = self::DEFAULT_RETENTION_MONTHS;
        }

        $now = new \DateTimeImmutable();
        $ids = array_values(array_unique(array_merge(
            $repository->findPurgeableSentAudienceIds($now->modify("-{$months} months")),
            $repository->findOrphanAudienceIds($now->modify('-' . self::ORPHAN_RETENTION_DAYS . ' days'))
        )));

        foreach ($ids as $id) {
            $repository->deleteById($id);
        }

        // The « Nouvel email » notifications go on the same horizon, and
        // that is what lets one of them carry a substituted subject at all
        // (issue #292). `notifications.body` is written once at dispatch
        // and never recomputed, so a subject like « Camp de Kaa » stored
        // there would otherwise outlive the audience it came from —
        // indefinitely, since the core retention purge only ever touches
        // notifications somebody has read.
        //
        // Same cutoff, computed once, in the same pass: a notification is
        // created when its email is sent, and an audience is deleted
        // `$months` after the most recent send that references it. Running
        // both here rather than from two daily tasks is what removes the
        // skew between them — the alternative was a `LIKE` over
        // `notifications.url`, the only column that links the two today,
        // or a correlation column in a core table to serve one module.
        $notificationsPurged = (new NotificationRepository($pdo, $context->encryption))
            ->deleteOfTypeOlderThan('mass_mail.email_received', $now->modify("-{$months} months"));

        if ($ids !== [] || $notificationsPurged > 0) {
            $context->journal->log(
                'mass_mail',
                'merge_audiences_purged',
                'info',
                'Suppression définitive d\'audiences de publipostage (rétention dépassée)',
                // Counts and a retention, never a recipient or a subject
                // (ARCHITECTURE.md §7.9) — this task exists to make such
                // values stop existing, so it cannot write one down.
                [
                    'count' => count($ids),
                    'notifications' => $notificationsPurged,
                    'retention_months' => $months,
                ]
            );
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->rearmAfter('mass_mail', 'purge_merge_audiences', self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
