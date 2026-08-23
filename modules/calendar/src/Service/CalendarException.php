<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Exception\UserFacingException;

/**
 * A refused calendar operation. Marked {@see UserFacingException}: every
 * message is a French sentence written for the person editing the event or
 * the calendar.
 */
class CalendarException extends \RuntimeException implements UserFacingException
{
}
