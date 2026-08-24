<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

/**
 * The map's tile provider, named once.
 *
 * Three places need to agree about it and they are in three different
 * languages: the Content-Security-Policy built in PHP
 * (Core\Http\Response::addImgSrcOrigin), the tile URL in JavaScript
 * (public/assets/js/camps-map.js), and the subprocessor list in the RGPD
 * page (Core\View\RgpdContentService). A tile host that changes in one
 * and not the others gives a blocked map, or worse, a privacy notice that
 * names the wrong company.
 *
 * This constant is what the PHP side reads, and
 * Tests\Modules\Camps\Service\MapTilesTest is what checks the JavaScript
 * and the RGPD text still say the same thing.
 */
final class MapTiles
{
    /** The origin the CSP must allow for tiles to render at all. */
    public const ORIGIN = 'https://tile.openstreetmap.org';

    /** How the provider is named to a reader, in the RGPD page. */
    public const PROVIDER_NAME = 'OpenStreetMap';

    /** The geocoding endpoint's host — a different service, same project. */
    public const GEOCODER_ORIGIN = 'https://nominatim.openstreetmap.org';
}
