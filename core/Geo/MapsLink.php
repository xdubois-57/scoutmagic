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
}
