<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Task;

use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Service\ReceiptExtractionService;
use Modules\LlmConnector\Api\LlmConnectorInterface;

/**
 * The receipts that never got their AI reading, given it — once each,
 * a few at a time (#1342, decision 9).
 *
 * Until #1342 the scheduled path built its receipt store without the
 * reading (§A), so every receipt that arrived by e-mail was filed with no
 * amount, date or merchant, and nothing ever came back for it. This task
 * offers the reading to the receipts it never ran on: active, with no
 * suggestion of any kind, and no attempt recorded
 * (`finance_attachments.extraction_attempted_at`).
 *
 * **Bounded, idempotent, and the existing scheduler.** {@see self::BATCH_SIZE}
 * an hour, each through the ordinary `extract_receipt_data` task, never
 * queued twice ({@see ReceiptExtractionService::scheduleExtractionUnlessQueued()}),
 * and the marker written by that task the moment it runs — so a receipt is
 * read once, whatever the reading finds.
 *
 * **What it leaves alone.** A receipt a person filled in by hand
 * (`suggested_source = 'manual'`), an archived one, and every receipt
 * while no AI provider answers: then nothing is queued and nothing is
 * marked, so the catch-up resumes the day the connector is back. A receipt
 * filed before the marker existed whose reading ran and found nothing has
 * no way of saying so; it is read one more time, which is the whole cost
 * of not knowing.
 */
class BackfillReceiptReadingsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'backfill_receipt_readings';
    public const REFERENCE = 'receipt-readings';

    /** How many readings one run queues: each is a provider call. */
    public const BATCH_SIZE = 10;

    /** Hourly: nothing waits on it, and the batch bounds what an hour costs. */
    public const INTERVAL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $extraction = new ReceiptExtractionService(
            SchedulerService::forPdo($pdo),
            $context->getOptional(LlmConnectorInterface::class)
        );

        if ($extraction->isAvailable()) {
            $ids = (new AttachmentRepository($pdo, $context->encryption))->findIdsNeverRead(self::BATCH_SIZE);
            foreach ($ids as $attachmentId) {
                $extraction->scheduleExtractionUnlessQueued($attachmentId);
            }

            // Said once per batch, with ids and nothing else: a receipt's
            // filename is personal data (§8.6). An hour that found nothing
            // says nothing.
            if ($ids !== []) {
                $context->journal->log(
                    'finance',
                    'receipt_readings_backfilled',
                    'info',
                    'Lecture IA proposée à des reçus jamais lus',
                    ['attachment_ids' => $ids],
                    null
                );
            }
        }

        // Unconditionally, as every self-rearming chain here: the task is
        // still `pending` while handle() runs (SchedulerRunner marks it
        // done afterwards), so a guard would find it and end the chain.
        SchedulerService::forPdo($pdo)->rearmAfter('finance', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }

    /**
     * Seed the chain — from the shared scheduler bootstrap, so on every
     * request: seed() and not rearm(), see SchedulerService::seed().
     */
    public static function bootstrap(SchedulerService $scheduler): void
    {
        $scheduler->seedAfter('finance', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
