<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Maintenance\GitHubReleaseClientInterface;
use Core\Maintenance\GitHubWebhookService;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Daily fallback release check for the stable channel (auto_update_level
 * patch/minor/major). The GitHub webhook is the primary detection
 * mechanism, but it's a delivery this site doesn't control — a missed or
 * temporarily misconfigured webhook would otherwise leave the stable
 * channel unaware of a new release until an admin manually clicks
 * "Vérifier maintenant". Development mode is deliberately skipped: it
 * relies entirely on the webhook's push event, and there's no meaningful
 * "poll for a new commit on this branch" cadence to reuse here.
 *
 * Self-reschedules for the next day at 01:00 plus a fresh random 0-3600s
 * jitter each time (module spec) — spreads every installation's daily
 * GitHub API call across an hour instead of every site on earth hitting
 * it at exactly the same second. Same self-rescheduling pattern as
 * Task\AutoBackupHandler/Modules\LlmConnector\Task\RefreshModelsHandler,
 * since Core\Scheduler has no first-class recurring-task concept.
 */
class CheckStableUpdateHandler implements TaskHandlerInterface
{
    private const TASK_KEY = 'check_stable_update';
    private const REFERENCE = 'daily';

    public function __construct(private ?GitHubReleaseClientInterface $releaseClient = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $enabled = (bool) ((int) ($context->settings->get('auto_update_enabled') ?: '0'));
        $level = (string) ($context->settings->get('auto_update_level') ?: 'minor');

        if ($enabled && $level !== 'dev') {
            $this->checkForUpdate($context);
        }

        $this->scheduleNext($context);
    }

    private function checkForUpdate(TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $service = new GitHubWebhookService(
            $context->settings,
            new SchedulerService(new SchedulerRepository($pdo)),
            new UpdateHistoryRepository($pdo),
            $context->journal,
            dirname($context->storagePath),
            $this->releaseClient
        );

        try {
            $service->checkForNewRelease();
        } catch (\Throwable $e) {
            $context->journal->log(
                'core', 'auto_update_check_failed', 'info',
                'Échec de la vérification quotidienne des mises à jour', ['error' => $e->getMessage()]
            );
        }
    }

    private function scheduleNext(TaskContext $context): void
    {
        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));

        $existing = $schedulerService->find('core', self::TASK_KEY, self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $schedulerService->rearm('core', self::TASK_KEY, self::REFERENCE, self::nextRunAt(new \DateTimeImmutable()));
    }

    /**
     * Next occurrence of 01:00 + a random 0-3600s jitter, strictly after
     * $now (so a run that happens to fire slightly early/late never
     * schedules a same-day repeat).
     */
    public static function nextRunAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $candidate = $now->setTime(1, 0, 0)->modify('+' . random_int(0, 3600) . ' seconds');
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate;
    }
}
