<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc\Task;

use Core\Mail\Feedback\Dmarc\DmarcReportRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Drops the DMARC reports nobody reads any more (roadmap IT-06).
 *
 * **Operational data, so it purges.** These are counters about servers,
 * not about people — but a table that only ever grows is its own problem,
 * and a year of reports from four providers is already tens of thousands
 * of lines on a site nobody is watching.
 *
 * **Ninety days, cut on the period's END.** The screen reports on thirty,
 * so ninety leaves two further windows for somebody to answer « depuis
 * quand ? » about a source they have just noticed. Cutting on the end
 * rather than on arrival matters more than it looks: a reporter can post
 * a report days after the window it describes, and a cut on arrival would
 * remove it for being old the moment it landed.
 *
 * Self-reschedules at the end of every run, the same precedent as
 * {@see \Core\Mail\Transport\Task\PurgeSendCountersHandler}, and is
 * declared once in `Core\Scheduler\CoreTaskHandlers` so both entry points
 * register it.
 */
class PurgeDmarcReportsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_mail_dmarc_reports';
    public const REFERENCE = 'daily';

    public const RETENTION_DAYS = 90;

    private const INTERVAL_SECONDS = 86400;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $cut = new \DateTimeImmutable('-' . self::RETENTION_DAYS . ' days');
        $dropped = (new DmarcReportRepository($pdo))->purgeBefore($cut);

        if ($dropped > 0) {
            $context->journal->log(
                'core',
                'mail_dmarc_reports_purged',
                'info',
                'Purge des rapports DMARC expirés',
                ['dropped' => $dropped, 'retention_days' => self::RETENTION_DAYS]
            );
        }

        (new SchedulerService(new SchedulerRepository($pdo)))
            ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
