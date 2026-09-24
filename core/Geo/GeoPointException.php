<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

use Core\Exception\UserFacingException;

/**
 * Coordinates somebody typed that are not a point. Every message names what
 * was typed and what to do about it, in French, as a whole sentence — the
 * three written in GeoPoint::fromInput() are the only ones.
 */
class GeoPointException extends \RuntimeException implements UserFacingException
{
}
