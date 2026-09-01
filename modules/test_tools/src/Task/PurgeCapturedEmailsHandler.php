<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Task;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\TestTools\Repository\CapturedEmailRepository;
use Modules\TestTools\Service\MailSandboxService;

/**
 * Retention for the mail sandbox (`test_tools`/`purge_captured_emails`,
 * daily, self-rescheduling — same shape as
 * Modules\SupportDashboard\Task\PurgeInstallationsHandler).
 *
 * **Bounded by COUNT, not by age**, and the difference is the whole point:
 * the bound that matters is the one that keeps the opt-in decrypted body
 * search affordable (ARCHITECTURE.md §8.63). A sandbox left armed
 * overnight can collect thousands of messages in an hour; "delete anything
 * older than thirty days" would not have removed one of them.
 *
 * The excess goes oldest first, **rows and encrypted files together** — a
 * row without its files is a detail page that cannot open, and a file
 * without its row is a blob nothing will ever delete.
 */
class PurgeCapturedEmailsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_captured_emails';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    /**
     * How many messages one run may delete. A cap on the run, not on the
     * rule: the next run picks up whatever is still in excess, so a sandbox
     * that collected 50 000 messages drains over a few days instead of
     * holding one request open while it deletes them all.
     */
    public const MAX_PER_RUN = 1000;

    public function handle(array $payload, TaskContext $context): void
    {
        try {
            $pdo = $context->connection->getPdo();
            $repository = new CapturedEmailRepository($pdo, $context->encryption);
            $sandbox = new MailSandboxService(
                $repository,
                $context->settings,
                new EncryptedFileStorageService(
                    new FileRepository($pdo),
                    $context->encryption,
                    $context->storagePath
                ),
                $context->journal
            );

            $deleted = $sandbox->purge(self::MAX_PER_RUN);

            if ($deleted > 0) {
                // A count and the retained bound, nothing else: no subject,
                // no address, nothing that names a message.
                $context->journal->log(
                    MailSandboxService::MODULE_ID,
                    'mail_capture_purged',
                    'info',
                    $deleted . ' message(s) capturé(s) supprimé(s) au-delà de la limite de conservation.',
                    ['count' => $deleted, 'retained' => $sandbox->retentionLimit()]
                );
            }
        } finally {
            // Rescheduled whatever happened, exactly like every other daily
            // task: a purge that threw must not be a purge that stops.
            (new SchedulerService(new SchedulerRepository($context->connection->getPdo())))
                ->rearmAfter(MailSandboxService::MODULE_ID, self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
        }
    }
}
