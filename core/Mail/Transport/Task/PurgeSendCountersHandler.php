<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport\Task;

use Core\Mail\Transport\SendCounterRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Drops `mail_send_counters` rows nothing reads any more
 * (ARCHITECTURE.md §8.106).
 *
 * Two readers decide the window. The quota reads today's rows; the
 * reserve reads the last thirty days (D8). The retention is deliberately
 * wider than the second of those, because the peak the reserve is built
 * on is a number an administrator is shown and may want to understand
 * — "where does that 42 come from" is unanswerable the day the rows behind it are
 * gone. Ninety days is three times the window and still a table of a few
 * hundred rows.
 *
 * Self-reschedules at the end of every run, the same precedent as
 * `Core\Mail\Task\PurgeSentEmailClaimsHandler`, and is declared once in
 * `Core\Scheduler\CoreTaskHandlers` so both entry points register it.
 */
class PurgeSendCountersHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_mail_send_counters';
    public const REFERENCE = 'daily';

    public const RETENTION_DAYS = 90;

    private const INTERVAL_SECONDS = 86400;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $cutoff = (new \DateTimeImmutable('-' . self::RETENTION_DAYS . ' days'))->format('Y-m-d');
        $dropped = (new SendCounterRepository($pdo))->purgeOlderThan($cutoff);

        if ($dropped > 0) {
            $context->journal->log(
                'core',
                'mail_send_counters_purged',
                'info',
                'Purge des compteurs d\'envoi expirés',
                ['dropped' => $dropped, 'retention_days' => self::RETENTION_DAYS]
            );
        }

        (new SchedulerService(new SchedulerRepository($pdo)))
            ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
