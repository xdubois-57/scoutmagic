<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Task;

use Core\Mail\SentEmailClaimRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Drops `sent_email_claims` rows past the retention window.
 *
 * A claim's scope names its own occurrence — a campaign's close date, an
 * event id, a form response id — so it can never guard a LATER send, and
 * a row older than the window is dead weight. The window is generous on
 * purpose: the longest-lived claim here belongs to the re-enrolment
 * campaign, whose four e-mails span the weeks around one close date, and
 * losing a claim while its campaign is still running would let a replay
 * write to a family twice — the very thing the table exists to prevent.
 *
 * Self-reschedules at the end of every run rather than being a
 * first-class recurring task, same precedent as Core\Security\HumanCheck
 * \Task\PurgeHumanCheckRateLimitsHandler and Core\Notification\Task\
 * PurgeNotificationsHandler (Core\Scheduler has no first-class recurring
 * -task concept). Declared once in Core\Scheduler\CoreTaskHandlers, so
 * both entry points register it.
 */
class PurgeSentEmailClaimsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_sent_email_claims';
    public const REFERENCE = 'daily';

    /** Six months — several times the longest campaign this guards. */
    public const RETENTION_DAYS = 180;

    private const INTERVAL_SECONDS = 86400;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $cutoff = (new \DateTimeImmutable('-' . self::RETENTION_DAYS . ' days'))->format('Y-m-d H:i:s');
        $dropped = (new SentEmailClaimRepository($pdo))->deleteClaimedBefore($cutoff);

        if ($dropped > 0) {
            $context->journal->log(
                'core',
                'sent_email_claims_purged',
                'info',
                'Purge des marqueurs d\'envoi expirés',
                ['dropped' => $dropped, 'retention_days' => self::RETENTION_DAYS]
            );
        }

        (new SchedulerService(new SchedulerRepository($pdo)))
            ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
