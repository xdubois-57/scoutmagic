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
 * The « Nouvel email » notification of each recipient frozen from a purged
 * audience goes with it, in the same pass and for the same reason — see the
 * call below. That purge is what lets one of them carry a substituted
 * subject at all (issue #292). A notification of an ordinary, non-merge send
 * is never touched: it carries the one subject everybody got.
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

        // Read BEFORE the deletion loop: `mass_mail_recipients.audience_row_id`
        // is what ties a notification's recipient to the audience it was
        // frozen from, and deleteById() sets it to NULL. Afterwards there
        // is no way left to find these rows.
        $notificationLinks = $repository->findRecipientEmailLinksForAudiences($ids);

        foreach ($ids as $id) {
            $repository->deleteById($id);
        }

        // The « Nouvel email » notifications of these same recipients go
        // with them, and that is what lets one of them carry a substituted
        // subject at all (issue #292). `notifications.body` is written once
        // at dispatch and never recomputed, so a subject like « Camp de
        // Kaa » stored there would otherwise outlive the audience it came
        // from — indefinitely, since the core retention purge only ever
        // touches notifications somebody has read.
        //
        // The correlation is the notification's own url, rebuilt for the
        // recipients just erased, rather than the `type_id` and an age.
        // That distinction is not cosmetic: Task\SendBatchHandler
        // dispatches this one declared type for EVERY send, so purging by
        // type would also delete the notification of an ordinary list send
        // — unread, carrying nothing personal, under a setting whose own
        // label is about imported publipostage files. Naming the rows is
        // what keeps this a merge retention.
        //
        // It also makes the two erasures the same decision rather than two
        // that agree today: an audience still referenced by a draft is not
        // purged, and now neither is its notification. There is no cutoff
        // here at all — `$ids` already is the answer.
        $notificationsPurged = (new NotificationRepository($pdo, $context->encryption))
            ->deleteOfTypeWithUrls('mass_mail.email_received', $notificationLinks);

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
