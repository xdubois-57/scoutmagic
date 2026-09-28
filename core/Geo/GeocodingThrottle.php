<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

use Core\Database\AdvisoryLock;

/**
 * Nominatim's « one request per second », for the whole site — every caller
 * of GeocodingService goes through here: the form lookup
 * (Core\Geo\AddressLocator) and both background tasks
 * (Modules\Covoiturage\Task\GeocodeCarpoolsHandler,
 * Modules\Camps\Task\GeocodePlacesHandler). One limiter, or two of them
 * can land in the same second.
 *
 * The call runs under an advisory lock that is held until a full second
 * has passed since it began, so the next call — from any process — cannot
 * start sooner. Another call arriving meanwhile does not queue behind it
 * (AdvisoryLock's timeout-0 rule): run() says it did not run, and the
 * caller decides — a form answers « not found », a task re-arms itself.
 */
class GeocodingThrottle
{
    public const LOCK_NAME = 'scoutmagic_geocoding';
    private const MIN_INTERVAL_MICROSECONDS = 1_000_000;

    /** @var \Closure(int): void */
    private \Closure $pause;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param (\Closure(int): void)|null $pause microseconds to wait —
     *        usleep() in production, recorded in the tests
     * @param (\Closure(): float)|null $clock seconds, as microtime(true)
     */
    public function __construct(private \PDO $pdo, ?\Closure $pause = null, ?\Closure $clock = null)
    {
        $this->pause = $pause ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * Runs $call alone on the site, and holds the slot a full second.
     *
     * @template T
     * @param callable(): T $call
     * @return array{bool, T|null} [false, null] when another call holds the slot
     */
    public function run(callable $call): array
    {
        if (!AdvisoryLock::acquire($this->pdo, self::LOCK_NAME)) {
            return [false, null];
        }

        $started = ($this->clock)();
        try {
            return [true, $call()];
        } finally {
            $elapsed = (int) ((($this->clock)() - $started) * 1_000_000);
            if ($elapsed < self::MIN_INTERVAL_MICROSECONDS) {
                ($this->pause)(self::MIN_INTERVAL_MICROSECONDS - $elapsed);
            }
            AdvisoryLock::release($this->pdo, self::LOCK_NAME);
        }
    }
}
