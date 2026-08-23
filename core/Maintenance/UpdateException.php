<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Exception\UserFacingException;

/**
 * Something went wrong checking for, downloading, or installing an update.
 *
 * Every message is French, whole, and names only things the admin who
 * pressed the button can act on: a GitHub status code, a refused
 * redirect, an invalid archive, or the file the install stopped on —
 * relative to the install root, never absolute (Task\InstallUpdateHandler
 * ::writeFailureMessage()).
 *
 * It was deliberately NOT user-facing until that was true. Two throw
 * sites used to interpolate an absolute server path and whatever
 * `error_get_last()` happened to hold, so the whole class was substituted
 * away behind a generic fallback — which meant the admin of a site
 * halfway through a failed update was told « la mise à jour a échoué »
 * and nothing else, with no file to go and look at. Naming the file is
 * the point of the message; the hosting account above it was the reason
 * it could not be said.
 */
class UpdateException extends \RuntimeException implements UserFacingException
{
}
