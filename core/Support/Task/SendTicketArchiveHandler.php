<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Task;

use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsServiceFactory;
use Core\Support\SupportPackageState;
use Core\Support\Ticket\StreamArchiveTransport;
use Core\Support\Ticket\SupportArchiveSender;
use Core\Support\Ticket\TicketIdentityService;

/**
 * Sends a ticket's diagnostic archive once one exists.
 *
 * **Why this is a task and not a line in the controller.** Opening a
 * ticket now transmits the archive without asking a second time, and an
 * installation that has never generated one would otherwise send a ticket
 * with nothing attached — the case « on est sûr de tout recevoir » exists
 * to prevent. Generating takes seconds to minutes and belongs nowhere near
 * a form submission, so the controller schedules the generation and this
 * handler waits for its result.
 *
 * **It waits rather than assumes.** The generation is its own task with
 * its own queue position; this one re-queues itself while no package has
 * appeared, up to a bounded number of attempts. A task that retried for
 * ever would be a task nobody notices has been failing since March.
 *
 * The archive itself is sent by `Ticket\SupportArchiveSender`, unchanged:
 * the consent was given on the form, and this handler carries it rather
 * than inventing one.
 */
class SendTicketArchiveHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'send_ticket_archive';

    /** How long to wait between two looks for a finished package. */
    public const RETRY_SECONDS = 60;

    /**
     * Roughly ten minutes of waiting. A package that has not appeared by
     * then is one whose generation failed, and the ticket says « archive
     * non transmise » with a button to retry by hand — which is a better
     * end than a task still hoping at midnight.
     */
    public const MAX_ATTEMPTS = 10;

    public function handle(array $payload, TaskContext $context): void
    {
        $reference = is_string($payload['reference'] ?? null) ? (string) $payload['reference'] : '';
        $attempt = is_int($payload['attempt'] ?? null) ? (int) $payload['attempt'] : 1;

        if ($reference === '') {
            return;
        }

        $settings = $context->settings;
        $fileId = (int) ($settings->get(SupportPackageState::FILE_ID) ?? '0');

        if ($fileId <= 0) {
            $this->retryOrGiveUp($reference, $attempt, $context);

            return;
        }

        $sender = new SupportArchiveSender(
            $settings,
            new TicketIdentityService(
                $settings,
                new InstallationIdentityService($settings, StatisticsServiceFactory::secretManager($context)),
                $context->journal
            ),
            new \Core\File\EncryptedFileStorageService(
                new FileRepository($context->connection->getPdo()),
                $context->encryption,
                $context->storagePath
            ),
            new StreamArchiveTransport(),
            $context->journal,
            \Core\Maintenance\VersionFile::read(StatisticsServiceFactory::projectRoot($context))
        );

        // The acknowledgement travelled with the form that queued this
        // task; the handler carries it rather than inventing one, and
        // there is no path here that reaches this line without it.
        $result = $sender->send($reference, true);

        if (!$result->sent) {
            $this->retryOrGiveUp($reference, $attempt, $context);
        }
    }

    private function retryOrGiveUp(string $reference, int $attempt, TaskContext $context): void
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            $context->journal->log(
                'core',
                'support_ticket_archive_not_sent',
                'warning',
                "L'archive de diagnostic n'a pas pu être jointe au ticket : elle reste à transmettre à la main.",
                ['ticket_reference' => $reference, 'attempts' => $attempt]
            );

            return;
        }

        (new SchedulerService(new SchedulerRepository($context->connection->getPdo())))->scheduleAfter(
            'core',
            self::TASK_KEY,
            self::RETRY_SECONDS,
            ['reference' => $reference, 'attempt' => $attempt + 1]
        );
    }
}
