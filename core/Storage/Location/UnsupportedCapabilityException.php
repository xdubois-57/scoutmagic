<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Exception\UserFacingException;

/**
 * Something was asked of a storage backend that this kind of storage
 * cannot do — seeking inside a video on a destination that reads whole
 * files only, resuming an upload on one that restarts them.
 *
 * It exists so that a missing capability is a REFUSAL with a reason rather
 * than a fatal « call to undefined method » three layers down. Marked
 * {@see UserFacingException} because that reason is exactly what the
 * administrator needs: {@see StorageCapabilities::require()} is the only
 * place that builds the message, from the location's own label and the
 * capability's French description, and it names nothing else.
 */
class UnsupportedCapabilityException extends \RuntimeException implements UserFacingException
{
}
