<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Task;

use Core\Geo\GeocodingService;
use Core\Geo\GeoPoint;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Covoiturage\Repository\CarpoolRepository;

/**
 * Finds the point of exactly ONE carpool's address per run, then re-arms
 * itself while more are waiting — the camps module's shape, and the same
 * reason: Nominatim allows one request per second and Core\Scheduler has
 * no limiter, so the limit is the shape of the task (Core\Geo\
 * GeocodingService). Seeded by the composition root only when a carpool is
 * actually waiting, never as if it were periodic.
 *
 * The point is optional everywhere: without it the maps link opens the
 * address (Core\Geo\MapsLink). A failed lookup is stamped and erases
 * nothing, and a point a chief placed by hand is never touched — both rules
 * are Core\Geo\GeoPointStore's.
 */
class GeocodeCarpoolsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'geocode_carpools';
    public const REFERENCE = 'covoiturage_geocode';

    /** Comfortably above Nominatim's one-per-second floor. */
    private const SECONDS_BETWEEN_CARPOOLS = 5;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        if ((string) ($context->settings->get('covoiturage_geocoding_enabled', 'covoiturage', '1') ?? '1') !== '1') {
            return;
        }

        $pdo = $context->connection->getPdo();
        $carpools = new CarpoolRepository($pdo);
        $carpool = $carpools->findNextToGeocode();
        if ($carpool === null) {
            return;
        }

        $found = (new GeocodingService((string) ($context->settings->get('base_url') ?? '')))
            ->geocodeLine($carpool->address);
        $carpools->points()->recordGeocoding(
            $carpool->id,
            $found !== null ? new GeoPoint($found['latitude'], $found['longitude']) : null,
            new \DateTimeImmutable()
        );

        // A refusal is stamped and never retried; without this line a
        // carpool that never gets a point would have no explanation
        // anywhere. Its id and nothing else — the address is what was
        // refused, and a journal travels in a support archive.
        if ($found === null) {
            $context->journal->log(
                'covoiturage',
                'carpool_not_geocoded',
                'info',
                sprintf(
                    'Covoiturage #%d : adresse non reconnue par le service de géocodage. '
                    . 'Le lien de carte ouvrira l\'adresse ; un point peut être placé à la main.',
                    $carpool->id
                ),
                ['carpool_id' => $carpool->id]
            );
        }

        if ($carpools->countPendingGeocoding() > 0) {
            SchedulerService::forPdo($pdo)->rearm(
                'covoiturage',
                self::TASK_KEY,
                self::REFERENCE,
                new \DateTimeImmutable('+' . self::SECONDS_BETWEEN_CARPOOLS . ' seconds')
            );
        }
    }
}
