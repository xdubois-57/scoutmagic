<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

/**
 * A link that opens a place on a map, for a reader to follow.
 *
 * **It prefers the point and falls back on the address**: a link that only
 * worked once geocoding had run would be useless on exactly the outings
 * whose address a gazetteer does not know. OpenStreetMap, the provider
 * the site already names (MapTiles): nothing is sent anywhere until the
 * reader clicks, and then only by their own browser.
 */
final class MapsLink
{
    public static function url(?GeoPoint $point, string $address): string
    {
        if ($point !== null) {
            $lat = number_format($point->latitude, 6, '.', '');
            $lng = number_format($point->longitude, 6, '.', '');

            return 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=17/' . $lat . '/' . $lng;
        }

        return 'https://www.openstreetmap.org/search?query=' . rawurlencode(trim($address));
    }

    /**
     * A link that opens the reader's own map application with a route to
     * the place from where they are (issue #703) — a second question from
     * {@see url()}, which shows a place, so a second method rather than a
     * change to that one.
     *
     * Chosen from the reader's User-Agent, because the right link depends
     * on the device: Apple Plans on an Apple device (`maps.apple.com` is
     * handed to the Plans app there); a `geo:` link on Android, so the
     * phone offers whichever map application is installed; and Google Maps
     * in the browser anywhere else.
     *
     * **That last case is a change of provider, made on purpose.**
     * {@see url()} names OpenStreetMap, the provider the site already uses
     * for its tiles. OpenStreetMap's own route planner needs a starting
     * point typed by hand, which is precisely what « from where I am »
     * avoids, and the request named Google Maps. Nothing leaves the server
     * either way: the destination travels only when the reader clicks, and
     * only from their own browser.
     */
    public static function directions(?GeoPoint $point, string $address, string $userAgent): string
    {
        $destination = $point !== null
            ? number_format($point->latitude, 6, '.', '') . ',' . number_format($point->longitude, 6, '.', '')
            : trim($address);

        return match (self::platform($userAgent)) {
            'apple' => 'https://maps.apple.com/?daddr=' . rawurlencode($destination) . '&dirflg=d',
            'android' => $point !== null
                ? 'geo:' . $destination . '?q=' . rawurlencode($destination)
                : 'geo:0,0?q=' . rawurlencode($destination),
            default => 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($destination),
        };
    }

    /**
     * 'apple', 'android' or 'other'. Android first: its User-Agent never
     * names an Apple device, while some Apple ones say « like Mac OS X ».
     */
    private static function platform(string $userAgent): string
    {
        if (stripos($userAgent, 'Android') !== false) {
            return 'android';
        }
        if (preg_match('/iPhone|iPad|iPod|Macintosh|Mac OS X/i', $userAgent) === 1) {
            return 'apple';
        }

        return 'other';
    }
}
