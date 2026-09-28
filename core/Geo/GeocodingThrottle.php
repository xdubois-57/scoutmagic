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
 * caller decides — a task re-arms itself.
 *
 * A form cannot re-arm itself, so it has {@see runWaiting()} instead
 * (issue #692): it tries again every tenth of a second until the slot is
 * free — which, against another form lookup, is the end of that call's
 * second — for a bounded time, and stops early the moment it is no longer
 * wanted. Still timeout 0 at every try: a waiting request never blocks
 * inside MySQL, and it gives up on its own clock.
 */
class GeocodingThrottle
{
    public const LOCK_NAME = 'scoutmagic_geocoding';
    private const MIN_INTERVAL_MICROSECONDS = 1_000_000;
    private const RETRY_MICROSECONDS = 100_000;

    /** runWaiting()'s outcomes, besides the call having run. */
    public const RAN = 'ran';
    public const TIMED_OUT = 'timed_out';
    public const NOT_WANTED = 'not_wanted';

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

        return [true, $this->runHolding($call)];
    }

    /**
     * Runs $call alone on the site like run(), but waits for the slot —
     * up to $maxWaitSeconds — instead of giving up at once.
     *
     * $stillWanted is asked before every try: when it answers false (a
     * newer request of the same person has arrived) the wait stops and
     * nothing is sent.
     *
     * @template T
     * @param callable(): T $call
     * @param callable(): bool $stillWanted
     * @return array{string, T|null} [self::RAN, result], or
     *         [self::TIMED_OUT|self::NOT_WANTED, null]
     */
    public function runWaiting(callable $call, float $maxWaitSeconds, callable $stillWanted): array
    {
        $deadline = ($this->clock)() + $maxWaitSeconds;

        while (true) {
            if (!$stillWanted()) {
                return [self::NOT_WANTED, null];
            }
            if (AdvisoryLock::acquire($this->pdo, self::LOCK_NAME)) {
                return [self::RAN, $this->runHolding($call)];
            }
            if (($this->clock)() >= $deadline) {
                return [self::TIMED_OUT, null];
            }
            ($this->pause)(self::RETRY_MICROSECONDS);
        }
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function runHolding(callable $call): mixed
    {
        $started = ($this->clock)();
        try {
            return $call();
        } finally {
            $elapsed = (int) ((($this->clock)() - $started) * 1_000_000);
            if ($elapsed < self::MIN_INTERVAL_MICROSECONDS) {
                ($this->pause)(self::MIN_INTERVAL_MICROSECONDS - $elapsed);
            }
            AdvisoryLock::release($this->pdo, self::LOCK_NAME);
        }
    }
}
