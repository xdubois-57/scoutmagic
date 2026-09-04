<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Task;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\SupportDashboard\Repository\SupportTicketAnalysisRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;

/**
 * Retention for tickets and their diagnostic archives
 * (`support_dashboard`/`purge_tickets`, daily, self-rescheduling — the
 * shape `PurgeInstallationsHandler` established, roadmap IT-28).
 *
 * **Two clocks, because two things are being kept for different reasons.**
 *
 * The **archive** is somebody else's server logs — IP addresses, internal
 * identifiers, whatever their host writes down — transmitted once, by an
 * explicit act, to answer one question (§8.48). It goes ninety days after
 * the ticket is closed, and at the latest a year after the ticket was
 * created whether anybody closed it or not. That second bound is the one
 * that matters: a ticket nobody ever closed is the normal fate of one
 * nobody could reproduce, and « on l'a gardée parce que personne n'a
 * cliqué » is not a retention policy.
 *
 * The **ticket** — its category, its dates, its description and the
 * resolution note — is kept two years, because « ce problème, on l'a déjà
 * vu » is only answerable from tickets that are still there. It weighs
 * nothing next to the archive, and losing it loses the corpus.
 *
 * **Deleting an archive deletes the file, not the reference to it.**
 * `EncryptedFileStorageService::delete()` removes the bytes on disk AND
 * the `files` row; clearing `archive_file_id` alone would leave an
 * encrypted archive on the receiver's disk that nothing points at and
 * nobody remembers.
 */
class PurgeTicketsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_tickets';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    public function handle(array $payload, TaskContext $context): void
    {
        try {
            $pdo = $context->connection->getPdo();
            $tickets = new SupportTicketRepository($pdo, $context->encryption);
            $fileRepository = new FileRepository($pdo);
            $storage = new EncryptedFileStorageService(
                $fileRepository,
                $context->encryption,
                $context->storagePath
            );

            $now = new \DateTimeImmutable();
            $archives = 0;

            foreach ($tickets->findArchivesToPurge($now) as $entry) {
                // The file first, the reference second: the reverse order
                // would leave an orphaned archive on disk if this threw
                // between the two, which is the one outcome retention
                // exists to prevent.
                $storage->delete($entry['archive_file_id']);
                $tickets->detachArchive($entry['id']);
                $archives++;
            }

            $cutoff = $now->modify('-' . SupportTicketRepository::TICKET_RETENTION_DAYS . ' days');
            $expired = $tickets->findIdsCreatedBefore($cutoff);
            foreach ($expired as $id) {
                $tickets->delete($id);
            }

            // A stored analysis is a summary OF those descriptions, so it
            // cannot outlive them: keeping it would be holding a digest of
            // texts that no longer exist anywhere.
            $analyses = (new SupportTicketAnalysisRepository($pdo, $context->encryption))
                ->deleteOlderThan($cutoff);

            if ($archives > 0 || $expired !== [] || $analyses > 0) {
                // Counts only — which installation reported what is not
                // something a retention entry needs to carry forward.
                $context->journal->log(
                    'support_dashboard',
                    'support_tickets_purged',
                    'info',
                    sprintf(
                        '%d archive(s) de diagnostic supprimée(s), %d ticket(s) supprimé(s), %d analyse(s) '
                            . 'supprimée(s).',
                        $archives,
                        count($expired),
                        $analyses
                    ),
                    ['archives' => $archives, 'tickets' => count($expired), 'analyses' => $analyses]
                );
            }
        } finally {
            // Rescheduled whatever happened, exactly like the other daily
            // tasks: a purge that threw must not be a purge that stops.
            (new SchedulerService(new SchedulerRepository($context->connection->getPdo())))
                ->rearmAfter('support_dashboard', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
        }
    }
}
