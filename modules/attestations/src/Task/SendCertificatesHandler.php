<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Task;

use Core\Config\AppClock;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Import\MemberYearRepository;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberEmailRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Service\IntegerInput;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Repository\MemberNameRepository;
use Modules\Attestations\Service\BatchDistributionService;
use Modules\Attestations\Service\BatchPublicationService;
use Modules\Attestations\Service\CertificateMailer;

/**
 * Sends one slice of a published batch, then re-arms itself while work
 * remains.
 *
 * A slice rather than the whole batch, and the scheduler rather than the
 * request that asked for it: two hundred certificates are two hundred SMTP
 * round trips, and a burst is what ruins a domain's deliverability. Same
 * reason, same shape, as the mass-mail module's own send.
 *
 * **Re-arming happens from inside `handle()`, which is why it works.**
 * `SchedulerService::rearm()` guards on `find()`, which only sees `pending`
 * rows — and `claimOverdue()` flipped this task to `processing` before
 * calling us, so the guard does not find *us* and the successor is really
 * scheduled (ARCHITECTURE.md §8.5).
 */
class SendCertificatesHandler implements TaskHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $batchId = IntegerInput::id($payload['batch_id'] ?? null);
        if ($batchId === null) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $fileRepository = new FileRepository($pdo);
        $fileStorage = new EncryptedFileStorageService(
            $fileRepository,
            $context->encryption,
            $context->storagePath
        );

        $service = new BatchDistributionService(
            new BatchRepository($context->connection),
            new BatchLineRepository($context->connection, $context->encryption),
            new MemberNameRepository($context->connection, $context->encryption),
            new CertificateMailer($context->mailService, $fileStorage, $context->storagePath),
            new MemberAccountResolver(
                new MemberYearRepository($pdo),
                new MemberEmailRepository($pdo, $context->encryption),
                $context->userAccounts,
                $context->encryption
            ),
            $context->journal,
            $context->notifications
        );

        $hasMore = $service->sendSlice(
            $batchId,
            (string) ($context->settings->get('site_name') ?? 'Votre unité')
        );

        if ($hasMore) {
            // Immediately: the queue drains itself through the scheduler's
            // own continuation (§8.5), so "now" means the next slice rather
            // than the next hour.
            SchedulerService::forPdo($pdo)->rearm(
                'attestations',
                BatchPublicationService::TASK_KEY,
                (string) $batchId,
                AppClock::now(),
                // The payload has to travel with the successor: rearm()
                // schedules a fresh row, and one without a batch id is a
                // task that wakes up, finds nothing to do and stops the
                // chain halfway through a send.
                ['batch_id' => $batchId]
            );
        }
    }
}
