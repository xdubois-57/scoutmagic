<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Alert\OperationalAlertRepository;
use Core\Alert\OperationalAlertService;
use Core\Maintenance\Health\Security\HostInventory;
use Core\Maintenance\Health\Security\HostSecurityAlertCheck;
use Core\Maintenance\Health\Security\HostSecurityService;
use Core\Maintenance\Health\Security\HostSecurityStore;
use Core\Maintenance\Health\Security\HttpReferenceSource;
use Core\Maintenance\Health\Security\ReferenceSource;
use Core\Maintenance\Health\Security\WebRuntimeFacts;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\System\CronExecutionFacts;

/**
 * The weekly host security check (issue #1358, lot 3): read what the web
 * and the cron measured, consult the reference sources, store the dated
 * snapshot, then run the per-component alerts against it.
 *
 * **It never measures its own process.** It may run in the cron or in the
 * scheduler a visit wakes up, and either way `PHP_VERSION` here is not the
 * other context's; it reads {@see WebRuntimeFacts} and
 * {@see CronExecutionFacts} instead. Only the database server is asked
 * directly, through the connection — that is the server's version, not
 * this process's.
 *
 * **Re-armed whatever happens**, from a `finally`: a source that fails is
 * already absorbed by the service, and anything worse must still leave the
 * chain alive for next week. The next occurrence is a week away when the
 * run was complete, and one minute away when part of the advisories
 * waited for the NVD's rate limit ({@see HostSecurityService::
 * NVD_REQUESTS_PER_RUN}) — the continuation finds everything already
 * fetched in the cache and asks only for the rest. `rearm()` is the
 * guard: a run that overlaps a queued occurrence queues nothing, so
 * visits, a slow run or a retry never make a second chain.
 */
final class CheckHostSecurityHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'host_security_check';
    public const REFERENCE = 'weekly';
    public const CONTINUATION_SECONDS = 60;

    public function __construct(private readonly ?ReferenceSource $source = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $complete = true;

        try {
            $pdo = $context->connection->getPdo();
            $store = new HostSecurityStore($context->settings);
            $store->register();

            $now = time();
            $components = HostInventory::collect(
                WebRuntimeFacts::read($context->settings),
                CronExecutionFacts::read($context->settings),
                self::attribute($pdo, \PDO::ATTR_DRIVER_NAME),
                self::attribute($pdo, \PDO::ATTR_SERVER_VERSION),
                $now
            );

            $snapshot = (new HostSecurityService($store, $this->source ?? new HttpReferenceSource()))
                ->refresh($components, $now);
            $complete = $snapshot->complete;

            (new OperationalAlertService(
                new OperationalAlertRepository($pdo),
                $context->notifications,
                $context->journal
            ))->run(HostSecurityAlertCheck::forSnapshot($snapshot));
        } finally {
            $this->scheduleNext($context, $complete);
        }
    }

    private static function attribute(\PDO $pdo, int $attribute): ?string
    {
        try {
            $value = $pdo->getAttribute($attribute);

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Next week at a jittered night hour — the same courtesy
     * {@see CheckStableUpdateHandler} pays the APIs it calls, so every
     * installation does not ask the NVD in the same second — or in one
     * minute, to finish an incomplete run.
     */
    private function scheduleNext(TaskContext $context, bool $complete): void
    {
        $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));

        if (!$complete) {
            $scheduler->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::CONTINUATION_SECONDS);

            return;
        }

        $next = (new \DateTimeImmutable('+7 days'))
            ->setTime(3, 0)
            ->modify('+' . random_int(0, 7200) . ' seconds');
        $scheduler->rearm('core', self::TASK_KEY, self::REFERENCE, $next);
    }
}
