<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

/**
 * How long a drive takes between two points, from OSRM's demonstration
 * server (#703) — the carpool form's suggested departure time.
 *
 * **A new third party, under the conditions it sets.** The demonstration
 * server is offered for reasonable, non-commercial use, at most one request
 * per second, with no guarantee of availability, its data under the ODbL
 * (© OpenStreetMap contributors). So: called from the server only, never
 * from a browser; paced by {@see GeocodingThrottle} under its own lock
 * ({@see GeocodingThrottle::ROUTING_LOCK_NAME}); identified by a
 * User-Agent naming the installation, like {@see GeocodingService} does
 * for Nominatim; and **every failure is an ordinary null**, which the
 * caller turns into its fallback without showing an error. Only two
 * coordinates leave the server — an outing's place and a meeting point
 * the driver typed, never a home address.
 *
 * Its terms are at https://github.com/Project-OSRM/osrm-backend/wiki/Demo-server —
 * registered in {@see \Core\ExternalSource\ExternalSources}, so the weekly
 * check notices if they move — and the call is described on the RGPD page.
 */
class RoutingService
{
    public const ENDPOINT = 'https://router.project-osrm.org/route/v1/driving/';
    private const TIMEOUT = 5;
    private const USER_AGENT_PREFIX = 'ScoutMagic/1.0';

    /** @var \Closure(string, string): ?string */
    private \Closure $fetch;

    /**
     * @param (\Closure(string, string): ?string)|null $fetch url and
     *        User-Agent in, body out — the network in production, a
     *        recording in the tests
     */
    public function __construct(private string $contactUrl = '', ?\Closure $fetch = null)
    {
        $this->fetch = $fetch ?? static function (string $url, string $userAgent): ?string {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: {$userAgent}\r\nAccept: application/json\r\n",
                    'timeout' => self::TIMEOUT,
                    'ignore_errors' => true,
                ],
            ]);
            $body = @file_get_contents($url, false, $context);

            return is_string($body) && $body !== '' ? $body : null;
        };
    }

    /**
     * Seconds by car from $from to $to, or null when the server is
     * unreachable, refuses, or finds no route.
     */
    public function drivingSeconds(GeoPoint $from, GeoPoint $to): ?int
    {
        $url = self::ENDPOINT . self::coordinates($from) . ';' . self::coordinates($to) . '?overview=false';
        $userAgent = self::USER_AGENT_PREFIX . ($this->contactUrl !== '' ? ' (+' . $this->contactUrl . ')' : '');

        $body = ($this->fetch)($url, $userAgent);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded['code'] ?? null) !== 'Ok') {
            return null;
        }
        $duration = $decoded['routes'][0]['duration'] ?? null;

        return is_int($duration) || is_float($duration) ? max(0, (int) round($duration)) : null;
    }

    /** OSRM reads longitude first. */
    private static function coordinates(GeoPoint $point): string
    {
        return number_format($point->longitude, 6, '.', '') . ',' . number_format($point->latitude, 6, '.', '');
    }
}
