<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo\Task;

use Core\Geo\AddressLocator;
use Core\Geo\GeocodingCacheRepository;
use Core\Geo\GeocodingLookupRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Drops what Core\Geo\AddressLocator no longer needs: quota rows past their
 * window, and cached answers too old to be served.
 *
 * Self-reschedules daily, the shape of
 * Core\Help\Assistant\Task\PurgeHelpAssistantHandler, and is registered once
 * in Core\Scheduler\CoreTaskHandlers so that both entry points know it.
 */
class PurgeGeocodingHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_geocoding';
    public const REFERENCE = 'daily';

    private const INTERVAL_SECONDS = 86400;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $quotaCutoff = (new \DateTimeImmutable('-' . AddressLocator::QUOTA_WINDOW_MINUTES . ' minutes'))
            ->format('Y-m-d H:i:s');
        (new GeocodingLookupRepository($pdo))->deleteOlderThan($quotaCutoff);

        // The longest a cached answer is ever served; « not found » rows
        // expire sooner and are simply ignored until this catches them.
        $cacheCutoff = (new \DateTimeImmutable('-' . AddressLocator::FOUND_TTL_DAYS . ' days'))
            ->format('Y-m-d H:i:s');
        (new GeocodingCacheRepository($pdo))->deleteOlderThan($cacheCutoff);

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
