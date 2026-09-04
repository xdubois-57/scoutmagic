<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * Permanently deletes registration requests past their retention period
 * (module spec: two years by default, `registration_purge_retention_years`
 * — counted from `final_at`, never `received_at`, and only for requests in
 * a FINAL state; a request still 'pending'/'accepted' is never touched,
 * regardless of age). Self-reschedules daily rather than being a
 * first-class recurring task (same pattern as Core\Maintenance\Task\
 * AutoBackupHandler), bootstrapped once from public/index.php AND
 * public/cron.php (ARCHITECTURE.md §8.17/§8.20).
 */
class PurgeRegistrationRequestsHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'daily';
    private const INTERVAL_SECONDS = 86400;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $repository = new RegistrationRequestRepository($pdo, $context->encryption);

        $years = (int) ($context->settings->get('registration_purge_retention_years', 'registration') ?: 2);
        $cutoff = (new \DateTimeImmutable())->modify("-{$years} years");

        $ids = $repository->findFinalIdsOlderThan($cutoff);
        foreach ($ids as $id) {
            $repository->deleteById($id);
        }

        if ($ids !== []) {
            $context->journal->log(
                'registration',
                'registration_requests_purged',
                'info',
                'Suppression définitive de demandes d\'inscription finalisées (rétention dépassée)',
                ['count' => count($ids), 'cutoff' => $cutoff->format('Y-m-d')]
            );
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->rearmAfter('registration', 'purge_registration_requests', self::REFERENCE,
            self::INTERVAL_SECONDS);
    }
}
