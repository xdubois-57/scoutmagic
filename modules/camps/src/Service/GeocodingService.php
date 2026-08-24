<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

/**
 * Turns a place's address into a point, using Nominatim
 * (OpenStreetMap) — free, no key, no account.
 *
 * Same outbound-HTTP approach as Core\Maintenance\GitHubReleaseClient:
 * file_get_contents() over a stream context, no new Composer dependency
 * for one GET.
 *
 * Nominatim's usage policy requires an identifying User-Agent and at most
 * ONE request per second. Core\Scheduler has no rate limiting, so the
 * rate limit is expressed as a shape instead: Task\GeocodePlacesHandler
 * geocodes exactly one place per run and re-schedules itself when more
 * are pending, the same way Core\Maintenance\Task\AutoBackupHandler
 * paces itself. On a site without a real cron this is slow; that is
 * acceptable, because coordinates are a convenience and typing them by
 * hand always works.
 *
 * NEVER called from a web request. An outbound HTTP call on a page load
 * makes the page as slow as the slowest third party, and this one is a
 * free service with no availability promise.
 */
class GeocodingService
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const TIMEOUT = 10;

    /**
     * Nominatim asks callers to identify themselves so an abusive client
     * can be contacted rather than silently blocked. The site's own base
     * URL is the honest identifier — a generic string would name this
     * software, not the installation actually making the requests.
     */
    private const USER_AGENT_PREFIX = 'ScoutMagic-Camps/1.0';

    public function __construct(private string $contactUrl = '')
    {
    }

    /**
     * @return array{latitude: float, longitude: float}|null null when the
     *         address is too thin to search, when Nominatim knows nothing
     *         about it, or when the request fails — all three are the same
     *         outcome for the caller: no point, try again another day.
     */
    public function geocode(?string $address, ?string $postalCode, ?string $city, ?string $country): ?array
    {
        $query = $this->buildQuery($address, $postalCode, $city, $country);
        if ($query === null) {
            return null;
        }

        $url = self::ENDPOINT . '?' . http_build_query([
            'q' => $query,
            'format' => 'jsonv2',
            'limit' => 1,
        ]);

        $body = $this->fetch($url);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
            return null;
        }

        $lat = $decoded[0]['lat'] ?? null;
        $lon = $decoded[0]['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        return ['latitude' => (float) $lat, 'longitude' => (float) $lon];
    }

    /**
     * A place with only a name has nothing to geocode: sending "Le pré de
     * Jules" to a gazetteer returns whatever it feels like, and a
     * confidently wrong pin is worse than no pin. At least a city or a
     * postal code is required.
     */
    private function buildQuery(?string $address, ?string $postalCode, ?string $city, ?string $country): ?string
    {
        $city = $this->clean($city);
        $postalCode = $this->clean($postalCode);
        if ($city === null && $postalCode === null) {
            return null;
        }

        $parts = array_values(array_filter([
            $this->clean($address),
            $postalCode,
            $city,
            $this->clean($country),
        ], static fn(?string $p): bool => $p !== null));

        return implode(', ', $parts);
    }

    private function fetch(string $url): ?string
    {
        $userAgent = self::USER_AGENT_PREFIX
            . ($this->contactUrl !== '' ? ' (+' . $this->contactUrl . ')' : '');

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
    }

    private function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== null && $value !== '' ? $value : null;
    }
}
