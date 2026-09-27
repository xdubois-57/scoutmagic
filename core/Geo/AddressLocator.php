<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

use Core\Database\AdvisoryLock;
use Core\Service\TextNormalizerService;

/**
 * An address looked up WHILE a form is being filled in — the one exception
 * to GeocodingService's « never from a web request », made for the carpool
 * organiser's map (issue #642, ARCHITECTURE.md §8.119): the map centres on
 * the address as soon as it is typed, instead of after saving.
 *
 * The exception is narrow by construction, and each fence answers one of
 * the reasons the rule exists:
 *
 * - **The browser never calls Nominatim.** A route of the site does, from
 *   the server, so the CSP keeps `connect-src 'self'` and the visitor's IP
 *   address never reaches a third party.
 * - **One request per second, site-wide** — Nominatim's usage policy. The
 *   call runs under an advisory lock, which is held until a full second has
 *   passed since the call began. A second lookup arriving meanwhile does not
 *   queue behind it (AdvisoryLock's timeout-0 rule): it gets no answer, and
 *   the form behaves as it did before any of this existed.
 * - **No autocompletion.** The form asks once, when the address field is
 *   left, never per keystroke; the per-account quota below is what makes
 *   that true whatever a script does.
 * - **A cache** (GeocodingCacheRepository): the same address, asked again
 *   by anybody, costs nothing — that includes every re-display of a form.
 * - **A per-account quota** (GeocodingLookupRepository), counting only the
 *   requests that actually left for Nominatim.
 *
 * Every refusal and every failure is the same null as « nowhere found »:
 * the caller's form stays usable and the pin is placed by hand, exactly as
 * before (and the background task still looks the address up after saving).
 *
 * Only a place is ever sent — an outing's venue, never a person's address;
 * that is the caller's contract, as it is GeocodingService's.
 */
class AddressLocator
{
    /** Requests one account may send to Nominatim per window. */
    public const QUOTA_PER_WINDOW = 30;
    public const QUOTA_WINDOW_MINUTES = 10;

    /**
     * A place does not move; three months keeps a season of outings. « Not
     * found » is kept for hours only: it may be a network failure as much
     * as an address Nominatim does not know (GeocodingService cannot tell
     * the two apart), and a typo corrected is a new fingerprint anyway.
     */
    public const FOUND_TTL_DAYS = 90;
    public const NOT_FOUND_TTL_HOURS = 6;

    private const LOCK_NAME = 'scoutmagic_live_geocoding';
    private const MIN_INTERVAL_MICROSECONDS = 1_000_000;
    private const MIN_LENGTH = 4;
    private const MAX_LENGTH = 255;

    /** @var \Closure(int): void */
    private \Closure $pause;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param (\Closure(int): void)|null $pause microseconds to wait —
     *        usleep() in production, recorded in the tests
     * @param (\Closure(): float)|null $clock seconds, as microtime(true)
     */
    public function __construct(
        private \PDO $pdo,
        private GeocodingService $geocoder,
        ?\Closure $pause = null,
        ?\Closure $clock = null
    ) {
        $this->pause = $pause ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * The point for $address, or null — not found, too short to mean a
     * place, over quota, or another lookup in flight.
     */
    public function locate(string $address, int $userAccountId): ?GeoPoint
    {
        $line = self::normalise($address);
        if ($line === null) {
            return null;
        }

        $fingerprint = hash('sha256', TextNormalizerService::fold($line));
        $cache = new GeocodingCacheRepository($this->pdo);
        $known = $cache->find($fingerprint);
        if ($known !== null && self::isFresh($known['point'], $known['looked_up_at'])) {
            return $known['point'];
        }

        $lookups = new GeocodingLookupRepository($this->pdo);
        $since = (new \DateTimeImmutable('-' . self::QUOTA_WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
        if ($lookups->countSince($userAccountId, $since) >= self::QUOTA_PER_WINDOW) {
            return null;
        }

        if (!AdvisoryLock::acquire($this->pdo, self::LOCK_NAME)) {
            return null;
        }

        $started = ($this->clock)();
        try {
            $lookups->record($userAccountId, new \DateTimeImmutable());
            $found = $this->geocoder->geocodeLine($line);
            $point = $found !== null ? new GeoPoint($found['latitude'], $found['longitude']) : null;
            $cache->store($fingerprint, $point, new \DateTimeImmutable());

            return $point;
        } finally {
            // Hold the lock until a second has passed since the call began:
            // the next lookup, from any process, cannot start sooner.
            $elapsed = (int) ((($this->clock)() - $started) * 1_000_000);
            if ($elapsed < self::MIN_INTERVAL_MICROSECONDS) {
                ($this->pause)(self::MIN_INTERVAL_MICROSECONDS - $elapsed);
            }
            AdvisoryLock::release($this->pdo, self::LOCK_NAME);
        }
    }

    /** The line as it will be sent, or null when it cannot mean a place. */
    private static function normalise(string $address): ?string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $address));
        if (mb_strlen($line) < self::MIN_LENGTH || mb_strlen($line) > self::MAX_LENGTH) {
            return null;
        }

        return $line;
    }

    private static function isFresh(?GeoPoint $point, string $lookedUpAt): bool
    {
        $limit = $point !== null
            ? '-' . self::FOUND_TTL_DAYS . ' days'
            : '-' . self::NOT_FOUND_TTL_HOURS . ' hours';

        return $lookedUpAt >= (new \DateTimeImmutable($limit))->format('Y-m-d H:i:s');
    }
}
