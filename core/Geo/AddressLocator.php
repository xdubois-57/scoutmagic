<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;


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
 * - **One request per second, site-wide** — Nominatim's usage policy —
 *   through Core\Geo\GeocodingThrottle, the limiter the background tasks
 *   share. A lookup arriving while another call holds the slot waits for
 *   it — the end of that call's second — up to MAX_WAIT_SECONDS, trying
 *   again every tenth of a second (GeocodingThrottle::runWaiting(), issue
 *   #692); past that it answers « unavailable » and sends nothing.
 * - **No autocompletion.** The form asks once, when the address field is
 *   left, never per keystroke; and whatever a script does, a request
 *   that has to wait gives way to the same account's newer one (below).
 * - **A cache** (GeocodingCacheRepository): the same address, asked again
 *   by anybody, costs nothing — that includes every re-display of a form.
 * - **A waiting request gives way to a newer one** (GeocodingLookupRepository,
 *   issue #692). A lookup that has to wait for the slot gives up the moment
 *   the same account asks for another address — checked before every try
 *   and once more when the slot is taken. A request that finds the slot
 *   free goes at once: it cannot know about a correction not yet typed, so
 *   a burst sends its first request and its latest, never the ones between.
 *   This replaced a per-account quota: the maintainer decided a quota must
 *   never be why an address is not found.
 *
 * The form says which answer it got (AddressLookup, issue #692): found,
 * not found, unavailable — the slot stayed busy past MAX_WAIT_SECONDS, or
 * the database failed — or superseded by the same person's next request.
 * Whatever it is, the form stays usable and the pin can be placed by hand
 * (and the background task still looks the address up after saving).
 * Only a place is ever sent — an outing's venue, or the meeting point a
 * driver types for the suggested departure (#703), which every member sees
 * and the form says must not be a home — never a person's address; that is
 * the caller's contract, as it is GeocodingService's.
 */
class AddressLocator
{
    /**
     * How long a form lookup waits for the site-wide slot before answering
     * « unavailable » (issue #692). Another form's call frees it within a
     * second; the margin is for a background task holding it.
     */
    public const MAX_WAIT_SECONDS = 3.0;

    /** How long a queued lookup's row is kept — far past any wait. */
    public const LOOKUP_RETENTION_MINUTES = 10;

    /**
     * A place does not move; three months keeps a season of outings. « Not
     * found » is kept for hours only: it may be a network failure as much
     * as an address Nominatim does not know (GeocodingService cannot tell
     * the two apart), and a typo corrected is a new fingerprint anyway.
     */
    public const FOUND_TTL_DAYS = 90;
    public const NOT_FOUND_TTL_HOURS = 6;

    private const MIN_LENGTH = 4;
    private const MAX_LENGTH = 255;

    private GeocodingThrottle $throttle;

    /**
     * @param (\Closure(int): void)|null $pause handed to GeocodingThrottle
     * @param (\Closure(): float)|null $clock handed to GeocodingThrottle
     */
    public function __construct(
        private \PDO $pdo,
        private GeocodingService $geocoder,
        ?\Closure $pause = null,
        ?\Closure $clock = null
    ) {
        $this->throttle = new GeocodingThrottle($pdo, $pause, $clock);
    }

    /**
     * The point for $address, and why there is none when there is none.
     */
    public function locate(string $address, int $userAccountId): AddressLookup
    {
        $line = self::normalise($address);
        if ($line === null) {
            return AddressLookup::notFound();
        }

        try {
            return $this->lookUp($line, $userAccountId);
        } catch (\PDOException $e) {
            // A lock wait, a dropped connection, a lost race on the cache
            // key: the form's lookup is a convenience, and the database is
            // not its caller's concern. error_log() rather than the
            // journal, which is the same database.
            error_log('ScoutMagic address lookup failed: ' . $e->getMessage());

            return AddressLookup::unavailable();
        }
    }

    private function lookUp(string $line, int $userAccountId): AddressLookup
    {
        // Lower-cased, never folded to ASCII: TextNormalizerService::fold()
        // drops every non-Latin letter, and two Greek or Cyrillic addresses
        // would then share one cache key — and one point.
        $fingerprint = hash('sha256', mb_strtolower($line));
        $cache = new GeocodingCacheRepository($this->pdo);
        $known = $cache->find($fingerprint);
        if ($known !== null && self::isFresh($known['point'], $known['looked_up_at'])) {
            return AddressLookup::of($known['point']);
        }

        $lookups = new GeocodingLookupRepository($this->pdo);
        $mine = $lookups->record($userAccountId, new \DateTimeImmutable());

        $lookup = function () use ($cache, $line, $fingerprint): AddressLookup {
            // Asked again once the slot is ours: somebody else may have
            // looked the same line up while this one waited.
            $known = $cache->find($fingerprint);
            if ($known !== null && self::isFresh($known['point'], $known['looked_up_at'])) {
                return AddressLookup::of($known['point']);
            }
            $found = $this->geocoder->geocodeLine($line);
            $point = $found !== null ? new GeoPoint($found['latitude'], $found['longitude']) : null;
            $cache->store($fingerprint, $point, new \DateTimeImmutable());

            return AddressLookup::of($point);
        };
        [$outcome, $result] = $this->throttle->runWaiting(
            $lookup,
            self::MAX_WAIT_SECONDS,
            static fn(): bool => $lookups->latestId($userAccountId) === $mine
        );

        return match ($outcome) {
            GeocodingThrottle::RAN => $result instanceof AddressLookup ? $result : AddressLookup::unavailable(),
            GeocodingThrottle::NOT_WANTED => AddressLookup::superseded(),
            default => AddressLookup::unavailable(),
        };
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
            ? new \DateTimeImmutable('-' . self::FOUND_TTL_DAYS . ' days')
            : new \DateTimeImmutable('-' . self::NOT_FOUND_TTL_HOURS . ' hours');

        return $lookedUpAt >= $limit->format('Y-m-d H:i:s');
    }
}
