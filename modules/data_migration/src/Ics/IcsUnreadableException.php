<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Ics;

/**
 * A downloaded file that is not an iCalendar at all, or holds no event —
 * the whole source is refused. One event that cannot be read is not this:
 * it becomes a line of its own, counted as « illisible ».
 */
final class IcsUnreadableException extends \RuntimeException
{
}
