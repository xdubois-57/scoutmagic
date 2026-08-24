<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\GeocodingService;

/**
 * Geocodes exactly ONE place per run, then re-schedules itself when more
 * are pending.
 *
 * One per run IS the rate limit. Nominatim's usage policy allows at most
 * one request per second and Core\Scheduler offers no throttling, so the
 * limit is expressed as the shape of the task rather than as a sleep
 * inside it — a sleep would hold a web request open, since the scheduler
 * on this site is driven by page loads. Same self-pacing shape as
 * Core\Maintenance\Task\AutoBackupHandler.
 *
 * On an installation without a real cron this drains slowly. That is
 * acceptable and stated in the module's help: coordinates are a
 * convenience, and typing them by hand always works — and always wins,
 * because a manually-set place is never geocoded again.
 */
class GeocodePlacesHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'geocode_places';
    public const REFERENCE = 'camps_geocode_places';

    /** Comfortably above Nominatim's one-per-second floor. */
    private const SECONDS_BETWEEN_PLACES = 5;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        if ((string) ($context->settings->get('camps_geocoding_enabled', 'camps', '1') ?? '1') !== '1') {
            // Switched off: nothing is sent to Nominatim, and the task
            // stops re-arming rather than spinning on a disabled feature.
            return;
        }

        $pdo = $context->connection->getPdo();
        $places = new PlaceRepository($pdo);

        $place = $places->findNextToGeocode();
        if ($place === null) {
            return;
        }

        $point = (new GeocodingService((string) ($context->settings->get('base_url') ?? '')))
            ->geocode($place->address, $place->postalCode, $place->city, $place->country);

        // Stamped either way. A failed lookup is a result: without it,
        // a place whose address means nothing to Nominatim would be
        // retried on every single run, for ever, and would block the
        // queue behind it.
        $places->recordGeocoding(
            $place->id,
            $point['latitude'] ?? null,
            $point['longitude'] ?? null,
            new \DateTimeImmutable()
        );

        if ($places->countPendingGeocoding() > 0) {
            $this->rescheduleSoon($pdo);
        }
    }

    private function rescheduleSoon(\PDO $pdo): void
    {
        $scheduler = new SchedulerService(new SchedulerRepository($pdo));
        if ($scheduler->find('camps', self::TASK_KEY, self::REFERENCE) !== null) {
            return;
        }

        $scheduler->schedule(
            'camps',
            self::TASK_KEY,
            new \DateTimeImmutable('+' . self::SECONDS_BETWEEN_PLACES . ' seconds'),
            [],
            self::REFERENCE
        );
    }
}
